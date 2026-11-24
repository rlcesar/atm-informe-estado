<?php
/**
 * Recolector «Problemas detectados»: errores PHP de los últimos 7 días,
 * modo de recuperación, correos fallidos y Site Health de WordPress.
 *
 * Los mensajes de error se acortan, se les quita la ruta del servidor y
 * cualquier correo, y se agrupan por texto: el informe nunca lleva datos
 * personales aunque un error los contenga.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Localiza el registro de errores de PHP, si lo hay y es legible.
 *
 * @return string|null Ruta del fichero.
 */
function atm_inf_ruta_log_errores(): ?string {
	$candidatos = array_filter(
		array(
			(string) ini_get( 'error_log' ),
			WP_CONTENT_DIR . '/debug.log',
			ABSPATH . 'error_log',
		)
	);

	foreach ( $candidatos as $ruta ) {
		if ( is_file( $ruta ) && is_readable( $ruta ) ) {
			return $ruta;
		}
	}

	return null;
}

/**
 * Analiza el final del registro de errores de PHP (última ~1 MiB).
 *
 * @param string $ruta Ruta del registro.
 * @return array `tamano` (bytes), `fatales`, `avisos`, `otros` (conteos de los últimos 7 días)
 *               y `top` (hasta 5 mensajes más repetidos: `[veces, nivel, mensaje]`).
 */
function atm_inf_analizar_log_errores( string $ruta ): array {
	$tamano = (int) filesize( $ruta );
	$leer   = 1048576;
	$cola   = '';
	$gestor = fopen( $ruta, 'rb' );

	if ( $gestor ) {
		if ( $tamano > $leer ) {
			fseek( $gestor, -$leer, SEEK_END );
		}

		$cola = (string) stream_get_contents( $gestor );
		fclose( $gestor );
	}

	$desde   = time() - 7 * DAY_IN_SECONDS;
	$niveles = array(
		'fatales' => 0,
		'avisos'  => 0,
		'otros'   => 0,
	);
	$grupos  = array();

	foreach ( preg_split( '/\R/', $cola ) as $linea ) {
		if ( ! preg_match( '/^\[(\d{2}-[A-Za-z]{3}-\d{4} \d{2}:\d{2}:\d{2})[^\]]*\] PHP (Fatal error|Parse error|Catchable fatal error|Warning|Notice|Deprecated|Strict Standards): +(.*)$/', $linea, $m ) ) {
			continue;
		}

		$fecha = DateTimeImmutable::createFromFormat( 'd-M-Y H:i:s', $m[1], new DateTimeZone( 'UTC' ) );

		if ( ! $fecha || $fecha->getTimestamp() < $desde ) {
			continue;
		}

		if ( false !== stripos( $m[2], 'fatal' ) || 'Parse error' === $m[2] ) {
			++$niveles['fatales'];
		} elseif ( 'Warning' === $m[2] ) {
			++$niveles['avisos'];
		} else {
			++$niveles['otros'];
		}

		$mensaje = mb_substr( trim( atm_inf_sanear_mensaje_log( $m[3] ) ), 0, 170 );
		$clave   = $m[2] . '|' . $mensaje;

		if ( ! isset( $grupos[ $clave ] ) ) {
			$grupos[ $clave ] = array( 0, $m[2], $mensaje );
		}

		++$grupos[ $clave ][0];
	}

	usort(
		$grupos,
		static function ( array $a, array $b ): int {
			return $b[0] <=> $a[0];
		}
	);

	return $niveles + array(
		'tamano' => $tamano,
		'top'    => array_slice( $grupos, 0, 5 ),
	);
}

/**
 * Quita de un mensaje del log lo que no debe salir en el informe.
 *
 * Sustituye `ABSPATH`, correos, IPs (v4 y v6) y rutas absolutas (Unix y Windows),
 * conservando el nombre del archivo final. No trunca: eso lo hace quien llama.
 *
 * @param string $mensaje Mensaje tal cual está en el log.
 * @return string Mensaje saneado.
 */
function atm_inf_sanear_mensaje_log( string $mensaje ): string {
	if ( defined( 'ABSPATH' ) && '' !== ABSPATH ) {
		$mensaje = str_replace( ABSPATH, '…/', $mensaje );
	}

	$mensaje = (string) preg_replace( '/[\w.+-]+@[\w-]+(\.[\w-]+)+/', '[correo]', $mensaje );

	// IPv6: candidatas con al menos dos ":" validadas, para no comerse horas ni "Clase::metodo".
	$mensaje = (string) preg_replace_callback(
		'/(?<![\w:.])[0-9A-Fa-f:.]*:[0-9A-Fa-f:.]*:[0-9A-Fa-f:.]*(?![\w:])/',
		static function ( array $c ): string {
			$candidata = rtrim( $c[0], '.' );

			if ( false === filter_var( $candidata, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6 ) ) {
				return $c[0];
			}

			return '[ip]' . substr( $c[0], strlen( $candidata ) );
		},
		$mensaje
	);

	// IPv4: cuatro grupos validados (octetos 0-255).
	$mensaje = (string) preg_replace_callback(
		'/(?<![\w.])\d{1,3}(?:\.\d{1,3}){3}(?!\d|\.\d)/',
		static function ( array $c ): string {
			return false === filter_var( $c[0], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 ) ? $c[0] : '[ip]';
		},
		$mensaje
	);

	// Rutas absolutas restantes: Unix (con al menos un directorio) y Windows.
	$mensaje = (string) preg_replace( '#(?<![\w.\-:/…])/(?:[^\s/\'":,;()<>]+/)+([^\s/\'":,;()<>]+)#u', '…/$1', $mensaje );

	return (string) preg_replace( '#(?<!\w)[A-Za-z]:\\\\+(?:[^\s\\\\\'"<>|]+\\\\+)*([^\s\\\\\'":<>|]+)#u', '…/$1', $mensaje );
}

/**
 * Comprueba si `wp-content/debug.log` se puede descargar por URL.
 *
 * @return bool true si responde 200 (el registro es público: grave).
 */
function atm_inf_debug_log_es_publico(): bool {
	if ( ! is_file( WP_CONTENT_DIR . '/debug.log' ) ) {
		return false;
	}

	$respuesta = wp_remote_head(
		content_url( 'debug.log' ),
		array(
			'timeout'     => 8,
			'redirection' => 0,
		)
	);

	return ! is_wp_error( $respuesta ) && 200 === (int) wp_remote_retrieve_response_code( $respuesta );
}

/**
 * Ejecuta los tests directos de Site Health (los mismos que la pantalla Salud del sitio).
 *
 * Se omiten los que ya cubre este informe (versiones de WP, PHP, plugins, temas y depuración)
 * para no repetir avisos. Cada test va en su `try`: uno que falle no tumba los demás.
 *
 * @return array|null `criticos` y `recomendados` (listas de títulos) y `buenos` (int); null si no está disponible.
 */
function atm_inf_ejecutar_site_health(): ?array {
	if ( ! class_exists( 'WP_Site_Health' ) ) {
		return null;
	}

	// Igual que hace el cron del propio Site Health: sus tests usan funciones del admin.
	require_once ABSPATH . 'wp-admin/includes/admin.php';

	$instancia = WP_Site_Health::get_instance();
	$tests     = WP_Site_Health::get_tests();
	$omitir    = array( 'wordpress_version', 'plugin_version', 'theme_version', 'php_version', 'debug_enabled' );
	$salida    = array(
		'criticos'     => array(),
		'recomendados' => array(),
		'buenos'       => 0,
	);

	foreach ( (array) ( $tests['direct'] ?? array() ) as $test ) {
		if ( ! empty( $test['skip_cron'] ) || ( is_string( $test['test'] ) && in_array( $test['test'], $omitir, true ) ) ) {
			continue;
		}

		try {
			$funcion = null;

			if ( is_string( $test['test'] ) && method_exists( $instancia, 'get_test_' . $test['test'] ) ) {
				$funcion = array( $instancia, 'get_test_' . $test['test'] );
			} elseif ( is_callable( $test['test'] ) ) {
				$funcion = $test['test'];
			}

			if ( ! $funcion ) {
				continue;
			}

			$resultado = call_user_func( $funcion );
			$estado    = (string) ( $resultado['status'] ?? '' );
			$etiqueta  = wp_strip_all_tags( (string) ( $resultado['label'] ?? '' ) );

			if ( 'critical' === $estado ) {
				$salida['criticos'][] = $etiqueta;
			} elseif ( 'recommended' === $estado ) {
				$salida['recomendados'][] = $etiqueta;
			} else {
				++$salida['buenos'];
			}
		} catch ( \Throwable $e ) {
			continue;
		}
	}

	return $salida;
}

/**
 * Sección «Problemas detectados».
 *
 * @return array Sección del informe (ver `atm_inf_seccion()`).
 */
function atm_inf_recolectar_problemas(): array {
	$s = atm_inf_seccion( 'Problemas detectados' );

	// Registro de errores de PHP.
	$ruta = atm_inf_ruta_log_errores();

	if ( $ruta ) {
		$log = atm_inf_analizar_log_errores( $ruta );

		$s['filas'][] = array( 'Errores PHP (7 días)', $log['fatales'] . ' fatales · ' . $log['avisos'] . ' avisos (warnings) · ' . $log['otros'] . ' otros (notices/deprecated)' );
		$s['filas'][] = array( 'Registro de errores', size_format( $log['tamano'] ) . ' (' . ( str_starts_with( $ruta, ABSPATH ) ? '…/' . substr( $ruta, strlen( ABSPATH ) ) : basename( $ruta ) ) . ')' );

		if ( $log['fatales'] > 0 ) {
			atm_inf_anadir_aviso( $s, 'atencion', $log['fatales'] . ' error(es) fatal(es) de PHP en los últimos 7 días.' );
		}

		if ( $log['tamano'] > 50 * MB_IN_BYTES ) {
			$s['notas'][] = 'El registro de errores supera los 50 MB: conviene rotarlo o vaciarlo.';
		}

		if ( $log['top'] ) {
			$s['tablas'][] = array(
				'titulo'   => 'Errores más repetidos (últimos 7 días)',
				'cabecera' => array( 'Veces', 'Nivel', 'Mensaje (acortado, sin rutas ni correos)' ),
				'filas'    => $log['top'],
			);
		}
	} else {
		$s['notas'][] = 'No hay un registro de errores de PHP accesible desde WordPress: los errores no se pueden contar. Si el hosting lo ofrece, conviene activarlo.';
	}

	if ( atm_inf_debug_log_es_publico() ) {
		atm_inf_anadir_aviso( $s, 'critico', 'wp-content/debug.log se puede descargar por URL: puede revelar rutas y datos internos. Hay que bloquearlo o borrarlo.' );
	}

	// Modo de recuperación de WordPress (aviso por error fatal que bloquea una página).
	$recuperacion = (int) get_option( 'recovery_mode_email_last_sent', 0 );

	if ( $recuperacion && $recuperacion > time() - 7 * DAY_IN_SECONDS ) {
		atm_inf_anadir_aviso( $s, 'atencion', 'WordPress envió un aviso de modo de recuperación (error fatal en un plugin o tema) hace ' . human_time_diff( $recuperacion ) . '.' );
	}

	// Correos que WordPress no ha podido enviar.
	$totales = atm_inf_totales_ventana( 7 );

	$s['filas'][] = array( 'Correos fallidos de WordPress (7 días)', (string) $totales['correos_fallidos'] );

	if ( $totales['correos_fallidos'] > 0 ) {
		atm_inf_anadir_aviso( $s, 'atencion', $totales['correos_fallidos'] . ' correo(s) no se han podido enviar desde WordPress en los últimos 7 días (avisos a alumnos, formulario de contacto…).' );
	}

	// Site Health.
	$salud = atm_inf_ejecutar_site_health();

	if ( $salud ) {
		$s['filas'][] = array( 'Salud del sitio (WordPress)', count( $salud['criticos'] ) . ' críticos · ' . count( $salud['recomendados'] ) . ' recomendaciones · ' . $salud['buenos'] . ' correctos' );

		foreach ( $salud['criticos'] as $etiqueta ) {
			atm_inf_anadir_aviso( $s, 'atencion', 'Salud del sitio (crítico): ' . $etiqueta . '.' );
		}

		if ( $salud['recomendados'] ) {
			$s['notas'][] = 'Salud del sitio recomienda: ' . implode( '; ', $salud['recomendados'] ) . '.';
		}
	}

	return $s;
}
