<?php
/**
 * Plugin Name:       ATM Sanse — Informe de estado (cargador)
 * Plugin URI:        https://motormediapress.com
 * Description:       WordPress solo carga los archivos PHP sueltos de mu-plugins/, no sus subcarpetas: este archivo existe únicamente para cargar el plugin de verdad, que vive en mu-plugins/atm-informe-estado/. Va copiado en mu-plugins/ (ver CLAUDE.md del repo).
 * Version:           1.0.0
 * Author:            César Rodríguez López
 * Author URI:        https://motormediapress.com
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

$atm_inf_principal = __DIR__ . '/atm-informe-estado/atm-informe-estado.php';

if ( is_readable( $atm_inf_principal ) ) {
	require_once $atm_inf_principal;
}

unset( $atm_inf_principal );
