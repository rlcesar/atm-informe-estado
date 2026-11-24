<?php
/**
 * Contadores diarios agregados (inicios de sesión, descargas, fallos).
 *
 * WordPress no guarda histórico de accesos ni de descargas, así que este
 * archivo cuenta desde que el mu-plugin está activo. Solo se guardan cifras
 * por día: ningún usuario, correo ni IP. Los «usuarios distintos» se calculan
 * con una lista de huellas que incluye el día en el hash (no enlazan un día
 * con otro) y que se descarta al terminar el día, dejando solo el recuento.
 *
 * Almacén: opción `atm_inf_contadores` (sin autoload), con esta forma:
 * `{ 'AAAA-MM-DD': { login_zona, login_otros, login_fallidos, descargas,
 * correos_fallidos, distintos_zona | _hashes[] } }`. Retención: 90 días.
 * Dos peticiones simultáneas pueden pisarse y perder una cuenta; es asumible
 * para estadística.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

const ATM_INF_OPCION_CONTADORES = 'atm_inf_contadores';
const ATM_INF_OPCION_DESDE      = 'atm_inf_contadores_desde';
const ATM_INF_DIAS_RETENCION    = 90;

/**
 * Lee todos los contadores guardados.
 *
 * @return array Contadores por día (ver DocBlock del archivo).
 */
function atm_inf_leer_contadores(): array {
	$datos = get_option( ATM_INF_OPCION_CONTADORES, array() );

	return is_array( $datos ) ? $datos : array();
}

/**
 * Guarda los contadores: compacta los días ya cerrados y poda los antiguos.
 *
 * @param array  $datos Contadores por día.
 * @param string $hoy   Clave del día actual (AAAA-MM-DD, hora del sitio).
 */
function atm_inf_guardar_contadores( array $datos, string $hoy ): void {
	$limite = wp_date( 'Y-m-d', time() - ATM_INF_DIAS_RETENCION * DAY_IN_SECONDS );

	foreach ( $datos as $dia => $valores ) {
		if ( (string) $dia < $limite ) {
			unset( $datos[ $dia ] );
			continue;
		}

		if ( (string) $dia !== $hoy && isset( $valores['_hashes'] ) ) {
			$datos[ $dia ]['distintos_zona'] = count( (array) $valores['_hashes'] );
			unset( $datos[ $dia ]['_hashes'] );
		}
	}

	ksort( $datos );
	update_option( ATM_INF_OPCION_CONTADORES, $datos, false );
}

/**
 * Suma 1 a un contador del día de hoy.
 *
 * @param string   $clave   Nombre del contador (login_zona, descargas…).
 * @param int|null $user_id Si se indica, cuenta además al usuario como «distinto» de hoy.
 */
function atm_inf_registrar_evento( string $clave, ?int $user_id = null ): void {
	atm_inf_asegurar_inicio_contadores();

	$datos = atm_inf_leer_contadores();
	$hoy   = wp_date( 'Y-m-d' );

	if ( ! isset( $datos[ $hoy ] ) || ! is_array( $datos[ $hoy ] ) ) {
		$datos[ $hoy ] = array();
	}

	$datos[ $hoy ][ $clave ] = (int) ( $datos[ $hoy ][ $clave ] ?? 0 ) + 1;

	if ( null !== $user_id ) {
		$huella = substr( hash_hmac( 'sha256', $user_id . '|' . $hoy, wp_salt( 'auth' ) ), 0, 12 );

		if ( ! isset( $datos[ $hoy ]['_hashes'] ) || ! is_array( $datos[ $hoy ]['_hashes'] ) ) {
			$datos[ $hoy ]['_hashes'] = array();
		}

		if ( ! in_array( $huella, $datos[ $hoy ]['_hashes'], true ) ) {
			$datos[ $hoy ]['_hashes'][] = $huella;
		}
	}

	atm_inf_guardar_contadores( $datos, $hoy );
}

/**
 * Suma los contadores de una ventana de días.
 *
 * @param int $dias          Días que abarca la ventana.
 * @param int $desplazamiento Días hacia atrás desde hoy donde termina la ventana (0 = hasta hoy).
 * @return array Totales: login_zona, login_otros, login_fallidos, descargas,
 *               correos_fallidos y distintos_media (usuarios distintos por día).
 */
function atm_inf_totales_ventana( int $dias, int $desplazamiento = 0 ): array {
	$datos     = atm_inf_leer_contadores();
	$totales   = array(
		'login_zona'       => 0,
		'login_otros'      => 0,
		'login_fallidos'   => 0,
		'descargas'        => 0,
		'correos_fallidos' => 0,
	);
	$distintos = 0;

	for ( $i = $desplazamiento; $i < $desplazamiento + $dias; $i++ ) {
		$dia = wp_date( 'Y-m-d', time() - $i * DAY_IN_SECONDS );

		if ( ! isset( $datos[ $dia ] ) || ! is_array( $datos[ $dia ] ) ) {
			continue;
		}

		foreach ( array_keys( $totales ) as $clave ) {
			$totales[ $clave ] += (int) ( $datos[ $dia ][ $clave ] ?? 0 );
		}

		$distintos += isset( $datos[ $dia ]['distintos_zona'] )
			? (int) $datos[ $dia ]['distintos_zona']
			: count( (array) ( $datos[ $dia ]['_hashes'] ?? array() ) );
	}

	$totales['distintos_media'] = $dias > 0 ? round( $distintos / $dias, 1 ) : 0;

	return $totales;
}

/**
 * Anota el día en que empezaron a contarse los datos (una sola vez).
 *
 * Se llama desde `atm_inf_registrar_evento()`, no en cada petición.
 */
function atm_inf_asegurar_inicio_contadores(): void {
	if ( false === get_option( ATM_INF_OPCION_DESDE ) ) {
		add_option( ATM_INF_OPCION_DESDE, wp_date( 'Y-m-d' ), '', false );
	}
}

/**
 * Cuenta un inicio de sesión correcto, separando Zona de Alumnos del resto.
 *
 * Sin tipos en los parámetros a propósito: otro plugin podría disparar
 * `wp_login` con algo que no sea un `WP_User` y no debe romper el login.
 *
 * @param mixed $user_login Nombre de usuario (no se guarda).
 * @param mixed $user       Usuario que ha entrado (se ignora si no es `WP_User`).
 */
function atm_inf_hook_login( $user_login, $user ): void {
	if ( ! $user instanceof WP_User ) {
		return;
	}

	foreach ( (array) $user->roles as $rol ) {
		if ( str_starts_with( (string) $rol, 'atm_za_' ) ) {
			atm_inf_registrar_evento( 'login_zona', (int) $user->ID );
			return;
		}
	}

	atm_inf_registrar_evento( 'login_otros' );
}
add_action( 'wp_login', 'atm_inf_hook_login', 10, 2 );

/**
 * Cuenta un intento de inicio de sesión fallido.
 */
function atm_inf_hook_login_fallido(): void {
	atm_inf_registrar_evento( 'login_fallidos' );
}
add_action( 'wp_login_failed', 'atm_inf_hook_login_fallido' );

/**
 * Cuenta un correo que WordPress no ha podido enviar.
 */
function atm_inf_hook_correo_fallido(): void {
	atm_inf_registrar_evento( 'correos_fallidos' );
}
add_action( 'wp_mail_failed', 'atm_inf_hook_correo_fallido' );

/**
 * Cuenta una petición de descarga de la Zona de Alumnos hecha con sesión iniciada.
 *
 * Se engancha con prioridad 1, antes del manejador del plugin, sin tocarlo. Cuenta
 * peticiones, no descargas efectivas: una petición rechazada por permisos también suma.
 */
function atm_inf_hook_descarga(): void {
	if ( is_user_logged_in() ) {
		atm_inf_registrar_evento( 'descargas' );
	}
}
add_action( 'admin_post_atm_za_descargar_archivo', 'atm_inf_hook_descarga', 1 );
