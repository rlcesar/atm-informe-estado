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

const ATM_INF_HOOK_CRON     = 'atm_inf_informe_semanal';
const ATM_INF_OPCION_ENVIO  = 'atm_inf_ultimo_envio';

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
	$zona   = new DateTimeZone( 'Europe/Madrid' );
	$ahora  = ( new DateTimeImmutable( '@' . ( $ahora ?? time() ) ) )->setTimezone( $zona );
	$lunes  = $ahora->modify( 'monday this week' )->setTime( 7, 30 );

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
		if ( $programado ) {
			wp_clear_scheduled_hook( ATM_INF_HOOK_CRON );
		}
		return;
	}

	if ( ! $programado ) {
		wp_schedule_single_event( atm_inf_proximo_lunes(), ATM_INF_HOOK_CRON );
	}
}
add_action( 'init', 'atm_inf_asegurar_programacion', 20 );

/**
 * Manejador del evento semanal: reprograma el siguiente y envía el informe.
 *
 * Reprograma primero para que un fallo al construir o enviar el informe no
 * rompa la cadena. Si ya hubo un envío en los últimos 3 días no repite
 * (por ejemplo, si el cron dispara dos veces).
 */
function atm_inf_ejecutar_informe_programado(): void {
	wp_schedule_single_event( atm_inf_proximo_lunes(), ATM_INF_HOOK_CRON );

	if ( ! atm_inf_es_produccion() ) {
		return;
	}

	$ultimo = (int) get_option( ATM_INF_OPCION_ENVIO, 0 );

	if ( $ultimo && ( time() - $ultimo ) < 3 * DAY_IN_SECONDS ) {
		return;
	}

	atm_inf_enviar_informe();
}
add_action( ATM_INF_HOOK_CRON, 'atm_inf_ejecutar_informe_programado' );
