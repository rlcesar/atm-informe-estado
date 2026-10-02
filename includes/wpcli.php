<?php
/**
 * Registro del comando de WP-CLI `wp atm-informe` (mostrar, enviar, estado).
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

if ( defined( 'WP_CLI' ) && WP_CLI ) {
	require_once ATM_INF_DIR . 'includes/class-atm-inf-comando.php';

	WP_CLI::add_command( 'atm-informe', 'Atm_Inf_Comando' );
}
