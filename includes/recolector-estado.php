<?php
/**
 * Recolector «Estado del servidor»: versiones, memoria, disco, cron y ajustes
 * del sitio que, mal puestos en producción, causan problemas (indexación,
 * depuración visible, HTTPS).
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Recorre `uploads/` una sola vez por ejecución: ficheros, tamaño y ficheros PHP.
 *
 * Lo usan «Estado» (tamaño) y «Seguridad» (PHP en uploads). Tiene un límite de
 * 30 s para no colgar el cron si la carpeta es enorme; en ese caso `completo` es false.
 *
 * @return array `ficheros` (int), `bytes` (int), `php` (rutas relativas de ficheros ejecutables) y `completo` (bool).
 */
function atm_inf_escanear_uploads(): array {
	static $cache = null;

	if ( null !== $cache ) {
		return $cache;
	}

	$resultado = array(
		'ficheros' => 0,
		'bytes'    => 0,
		'php'      => array(),
		'completo' => true,
	);

	$subidas = wp_upload_dir( null, false );
	$base    = (string) ( $subidas['basedir'] ?? '' );

	if ( '' === $base || ! is_dir( $base ) ) {
		$cache = $resultado;
		return $cache;
	}

	$limite = microtime( true ) + 30;

	try {
		$iterador = new RecursiveIteratorIterator(
			new RecursiveDirectoryIterator( $base, FilesystemIterator::SKIP_DOTS ),
			RecursiveIteratorIterator::LEAVES_ONLY,
			RecursiveIteratorIterator::CATCH_GET_CHILD
		);

		foreach ( $iterador as $fichero ) {
			if ( ! $fichero->isFile() ) {
				continue;
			}

			$tamano = (int) $fichero->getSize();
			$nombre = strtolower( $fichero->getFilename() );

			++$resultado['ficheros'];
			$resultado['bytes'] += $tamano;

			// Los `index.php` diminutos («Silence is golden») son normales.
			$es_ejecutable = (bool) preg_match( '/\.(php\d?|phtml|pht|phar)$/', $nombre );

			if ( $es_ejecutable && ! ( 'index.php' === $nombre && $tamano < 100 ) ) {
				$resultado['php'][] = ltrim( substr( $fichero->getPathname(), strlen( $base ) ), '/\\' );
			}

			if ( microtime( true ) > $limite ) {
				$resultado['completo'] = false;
				break;
			}
		}
	} catch ( \Throwable $e ) {
		$resultado['completo'] = false;
	}

	$cache = $resultado;

	return $cache;
}

/**
 * Sección «Estado del servidor».
 *
 * @return array Sección del informe (ver `atm_inf_seccion()`).
 */
function atm_inf_recolectar_estado(): array {
	global $wpdb;

	require_once ABSPATH . 'wp-admin/includes/misc.php';

	$s = atm_inf_seccion( 'Estado del servidor' );

	$s['filas'][] = array( 'WordPress', get_bloginfo( 'version' ) . ' (entorno declarado: ' . wp_get_environment_type() . ')' );
	$s['filas'][] = array( 'PHP', PHP_VERSION . ' (' . PHP_SAPI . ')' );
	$motor_bd     = false !== stripos( (string) $wpdb->db_server_info(), 'mariadb' ) ? 'MariaDB ' : 'MySQL ';
	$memoria_php  = ini_get( 'memory_limit' );
	$s['filas'][] = array( 'Base de datos', $motor_bd . $wpdb->db_version() );
	$s['filas'][] = array( 'Memoria', WP_MEMORY_LIMIT . ' (límite de WordPress) · ' . ( '-1' === $memoria_php ? 'sin límite' : $memoria_php ) . ' (PHP)' );

	// Soporte de la versión de PHP, según la propia API de WordPress.org.
	$php = wp_check_php_version();

	if ( is_array( $php ) ) {
		if ( empty( $php['is_secure'] ) ) {
			atm_inf_anadir_aviso( $s, 'atencion', 'PHP ' . PHP_VERSION . ' ya no recibe parches de seguridad (recomendada: ' . ( $php['recommended_version'] ?? '?' ) . ').' );
		} elseif ( empty( $php['is_supported'] ) ) {
			$s['notas'][] = 'PHP ' . PHP_VERSION . ' solo recibe parches de seguridad (soporte activo terminado); conviene planificar el salto a ' . ( $php['recommended_version'] ?? 'la versión recomendada' ) . '.';
		}
	}

	// Disco.
	$libre = @disk_free_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged -- puede estar desactivada en el hosting.
	$total = @disk_total_space( ABSPATH ); // phpcs:ignore WordPress.PHP.NoSilencedErrors.Discouraged

	if ( $libre && $total ) {
		$pct_libre    = $libre / $total * 100;
		$s['filas'][] = array( 'Disco (volumen del servidor, orientativo)', size_format( $libre ) . ' libres de ' . size_format( $total ) . ' (' . round( $pct_libre ) . ' % libre)' );
		$s['notas'][] = 'El disco es el del volumen del servidor, no la cuota del plan; la cuota real de SiteGround se ve en Site Tools.';

		if ( $pct_libre < 10 ) {
			atm_inf_anadir_aviso( $s, 'atencion', 'Queda menos del 10 % de disco libre en el volumen del servidor (dato orientativo).' );
		}
	} else {
		$s['notas'][] = 'El hosting no permite consultar el espacio en disco desde PHP.';
	}

	$uploads      = atm_inf_escanear_uploads();
	$s['filas'][] = array( 'Carpeta uploads', number_format_i18n( $uploads['ficheros'] ) . ' ficheros · ' . size_format( $uploads['bytes'] ) . ( $uploads['completo'] ? '' : ' (recuento parcial: la carpeta es muy grande)' ) );

	// Ajustes del sitio.
	if ( ! str_starts_with( home_url(), 'https://' ) ) {
		atm_inf_anadir_aviso( $s, 'atencion', 'La dirección del sitio no usa HTTPS.' );
	}

	if ( '0' === (string) get_option( 'blog_public' ) ) {
		atm_inf_anadir_aviso( $s, 'critico', 'Ajustes → Lectura pide a los buscadores que no indexen el sitio.' );
	}

	if ( defined( 'WP_DEBUG' ) && WP_DEBUG ) {
		$muestra = ! defined( 'WP_DEBUG_DISPLAY' ) || WP_DEBUG_DISPLAY;
		atm_inf_anadir_aviso( $s, 'atencion', 'WP_DEBUG está activo' . ( $muestra ? ' y puede mostrar errores a los visitantes' : '' ) . '.' );
	}

	// WP-Cron.
	$cron      = _get_cron_array();
	$atrasadas = 0;
	$mas_vieja = 0;

	if ( is_array( $cron ) ) {
		foreach ( $cron as $marca => $ganchos ) {
			if ( is_int( $marca ) && $marca < time() - HOUR_IN_SECONDS ) {
				$atrasadas += count( (array) $ganchos );
				$mas_vieja  = $mas_vieja ? min( $mas_vieja, $marca ) : $marca;
			}
		}
	}

	$s['filas'][] = array( 'Tareas de WP-Cron atrasadas (más de 1 h)', $atrasadas . ( $mas_vieja ? ' (la más antigua, de hace ' . human_time_diff( $mas_vieja ) . ')' : '' ) );

	if ( $mas_vieja && $mas_vieja < time() - DAY_IN_SECONDS ) {
		atm_inf_anadir_aviso( $s, 'atencion', 'WP-Cron lleva más de 24 h con tareas sin ejecutar: sin visitas ni cron del sistema, el informe y las copias programadas no salen a su hora.' );
	}

	if ( defined( 'DISABLE_WP_CRON' ) && DISABLE_WP_CRON ) {
		$s['notas'][] = 'WP-Cron por visitas está desactivado: debe existir un cron del sistema que lo lance.';
	}

	$proximo = wp_next_scheduled( ATM_INF_HOOK_CRON );

	$s['filas'][] = array( 'Próximo informe', $proximo ? wp_date( 'D j M Y H:i', $proximo ) : 'sin programar (solo se programa en el dominio de producción)' );

	return $s;
}
