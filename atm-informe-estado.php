<?php
/**
 * Plugin Name:       ATM Sanse — Informe de estado
 * Plugin URI:        https://motormediapress.com
 * Description:       mu-plugin autónomo: cada lunes a las 7:30 (hora de Madrid) envía por correo a webadmin@atmsanse.com un informe del estado de la web (servidor, actualizaciones pendientes, problemas, seguridad y actividad de la Zona de Alumnos en cifras agregadas). Solo lee: no expone ningún endpoint ni modifica nada del sitio.
 * Version:           1.0.0
 * Requires at least: 6.5
 * Requires PHP:      8.0
 * Author:            César Rodríguez López
 * Author URI:        https://motormediapress.com
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/*
 * Prefijo `atm_inf_` (Informe de estado): el tema y los demás plugins del
 * proyecto corren en el mismo proceso PHP y ya usan `atm_`, `atm_za_` y
 * `atm_ck_`. No se usa __() porque el informe es interno y solo en castellano.
 */

define( 'ATM_INF_VERSION', '1.0.0' );
define( 'ATM_INF_DIR', __DIR__ . '/' );

/* ==========================================================================
   CARGA DE ARCHIVOS — cada uno depende solo de los anteriores. Las funciones
   de `informe.php` (atm_inf_seccion, atm_inf_anadir_aviso…) las usan todos los
   recolectores, pero solo al ejecutarse, no al cargarse.
   ========================================================================== */

require_once ATM_INF_DIR . 'includes/contadores.php';
require_once ATM_INF_DIR . 'includes/programacion.php';
require_once ATM_INF_DIR . 'includes/recolector-estado.php';
require_once ATM_INF_DIR . 'includes/recolector-actualizaciones.php';
require_once ATM_INF_DIR . 'includes/recolector-problemas.php';
require_once ATM_INF_DIR . 'includes/recolector-seguridad.php';
require_once ATM_INF_DIR . 'includes/recolector-actividad.php';
require_once ATM_INF_DIR . 'includes/informe.php';
require_once ATM_INF_DIR . 'includes/plantilla-correo.php';
require_once ATM_INF_DIR . 'includes/envio.php';
require_once ATM_INF_DIR . 'includes/wpcli.php';
