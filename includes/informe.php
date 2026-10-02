<?php
/**
 * Ensamblado del informe: llama a cada recolector, reúne los avisos y calcula el veredicto.
 *
 * Contrato de un recolector: función sin parámetros que devuelve una «sección»
 * (ver `atm_inf_seccion()`). Si lanza una excepción, el informe sigue con las
 * demás secciones y deja un aviso, para que un fallo puntual no impida el correo.
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Crea una sección vacía del informe.
 *
 * Forma: `titulo` (string); `filas` (lista de `[etiqueta, valor]`); `tablas` (lista de
 * `['titulo' => string, 'cabecera' => string[], 'filas' => string[][]]`); `notas`
 * (lista de strings); `avisos` (lista de `['nivel' => 'critico'|'atencion', 'texto' => string]`).
 *
 * @param string $titulo Título de la sección.
 * @return array
 */
function atm_inf_seccion( string $titulo ): array {
	return array(
		'titulo' => $titulo,
		'filas'  => array(),
		'tablas' => array(),
		'notas'  => array(),
		'avisos' => array(),
	);
}

/**
 * Añade un aviso a una sección.
 *
 * @param array  $seccion Sección (por referencia).
 * @param string $nivel   `critico` o `atencion`.
 * @param string $texto   Texto del aviso, ya en castellano y sin HTML.
 */
function atm_inf_anadir_aviso( array &$seccion, string $nivel, string $texto ): void {
	$seccion['avisos'][] = array(
		'nivel' => $nivel,
		'texto' => $texto,
	);
}

/**
 * Cuenta las cuentas de usuario por rol (una sola vez por ejecución).
 *
 * @return array Resultado de `count_users()`.
 */
function atm_inf_contar_usuarios(): array {
	static $cuentas = null;

	if ( null === $cuentas ) {
		$cuentas = count_users();
	}

	return $cuentas;
}

/**
 * Construye el informe completo.
 *
 * @return array `generado` (marca de tiempo), `sitio` (dominio), `veredicto`
 *               (`OK`, `ATENCIÓN` o `CRÍTICO`), `avisos` (todos, de más a menos
 *               grave, con su sección) y `secciones`.
 */
function atm_inf_construir_informe(): array {
	$recolectores = array(
		array( 'Estado del servidor', 'atm_inf_recolectar_estado' ),
		array( 'Actualizaciones y configuración', 'atm_inf_recolectar_actualizaciones' ),
		array( 'Problemas detectados', 'atm_inf_recolectar_problemas' ),
		array( 'Seguridad', 'atm_inf_recolectar_seguridad' ),
		array( 'Actividad y Zona de Alumnos', 'atm_inf_recolectar_actividad' ),
	);

	$secciones = array();
	$avisos    = array();

	foreach ( $recolectores as list( $titulo, $funcion ) ) {
		try {
			$seccion = $funcion();
		} catch ( \Throwable $e ) {
			$seccion = atm_inf_seccion( $titulo );
			atm_inf_anadir_aviso(
				$seccion,
				'atencion',
				'No se ha podido calcular esta sección (' . get_class( $e ) . ': ' . mb_substr( wp_strip_all_tags( $e->getMessage() ), 0, 160 ) . ').'
			);
		}

		foreach ( $seccion['avisos'] as $aviso ) {
			$avisos[] = array(
				'nivel'   => $aviso['nivel'],
				'texto'   => $aviso['texto'],
				'seccion' => $seccion['titulo'],
			);
		}

		$secciones[] = $seccion;
	}

	usort(
		$avisos,
		static function ( array $a, array $b ): int {
			return ( 'critico' === $b['nivel'] ) <=> ( 'critico' === $a['nivel'] );
		}
	);

	$veredicto = 'OK';

	foreach ( $avisos as $aviso ) {
		if ( 'critico' === $aviso['nivel'] ) {
			$veredicto = 'CRÍTICO';
			break;
		}

		$veredicto = 'ATENCIÓN';
	}

	return array(
		'generado'  => time(),
		'sitio'     => preg_replace( '/^www\./', '', (string) wp_parse_url( home_url(), PHP_URL_HOST ) ),
		'veredicto' => $veredicto,
		'avisos'    => $avisos,
		'secciones' => $secciones,
	);
}
