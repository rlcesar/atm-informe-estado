<?php
/**
 * Programación del envío semanal.
 *
 * Un mu-plugin no tiene gancho de activación, así que en cada `init` se
 * comprueba que haya un evento de WP-Cron programado y, si falta, se crea.
 * Es un evento único que se reprograma a sí mismo (en vez de uno recurrente)
 * para fijar siempre las 7:30 de Madrid aunque cambie el horario de verano.
 *
 * Solo se programa en producción. Si alguien importa la base de datos de
 * producción en Local, el evento llegaría con ella: fuera de producción se
 * borra para que un entorno de pruebas nunca mande el informe real.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ATM_INF_HOOK_CRON         = 'atm_inf_informe_semanal';
const ATM_INF_HOOK_REINTENTO    = 'atm_inf_informe_reintento';
const ATM_INF_OPCION_ENVIO      = 'atm_inf_ultimo_envio';
const ATM_INF_OPCION_CANDADO    = 'atm_inf_enviando';
const ATM_INF_OPCION_REINTENTOS = 'atm_inf_reintentos';
const ATM_INF_MAX_REINTENTOS    = 3;

/**
 * Indica si el sitio está en el dominio de producción.
 *
 * Se mira el dominio y no `wp_get_environment_type()`: en SiteGround esa
 * constante no está definida y vale `production` en cualquier sitio.
 *
 * @return bool
 */
function atm_inf_es_produccion(): bool {
	$dominio = (string) apply_filters( 'atm_inf_dominio_produccion', 'atmsanse.com' );
	$host    = (string) wp_parse_url( home_url(), PHP_URL_HOST );

	return in_array( $host, array( $dominio, 'www.' . $dominio ), true );
}

/**
 * Calcula el próximo lunes a las 7:30 hora de Madrid.
 *
 * @param int|null $ahora Marca de tiempo de referencia (por defecto, ahora). Útil para pruebas.
 * @return int Marca de tiempo UNIX (UTC).
 */
function atm_inf_proximo_lunes( ?int $ahora = null ): int {
	$zona  = new DateTimeZone( 'Europe/Madrid' );
	$ahora = ( new DateTimeImmutable( '@' . ( $ahora ?? time() ) ) )->setTimezone( $zona );
	$lunes = $ahora->modify( 'monday this week' )->setTime( 7, 30 );

	if ( $lunes <= $ahora ) {
		$lunes = $lunes->modify( '+1 week' );
	}

	return $lunes->getTimestamp();
}

/**
 * Deja programado (o desprogramado) el evento semanal según el entorno.
 */
function atm_inf_asegurar_programacion(): void {
	$programado = wp_next_scheduled( ATM_INF_HOOK_CRON );

	if ( ! atm_inf_es_produccion() ) {
		wp_clear_scheduled_hook( ATM_INF_HOOK_CRON );
		wp_clear_scheduled_hook( ATM_INF_HOOK_REINTENTO );
		return;
	}

	if ( ! $programado ) {
		wp_schedule_single_event( atm_inf_proximo_lunes(), ATM_INF_HOOK_CRON );
	}
}
add_action( 'init', 'atm_inf_asegurar_programacion', 20 );

/**
 * Intenta insertar la fila del candado (`INSERT IGNORE`, atómico).
 *
 * @param int $ahora Marca de tiempo que se guarda como valor.
 * @return int Filas afectadas: 1 si se creó, 0 si ya existía.
 */
function atm_inf_insertar_candado( int $ahora ): int {
	global $wpdb;

	return (int) $wpdb->query(
		$wpdb->prepare(
			"INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')",
			ATM_INF_OPCION_CANDADO,
			(string) $ahora
		)
	);
}

/**
 * Toma el candado que evita que dos procesos envíen el informe a la vez.
 *
 * `add_option()` no es atómico, así que se usa `INSERT IGNORE` y se comprueba
 * cuántas filas afecta (como `WP_Upgrader::create_lock()`). Un candado de más
 * de 15 minutos se considera huérfano (fatal a mitad de envío) y se sustituye
 * con un `UPDATE` condicionado al valor leído, de modo que solo uno lo gana.
 *
 * @return bool True si se obtuvo el candado.
 */
function atm_inf_tomar_candado(): bool {
	global $wpdb;

	$ahora = time();

	$filas = atm_inf_insertar_candado( $ahora );

	if ( ! $filas ) {
		// Ya existía: se lee de la base de datos, sin pasar por la caché.
		$valor = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT option_value FROM {$wpdb->options} WHERE option_name = %s",
				ATM_INF_OPCION_CANDADO
			)
		);

		if ( null !== $valor && ( $ahora - (int) $valor ) < 15 * MINUTE_IN_SECONDS ) {
			return false;
		}

		if ( null === $valor ) {
			// Lo soltaron entre medias: se vuelve a intentar una vez.
			$filas = atm_inf_insertar_candado( $ahora );
		} else {
			$filas = $wpdb->query(
				$wpdb->prepare(
					"UPDATE {$wpdb->options} SET option_value = %s WHERE option_name = %s AND option_value = %s",
					(string) $ahora,
					ATM_INF_OPCION_CANDADO,
					$valor
				)
			);
		}

		if ( 1 !== (int) $filas ) {
			return false;
		}
	}

	wp_cache_delete( 'notoptions', 'options' );
	wp_cache_delete( ATM_INF_OPCION_CANDADO, 'options' );

	return true;
}

/**
 * Suelta el candado de envío.
 */
function atm_inf_soltar_candado(): void {
	delete_option( ATM_INF_OPCION_CANDADO );
	wp_cache_delete( ATM_INF_OPCION_CANDADO, 'options' );
}

/**
 * Flujo común del envío programado (lunes y reintentos).
 *
 * Orden: comprobar producción, reprogramar el lunes (solo si lo dispara el
 * evento semanal), tomar el candado, programar el reintento ANTES de construir
 * (un fatal o un timeout no pierde la semana) y enviar. Si el envío sale bien se
 * cancelan el reintento y el contador. No repite si ya hubo envío en 3 días.
 *
 * @param bool $es_lunes True si lo dispara el evento semanal; false si es un reintento.
 */
function atm_inf_procesar_envio( bool $es_lunes ): void {
	if ( ! atm_inf_es_produccion() ) {
		// Fuera de producción no se programa nada (la BD importada trae los eventos).
		wp_clear_scheduled_hook( ATM_INF_HOOK_CRON );
		wp_clear_scheduled_hook( ATM_INF_HOOK_REINTENTO );
		return;
	}

	if ( $es_lunes ) {
		wp_schedule_single_event( atm_inf_proximo_lunes(), ATM_INF_HOOK_CRON );
	}

	if ( ! atm_inf_tomar_candado() ) {
		return;
	}

	try {
		// Otro proceso pudo actualizarla justo antes de que tomáramos el candado.
		wp_cache_delete( ATM_INF_OPCION_ENVIO, 'options' );
		wp_cache_delete( 'alloptions', 'options' );
		$ultimo = (int) get_option( ATM_INF_OPCION_ENVIO, 0 );

		if ( $ultimo && ( time() - $ultimo ) < 3 * DAY_IN_SECONDS ) {
			wp_clear_scheduled_hook( ATM_INF_HOOK_REINTENTO );
			delete_option( ATM_INF_OPCION_REINTENTOS );
			return;
		}

		if ( $es_lunes ) {
			// Semana nueva: el contador de reintentos empieza de cero.
			delete_option( ATM_INF_OPCION_REINTENTOS );
		}

		$intentos = (int) get_option( ATM_INF_OPCION_REINTENTOS, 0 );
		$maximo   = (int) apply_filters( 'atm_inf_max_reintentos', ATM_INF_MAX_REINTENTOS );

		wp_clear_scheduled_hook( ATM_INF_HOOK_REINTENTO );
		if ( $intentos < $maximo ) {
			wp_schedule_single_event( time() + HOUR_IN_SECONDS, ATM_INF_HOOK_REINTENTO );
			update_option( ATM_INF_OPCION_REINTENTOS, $intentos + 1, false );
		}

		if ( atm_inf_enviar_informe() ) {
			// Solo el cron fija el último envío; el manual (wp atm-informe enviar) no cuenta.
			// Se escribe antes de soltar el candado (finally).
			update_option( ATM_INF_OPCION_ENVIO, time(), false );
			wp_clear_scheduled_hook( ATM_INF_HOOK_REINTENTO );
			delete_option( ATM_INF_OPCION_REINTENTOS );
		}
	} finally {
		atm_inf_soltar_candado();
	}
}

/**
 * Manejador del evento semanal (lunes 7:30).
 */
function atm_inf_ejecutar_informe_programado(): void {
	atm_inf_procesar_envio( true );
}
add_action( ATM_INF_HOOK_CRON, 'atm_inf_ejecutar_informe_programado' );

/**
 * Manejador del reintento tras un envío fallido (no reprograma el lunes).
 */
function atm_inf_ejecutar_informe_reintento(): void {
	atm_inf_procesar_envio( false );
}
add_action( ATM_INF_HOOK_REINTENTO, 'atm_inf_ejecutar_informe_reintento' );
