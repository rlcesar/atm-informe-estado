<?php
/**
 * Comando de WP-CLI `wp atm-informe`: probar el informe sin esperar al lunes.
 *
 * Seguridad: fuera del dominio de producción, `enviar` exige indicar un
 * correo con `--a=`, para que un entorno de pruebas nunca escriba por defecto
 * al destinatario real. Solo se carga desde `wpcli.php` cuando corre WP-CLI.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Informe semanal de estado de la web.
 */
class Atm_Inf_Comando {

	/**
	 * Construye el informe y lo muestra en pantalla, sin enviarlo.
	 *
	 * ## OPTIONS
	 *
	 * [--html]
	 * : Muestra el HTML del correo en vez del texto plano.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atm-informe mostrar
	 *
	 * @param array $args       Argumentos posicionales (no se usan).
	 * @param array $assoc_args Opciones.
	 */
	public function mostrar( $args, $assoc_args ) {
		$informe = atm_inf_construir_informe();

		WP_CLI::line( isset( $assoc_args['html'] ) ? atm_inf_renderizar_html( $informe ) : atm_inf_renderizar_texto( $informe ) );
	}

	/**
	 * Construye el informe y lo envía por correo.
	 *
	 * Es un envío manual: no cuenta como el de la semana (no actualiza
	 * «Último envío») y no anula el del lunes.
	 *
	 * ## OPTIONS
	 *
	 * [--a=<correo>]
	 * : Destinatario. Obligatorio fuera de producción.
	 *
	 * ## EXAMPLES
	 *
	 *     wp atm-informe enviar --a=yo@ejemplo.com
	 *
	 * @param array $args       Argumentos posicionales (no se usan).
	 * @param array $assoc_args Opciones.
	 */
	public function enviar( $args, $assoc_args ) {
		$destino = isset( $assoc_args['a'] ) ? sanitize_email( (string) $assoc_args['a'] ) : '';

		if ( isset( $assoc_args['a'] ) && ! is_email( $destino ) ) {
			WP_CLI::error( 'El correo indicado en --a no es válido.' );
		}

		if ( '' === $destino && ! atm_inf_es_produccion() ) {
			WP_CLI::error( 'Fuera de producción hay que indicar el destinatario con --a=<correo>.' );
		}

		if ( atm_inf_enviar_informe( '' === $destino ? null : $destino ) ) {
			WP_CLI::success( 'Informe enviado a ' . ( '' === $destino ? atm_inf_destinatario() : $destino ) . '.' );
		} else {
			WP_CLI::error( 'wp_mail() no ha podido enviar el informe.' );
		}
	}

	/**
	 * Muestra la programación y desde cuándo se cuentan los accesos.
	 *
	 * @param array $args       Argumentos posicionales (no se usan).
	 * @param array $assoc_args Opciones (no se usan).
	 */
	public function estado( $args, $assoc_args ) {
		$proximo = wp_next_scheduled( ATM_INF_HOOK_CRON );
		$ultimo  = (int) get_option( ATM_INF_OPCION_ENVIO, 0 );

		WP_CLI::line( 'Producción (dominio): ' . ( atm_inf_es_produccion() ? 'sí' : 'no — no se programa ni se envía solo' ) );
		WP_CLI::line( 'Próximo envío programado: ' . ( $proximo ? wp_date( 'Y-m-d H:i T', $proximo ) : 'ninguno' ) );
		$reintento = wp_next_scheduled( ATM_INF_HOOK_REINTENTO );
		WP_CLI::line( 'Reintento pendiente: ' . ( $reintento ? wp_date( 'Y-m-d H:i T', $reintento ) . ' (intentos: ' . (int) get_option( ATM_INF_OPCION_REINTENTOS, 0 ) . ')' : 'ninguno' ) );
		WP_CLI::line( 'Último envío: ' . ( $ultimo ? wp_date( 'Y-m-d H:i T', $ultimo ) : 'nunca' ) );
		WP_CLI::line( 'Contadores de accesos desde: ' . ( '' !== (string) get_option( ATM_INF_OPCION_DESDE ) ? get_option( ATM_INF_OPCION_DESDE ) : 'sin iniciar' ) );
		WP_CLI::line( 'Destinatario: ' . atm_inf_destinatario() );
	}
}
