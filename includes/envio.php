<?php
/**
 * Envío del informe por correo.
 *
 * Usa `wp_mail()`, así que el remitente (no-responder@dominio) lo pone el
 * tema en `includes/remitente-correo.php`; aquí no se fija ningún `From`.
 * Las cuentas y credenciales de correo son las del propio hosting: este
 * plugin no guarda ninguna.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Destinatario del informe.
 *
 * @return string
 */
function atm_inf_destinatario(): string {
	return (string) apply_filters( 'atm_inf_destinatario', 'webadmin@atmsanse.com' );
}

/**
 * Construye el informe y lo envía por correo (HTML con alternativa en texto plano).
 * No registra el envío: `atm_inf_ultimo_envio` la escribe solo el cron en `includes/programacion.php`.
 *
 * @param string|null $destinatario Correo al que enviarlo; por defecto, el destinatario fijo.
 * @return bool true si `wp_mail()` ha aceptado el envío.
 */
function atm_inf_enviar_informe( ?string $destinatario = null ): bool {
	// Hay llamadas HTTP a wordpress.org y recorridos de disco: puede tardar.
	if ( function_exists( 'set_time_limit' ) ) {
		@set_time_limit( 180 ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- puede estar desactivada en el hosting.
	}

	$destinatario = '' !== (string) $destinatario ? $destinatario : atm_inf_destinatario();
	$informe      = atm_inf_construir_informe();
	$texto        = atm_inf_renderizar_texto( $informe );

	$alternativa = static function ( $phpmailer ) use ( $texto ): void {
		$phpmailer->AltBody = $texto; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propiedad de PHPMailer; no la nombra este código.
	};

	add_action( 'phpmailer_init', $alternativa );
	$enviado = wp_mail(
		$destinatario,
		atm_inf_asunto( $informe ),
		atm_inf_renderizar_html( $informe ),
		array( 'Content-Type: text/html; charset=UTF-8' )
	);
	remove_action( 'phpmailer_init', $alternativa );

	if ( ! $enviado ) {
		error_log( 'ATM Informe de estado: wp_mail() no ha podido enviar el informe.' ); // phpcs:ignore WordPress.PHP.DevelopmentFunctions.error_log_error_log -- no hay otro canal si falla el correo.
	}

	return $enviado;
}
