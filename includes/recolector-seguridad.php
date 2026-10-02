<?php
/**
 * Recolector «Seguridad»: integridad de los archivos del núcleo, ficheros PHP
 * en uploads, número de administradores e intentos de acceso fallidos.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Compara los archivos del núcleo con los checksums oficiales de wordpress.org.
 *
 * Se ignora `wp-content/` (plugins y temas se verifican de otra forma) y los
 * archivos que los hostings suelen borrar a propósito (readme, licencia).
 *
 * @return array|null `modificados` y `ausentes` (rutas relativas) y `completo` (bool);
 *                    null si no se han podido descargar los checksums.
 */
function atm_inf_verificar_nucleo(): ?array {
	require_once ABSPATH . 'wp-admin/includes/update.php';

	global $wp_version;

	$sumas = get_core_checksums( $wp_version, get_locale() );

	if ( ! is_array( $sumas ) ) {
		$sumas = get_core_checksums( $wp_version, 'en_US' );
	}

	if ( ! is_array( $sumas ) ) {
		return null;
	}

	$ignorar   = array( 'readme.html', 'license.txt', 'wp-config-sample.php' );
	$resultado = array(
		'modificados' => array(),
		'ausentes'    => array(),
		'completo'    => true,
	);
	$limite    = microtime( true ) + 60;

	foreach ( $sumas as $fichero => $suma ) {
		if ( str_starts_with( $fichero, 'wp-content/' ) || in_array( $fichero, $ignorar, true ) ) {
			continue;
		}

		$ruta = ABSPATH . $fichero;

		if ( ! is_file( $ruta ) ) {
			$resultado['ausentes'][] = $fichero;
		} elseif ( md5_file( $ruta ) !== $suma ) {
			$resultado['modificados'][] = $fichero;
		}

		if ( microtime( true ) > $limite ) {
			$resultado['completo'] = false;
			break;
		}
	}

	return $resultado;
}

/**
 * Sección «Seguridad».
 *
 * @return array Sección del informe (ver `atm_inf_seccion()`).
 */
function atm_inf_recolectar_seguridad(): array {
	$s = atm_inf_seccion( 'Seguridad' );

	// Integridad del núcleo.
	$nucleo = atm_inf_verificar_nucleo();

	if ( null === $nucleo ) {
		$s['notas'][] = 'No se han podido descargar los checksums oficiales de WordPress.org: la integridad del núcleo no se ha verificado esta semana.';
	} else {
		$s['filas'][] = array(
			'Integridad del núcleo de WordPress',
			count( $nucleo['modificados'] ) . ' archivos modificados · ' . count( $nucleo['ausentes'] ) . ' ausentes' . ( $nucleo['completo'] ? '' : ' (comprobación incompleta por tiempo)' ),
		);

		if ( $nucleo['modificados'] ) {
			atm_inf_anadir_aviso( $s, 'critico', 'Archivos del núcleo de WordPress alterados (posible intrusión): ' . implode( ', ', array_slice( $nucleo['modificados'], 0, 8 ) ) . ( count( $nucleo['modificados'] ) > 8 ? '…' : '' ) . '.' );
		}

		if ( $nucleo['ausentes'] ) {
			atm_inf_anadir_aviso( $s, 'atencion', 'Faltan archivos del núcleo de WordPress: ' . implode( ', ', array_slice( $nucleo['ausentes'], 0, 8 ) ) . ( count( $nucleo['ausentes'] ) > 8 ? '…' : '' ) . '.' );
		}
	}

	// Ficheros ejecutables dentro de uploads.
	$uploads = atm_inf_escanear_uploads();

	$s['filas'][] = array( 'Ficheros PHP dentro de uploads', (string) count( $uploads['php'] ) );

	if ( $uploads['php'] ) {
		atm_inf_anadir_aviso( $s, 'critico', 'Hay ficheros ejecutables (PHP) en uploads, donde solo debería haber medios: ' . implode( ', ', array_slice( $uploads['php'], 0, 5 ) ) . ( count( $uploads['php'] ) > 5 ? '…' : '' ) . '.' );
	}

	// Cuentas con privilegios.
	$cuentas         = atm_inf_contar_usuarios();
	$administradores = (int) ( $cuentas['avail_roles']['administrator'] ?? 0 );

	$s['filas'][] = array( 'Cuentas de administrador', (string) $administradores );

	if ( $administradores > 3 ) {
		$s['notas'][] = 'Hay más de 3 administradores: conviene dejar solo los imprescindibles.';
	}

	$s['filas'][] = array( 'Editor de archivos del admin', defined( 'DISALLOW_FILE_EDIT' ) && DISALLOW_FILE_EDIT ? 'desactivado (correcto)' : 'activo (se recomienda desactivarlo con DISALLOW_FILE_EDIT)' );

	// Intentos de acceso fallidos.
	$totales = atm_inf_totales_ventana( 7 );
	$umbral  = (int) apply_filters( 'atm_inf_umbral_login_fallidos', 300 );

	$s['filas'][] = array( 'Intentos de inicio de sesión fallidos (7 días)', (string) $totales['login_fallidos'] );

	if ( $totales['login_fallidos'] > $umbral ) {
		atm_inf_anadir_aviso( $s, 'atencion', $totales['login_fallidos'] . ' intentos de inicio de sesión fallidos en 7 días (umbral ' . $umbral . '): posible ataque de fuerza bruta.' );
	}

	return $s;
}
