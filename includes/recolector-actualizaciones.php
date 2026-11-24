<?php
/**
 * Recolector «Actualizaciones y configuración»: núcleo, plugins y temas con
 * actualización pendiente (con resumen del changelog de los plugins de
 * wordpress.org) y el inventario de lo instalado.
 *
 * Lee lo que WordPress ya sabe (transients `update_*`) tras pedirle que
 * refresque. Los plugins que no vienen de wordpress.org (los `atm-*`, los
 * premium sin licencia) no aparecen aquí salvo que su actualizador los ofrezca.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Resume el changelog de un plugin de wordpress.org para la versión nueva.
 *
 * @param string $slug  Slug del plugin en wordpress.org.
 * @param string $nueva Versión nueva.
 * @return array|null `texto` (primeros ~450 caracteres a partir de la versión) y
 *                    `seguridad` (bool: el texto menciona seguridad); null si no hay datos.
 */
function atm_inf_resumen_changelog( string $slug, string $nueva ): ?array {
	if ( ! function_exists( 'plugins_api' ) ) {
		require_once ABSPATH . 'wp-admin/includes/plugin-install.php';
	}

	$info = plugins_api(
		'plugin_information',
		array(
			'slug'   => $slug,
			'fields' => array(
				'sections'          => true,
				'short_description' => false,
				'description'       => false,
				'banners'           => false,
				'icons'             => false,
				'reviews'           => false,
				'ratings'           => false,
				'contributors'      => false,
				'versions'          => false,
				'compatibility'     => false,
				'tags'              => false,
				'donate_link'       => false,
			),
		)
	);

	if ( is_wp_error( $info ) || empty( $info->sections['changelog'] ) ) {
		return null;
	}

	$html  = preg_replace( '#</(li|p|h[1-6]|ul)>|<br\s*/?>#i', "\n", (string) $info->sections['changelog'] );
	$texto = html_entity_decode( wp_strip_all_tags( (string) $html ), ENT_QUOTES, 'UTF-8' );
	$texto = trim( (string) preg_replace( '/[ \t]+/', ' ', (string) preg_replace( '/\s*\n\s*/', ' · ', trim( $texto ) ) ) );

	if ( '' === $texto ) {
		return null;
	}

	$pos = '' !== $nueva ? strpos( $texto, $nueva ) : false;
	$ini = false === $pos ? 0 : $pos;

	return array(
		'texto'     => mb_substr( substr( $texto, $ini ), 0, 450 ),
		'seguridad' => (bool) preg_match( '/security|seguridad|vulnerab|\bXSS\b|\bCSRF\b|injection|\bCVE-\d|exploit|privilege/i', substr( $texto, $ini, 1200 ) ),
	);
}

/**
 * Sección «Actualizaciones y configuración».
 *
 * @return array Sección del informe (ver `atm_inf_seccion()`).
 */
function atm_inf_recolectar_actualizaciones(): array {
	require_once ABSPATH . 'wp-admin/includes/plugin.php';
	require_once ABSPATH . 'wp-admin/includes/update.php';

	$s = atm_inf_seccion( 'Actualizaciones y configuración' );

	// Pide a WordPress que refresque lo que sabe (respeta su propia caché de 12 h).
	wp_version_check();
	wp_update_plugins();
	wp_update_themes();

	$version_wp = get_bloginfo( 'version' );
	$pendientes = 0;

	// Núcleo.
	$nucleo = get_core_updates();

	if ( is_array( $nucleo ) ) {
		foreach ( $nucleo as $actualizacion ) {
			if ( ! isset( $actualizacion->response ) || 'upgrade' !== $actualizacion->response ) {
				continue;
			}

			$nueva = (string) ( $actualizacion->current ?? $actualizacion->version ?? '?' );
			$menor = array_slice( explode( '.', $version_wp ), 0, 2 ) === array_slice( explode( '.', $nueva ), 0, 2 );

			++$pendientes;
			$s['filas'][] = array( 'WordPress', $version_wp . ' → ' . $nueva . ( $menor ? ' (versión de mantenimiento, suele incluir correcciones de seguridad)' : ' (versión mayor)' ) );
			atm_inf_anadir_aviso( $s, 'atencion', 'Actualización de WordPress pendiente: ' . $version_wp . ' → ' . $nueva . ( $menor ? ' (mantenimiento, posible seguridad)' : '' ) . '.' );
			break;
		}
	}

	// Plugins.
	$plugins_pend = get_plugin_updates();
	$auto         = (array) get_site_option( 'auto_update_plugins', array() );
	$filas        = array();
	$consultas    = 0;
	$seguridad    = array();

	foreach ( $plugins_pend as $archivo => $plugin ) {
		$u     = $plugin->update;
		$nueva = (string) ( $u->new_version ?? '?' );
		$notas = array();

		++$pendientes;

		$de_repositorio = isset( $u->url ) && str_contains( (string) $u->url, 'wordpress.org' ) && ! empty( $u->slug );

		if ( $de_repositorio && $consultas < 15 ) {
			++$consultas;
			$resumen = atm_inf_resumen_changelog( (string) $u->slug, $nueva );

			if ( $resumen ) {
				$notas[] = $resumen['texto'];

				if ( $resumen['seguridad'] ) {
					$notas[]     = 'MENCIONA SEGURIDAD';
					$seguridad[] = $plugin->Name; // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propiedad del objeto de actualizaciones de WordPress; no la nombra este código.
				}
			}
		} elseif ( ! $de_repositorio ) {
			$notas[] = 'No es de wordpress.org: sin changelog disponible aquí.';
		}

		if ( empty( $u->package ) ) {
			$notas[] = 'Sin paquete de descarga (licencia caducada o actualización manual).';
		}

		if ( ! empty( $u->tested ) && array_slice( explode( '.', (string) $u->tested ), 0, 2 ) !== array_slice( explode( '.', $version_wp ), 0, 2 ) && version_compare( (string) $u->tested, $version_wp, '<' ) ) {
			$notas[] = 'Probado solo hasta WordPress ' . $u->tested . '.';
		}

		if ( ! empty( $u->requires_php ) && version_compare( PHP_VERSION, (string) $u->requires_php, '<' ) ) {
			$notas[] = 'Requiere PHP ' . $u->requires_php . ' (este servidor tiene ' . PHP_VERSION . ').';
		}

		if ( in_array( $archivo, $auto, true ) ) {
			$notas[] = 'Se actualizará sola (autoactualización activada).';
		}

		$filas[] = array( $plugin->Name, $plugin->Version . ' → ' . $nueva, implode( ' ', $notas ) ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propiedad del objeto de actualizaciones de WordPress; no la nombra este código.
	}

	if ( $filas ) {
		$s['tablas'][] = array(
			'titulo'   => 'Plugins con actualización pendiente',
			'cabecera' => array( 'Plugin', 'Versión', 'Notas / resumen del cambio' ),
			'filas'    => $filas,
		);
		atm_inf_anadir_aviso( $s, 'atencion', count( $filas ) . ' plugin(s) con actualización pendiente.' );
	}

	foreach ( $seguridad as $nombre ) {
		atm_inf_anadir_aviso( $s, 'critico', 'El changelog de la actualización de «' . $nombre . '» menciona seguridad.' );
	}

	// Temas.
	$filas_temas = array();

	foreach ( get_theme_updates() as $tema ) {
		++$pendientes;
		$filas_temas[] = array( $tema->get( 'Name' ), $tema->get( 'Version' ) . ' → ' . ( $tema->update['new_version'] ?? '?' ) );
	}

	if ( $filas_temas ) {
		$s['tablas'][] = array(
			'titulo'   => 'Temas con actualización pendiente',
			'cabecera' => array( 'Tema', 'Versión' ),
			'filas'    => $filas_temas,
		);
		atm_inf_anadir_aviso( $s, 'atencion', count( $filas_temas ) . ' tema(s) con actualización pendiente.' );
	}

	if ( ! $pendientes ) {
		$s['filas'][] = array( 'Actualizaciones pendientes', 'ninguna (núcleo, plugins y temas al día)' );
	}

	$s['notas'][] = 'Los plugins que no se distribuyen por wordpress.org (los atm-* propios y los premium sin licencia activa) no aparecen como pendientes: se actualizan por Git o con su licencia.';

	// Configuración: tema y plugins instalados.
	$tema         = wp_get_theme();
	$s['filas'][] = array( 'Tema activo', $tema->get( 'Name' ) . ' ' . $tema->get( 'Version' ) . ( $tema->parent() ? ' (hijo de ' . $tema->parent()->get( 'Name' ) . ')' : '' ) );

	$todos   = get_plugins();
	$activos = (array) get_option( 'active_plugins', array() );
	$tabla   = array();

	foreach ( $activos as $archivo ) {
		if ( isset( $todos[ $archivo ] ) ) {
			$tabla[] = array( $todos[ $archivo ]['Name'], $todos[ $archivo ]['Version'], 'plugin' );
		}
	}

	foreach ( get_mu_plugins() as $datos ) {
		$tabla[] = array( $datos['Name'], '' !== (string) $datos['Version'] ? $datos['Version'] : '—', 'mu-plugin' );
	}

	$inactivos    = count( $todos ) - count( array_intersect( $activos, array_keys( $todos ) ) );
	$s['filas'][] = array( 'Plugins', count( $activos ) . ' activos, ' . $inactivos . ' inactivos' . ( $inactivos ? ' (conviene eliminar los que no se usen)' : '' ) );

	if ( $tabla ) {
		$s['tablas'][] = array(
			'titulo'   => 'Plugins activos y mu-plugins',
			'cabecera' => array( 'Nombre', 'Versión', 'Tipo' ),
			'filas'    => $tabla,
		);
	}

	return $s;
}
