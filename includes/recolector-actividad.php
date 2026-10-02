<?php
/**
 * Recolector «Actividad y Zona de Alumnos»: contenido del sitio y uso de la
 * Zona de Alumnos, siempre en cifras agregadas.
 *
 * Nunca se incluyen nombres, correos, IPs ni títulos de archivos. Los únicos
 * nombres que aparecen son los de los cursos (contenido público de la web).
 * Lee las tablas del plugin `atm-zona-alumnos` sin cargarlo: si no están, la
 * parte de la Zona de Alumnos se omite.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Indica si existe una tabla propia en la base de datos.
 *
 * @param string $tabla Nombre completo, con prefijo.
 * @return bool
 */
function atm_inf_existe_tabla( string $tabla ): bool {
	global $wpdb;

	return $tabla === $wpdb->get_var( $wpdb->prepare( 'SHOW TABLES LIKE %s', $wpdb->esc_like( $tabla ) ) );
}

/**
 * Sección «Actividad y Zona de Alumnos».
 *
 * @return array Sección del informe (ver `atm_inf_seccion()`).
 */
function atm_inf_recolectar_actividad(): array {
	global $wpdb;

	$s       = atm_inf_seccion( 'Actividad y Zona de Alumnos' );
	$hace_7  = wp_date( 'Y-m-d H:i:s', time() - 7 * DAY_IN_SECONDS );
	$hace_30 = wp_date( 'Y-m-d H:i:s', time() - 30 * DAY_IN_SECONDS );

	// Contenido del sitio.
	foreach ( array( 'teatro_curso' => 'Cursos publicados', 'teatro_show' => 'Actuaciones publicadas' ) as $tipo => $etiqueta ) {
		if ( post_type_exists( $tipo ) ) {
			$s['filas'][] = array( $etiqueta, (string) ( wp_count_posts( $tipo )->publish ?? 0 ) );
		}
	}

	if ( post_type_exists( 'teatro_inquiry' ) ) {
		$consulta = new WP_Query(
			array(
				'post_type'      => 'teatro_inquiry',
				'post_status'    => 'any',
				'posts_per_page' => 1,
				'fields'         => 'ids',
				'date_query'     => array( array( 'after' => '7 days ago' ) ),
			)
		);

		$s['filas'][] = array( 'Mensajes del formulario de contacto (7 días)', (string) $consulta->found_posts );
	}

	// Cuentas.
	$cuentas = atm_inf_contar_usuarios();
	$roles   = (array) ( $cuentas['avail_roles'] ?? array() );

	$s['filas'][] = array( 'Cuentas de usuario', (string) ( $cuentas['total_users'] ?? 0 ) );

	$roles_alumno = array_values(
		array_filter(
			array_keys( $roles ),
			static function ( $rol ): bool {
				return str_starts_with( (string) $rol, 'atm_za_alumno_' );
			}
		)
	);

	if ( isset( $roles['atm_za_profesor'] ) || $roles_alumno ) {
		$alumnos = $roles_alumno
			? count( get_users( array( 'role__in' => $roles_alumno, 'fields' => 'ID', 'number' => -1 ) ) )
			: 0;

		$s['filas'][] = array( 'Profesorado con acceso a la Zona de Alumnos', (string) ( $roles['atm_za_profesor'] ?? 0 ) );
		$s['filas'][] = array( 'Alumnado matriculado en algún curso (cuentas distintas)', (string) $alumnos );
	}

	// Archivos y mensajes de la Zona de Alumnos.
	$t_archivos = $wpdb->prefix . 'atm_za_archivos';
	$t_mensajes = $wpdb->prefix . 'atm_za_mensajes';

	if ( atm_inf_existe_tabla( $t_archivos ) && atm_inf_existe_tabla( $t_mensajes ) ) {
		// Los nombres de tabla salen de `$wpdb->prefix` + una constante: no hay datos de usuario en el SQL.
		$total  = $wpdb->get_row( "SELECT COUNT(*) AS n, COALESCE(SUM(tamano_bytes), 0) AS bytes FROM {$t_archivos}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$semana = $wpdb->get_row( $wpdb->prepare( "SELECT COUNT(*) AS n, COALESCE(SUM(tamano_bytes), 0) AS bytes FROM {$t_archivos} WHERE creado_en >= %s", $hace_7 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$msg_t  = (int) $wpdb->get_var( "SELECT COUNT(*) FROM {$t_mensajes}" ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
		$msg_s  = (int) $wpdb->get_var( $wpdb->prepare( "SELECT COUNT(*) FROM {$t_mensajes} WHERE creado_en >= %s", $hace_7 ) ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared

		$s['filas'][] = array( 'Archivos subidos (7 días)', (int) $semana->n . ' (' . size_format( (int) $semana->bytes ) . ')' );
		$s['filas'][] = array( 'Archivos en total', (int) $total->n . ' (' . size_format( (int) $total->bytes ) . ')' );
		$s['filas'][] = array( 'Mensajes del profesorado (7 días / total)', $msg_s . ' / ' . $msg_t );

		// Resumen por curso.
		$cursos = array_map( 'intval', (array) get_option( 'atm_za_cursos_con_rol', array() ) );

		if ( $cursos ) {
			$por_archivos = $wpdb->get_results( "SELECT curso_id, COUNT(*) AS n, COALESCE(SUM(tamano_bytes), 0) AS bytes, MAX(actualizado_en) AS ultimo FROM {$t_archivos} GROUP BY curso_id", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$por_mensajes = $wpdb->get_results( "SELECT curso_id, COUNT(*) AS n, MAX(creado_en) AS ultimo FROM {$t_mensajes} GROUP BY curso_id", OBJECT_K ); // phpcs:ignore WordPress.DB.PreparedSQL.InterpolatedNotPrepared
			$filas        = array();
			$inactivos    = array();

			foreach ( $cursos as $curso_id ) {
				$matriculados = (int) ( $roles[ 'atm_za_alumno_' . $curso_id ] ?? 0 );
				$archivos     = $por_archivos[ $curso_id ] ?? null;
				$mensajes     = $por_mensajes[ $curso_id ] ?? null;
				$ultimo       = max( (string) ( $archivos->ultimo ?? '' ), (string) ( $mensajes->ultimo ?? '' ) );

				$filas[] = array(
					get_the_title( $curso_id ) ?: 'Curso ' . $curso_id,
					(string) $matriculados,
					$archivos ? (int) $archivos->n . ' (' . size_format( (int) $archivos->bytes ) . ')' : '0',
					(string) (int) ( $mensajes->n ?? 0 ),
					'' === $ultimo ? 'nunca' : human_time_diff( (int) get_gmt_from_date( $ultimo, 'U' ) ),
				);

				if ( $matriculados > 0 && ( '' === $ultimo || $ultimo < $hace_30 ) ) {
					$inactivos[] = get_the_title( $curso_id ) ?: 'Curso ' . $curso_id;
				}
			}

			$s['tablas'][] = array(
				'titulo'   => 'Zona de Alumnos por curso',
				'cabecera' => array( 'Curso', 'Alumnos', 'Archivos', 'Mensajes', 'Última actividad (hace)' ),
				'filas'    => $filas,
			);

			if ( $inactivos ) {
				$s['notas'][] = 'Cursos con alumnos matriculados y sin archivos ni mensajes nuevos en 30 días: ' . implode( ', ', $inactivos ) . '.';
			}
		}
	}

	// Accesos (desde que el mu-plugin empezó a contar).
	$ahora = atm_inf_totales_ventana( 7 );
	$antes = atm_inf_totales_ventana( 7, 7 );
	$desde = (string) get_option( ATM_INF_OPCION_DESDE, '' );

	$s['filas'][] = array( 'Inicios de sesión de alumnado y profesorado (7 días)', $ahora['login_zona'] . ' (semana anterior: ' . $antes['login_zona'] . ')' );
	$s['filas'][] = array( 'Usuarios distintos por día (media de 7 días)', (string) $ahora['distintos_media'] . ' (semana anterior: ' . $antes['distintos_media'] . ')' );
	$s['filas'][] = array( 'Descargas de archivos (7 días)', $ahora['descargas'] . ' (semana anterior: ' . $antes['descargas'] . ')' );
	$s['filas'][] = array( 'Inicios de sesión de otras cuentas (7 días)', (string) $ahora['login_otros'] );

	if ( '' !== $desde && $desde > wp_date( 'Y-m-d', time() - 6 * DAY_IN_SECONDS ) ) {
		$s['notas'][] = 'Los contadores de accesos y descargas empezaron el ' . $desde . ': esta semana está incompleta.';
	}

	$s['notas'][] = 'WordPress no guarda histórico de accesos: estas cifras cuentan desde que el mu-plugin está activo. Las descargas son peticiones con sesión iniciada (incluyen las que el plugin rechaza por permisos).';

	return $s;
}
