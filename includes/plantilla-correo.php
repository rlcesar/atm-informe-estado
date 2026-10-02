<?php
/**
 * Presentación del informe: asunto, HTML (estilos en línea, apto para clientes
 * de correo) y versión en texto plano. Misma paleta que los correos de la Zona
 * de Alumnos (cabecera negra con acento cian).
 *
 * @package Atm_Informe_Estado
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Asunto del correo, p. ej. `[atmsanse.com] Informe semanal 2026-10-05 — ATENCIÓN`.
 *
 * @param array $informe Informe de `atm_inf_construir_informe()`.
 * @return string
 */
function atm_inf_asunto( array $informe ): string {
	return sprintf(
		'[%1$s] Informe semanal %2$s — %3$s',
		$informe['sitio'],
		wp_date( 'Y-m-d', $informe['generado'] ),
		$informe['veredicto']
	);
}

/**
 * Color asociado a un veredicto o nivel de aviso.
 *
 * @param string $clave `OK`, `ATENCIÓN`, `CRÍTICO`, `atencion` o `critico`.
 * @return string Color hexadecimal.
 */
function atm_inf_color( string $clave ): string {
	switch ( $clave ) {
		case 'CRÍTICO':
		case 'critico':
			return '#b91c1c';
		case 'ATENCIÓN':
		case 'atencion':
			return '#b45309';
		default:
			return '#15803d';
	}
}

/**
 * Tabla HTML con cabecera.
 *
 * @param string[]   $cabecera Títulos de columna.
 * @param string[][] $filas    Filas de celdas.
 * @return string
 */
function atm_inf_html_tabla( array $cabecera, array $filas ): string {
	$celda = 'padding:6px 8px;border-bottom:1px solid #e5e5e5;font-size:13px;vertical-align:top;text-align:left;';
	$html  = '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="border-collapse:collapse;margin:0 0 14px;"><tr>';

	foreach ( $cabecera as $titulo ) {
		$html .= '<th style="' . $celda . 'background-color:#f2f2f2;color:#111111;">' . esc_html( $titulo ) . '</th>';
	}

	$html .= '</tr>';

	foreach ( $filas as $fila ) {
		$html .= '<tr>';

		foreach ( $fila as $valor ) {
			$html .= '<td style="' . $celda . 'color:#333333;">' . esc_html( (string) $valor ) . '</td>';
		}

		$html .= '</tr>';
	}

	return $html . '</table>';
}

/**
 * Informe en HTML.
 *
 * @param array $informe Informe de `atm_inf_construir_informe()`.
 * @return string
 */
function atm_inf_renderizar_html( array $informe ): string {
	$color    = atm_inf_color( $informe['veredicto'] );
	$fuente   = 'font-family:Arial,Helvetica,sans-serif;';
	$cuerpo   = '';

	// Resumen: veredicto y lista de avisos.
	$cuerpo .= '<p style="margin:0 0 14px;' . $fuente . 'font-size:15px;color:#333333;">Resultado: <strong style="color:' . esc_attr( $color ) . ';">' . esc_html( $informe['veredicto'] ) . '</strong></p>';

	if ( $informe['avisos'] ) {
		$cuerpo .= '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="margin:0 0 22px;">';

		foreach ( $informe['avisos'] as $aviso ) {
			$cuerpo .= '<tr><td style="padding:6px 0;' . $fuente . 'font-size:14px;line-height:1.5;color:#333333;border-left:4px solid ' . esc_attr( atm_inf_color( $aviso['nivel'] ) ) . ';padding-left:10px;">'
				. '<strong>' . esc_html( 'critico' === $aviso['nivel'] ? 'Crítico' : 'Atención' ) . '</strong> · ' . esc_html( $aviso['texto'] )
				. ' <span style="color:#888888;">[' . esc_html( $aviso['seccion'] ) . ']</span></td></tr>';
		}

		$cuerpo .= '</table>';
	} else {
		$cuerpo .= '<p style="margin:0 0 22px;' . $fuente . 'font-size:14px;color:#333333;">Sin avisos esta semana.</p>';
	}

	// Secciones.
	foreach ( $informe['secciones'] as $seccion ) {
		$cuerpo .= '<h2 style="margin:26px 0 10px;' . $fuente . 'font-size:16px;color:#111111;border-bottom:2px solid #00e5ff;padding-bottom:4px;">' . esc_html( $seccion['titulo'] ) . '</h2>';

		if ( $seccion['filas'] ) {
			$cuerpo .= atm_inf_html_tabla( array( 'Dato', 'Valor' ), $seccion['filas'] );
		}

		foreach ( $seccion['tablas'] as $tabla ) {
			if ( ! empty( $tabla['titulo'] ) ) {
				$cuerpo .= '<p style="margin:12px 0 6px;' . $fuente . 'font-size:13px;font-weight:bold;color:#111111;">' . esc_html( $tabla['titulo'] ) . '</p>';
			}

			$cuerpo .= atm_inf_html_tabla( $tabla['cabecera'], $tabla['filas'] );
		}

		foreach ( $seccion['notas'] as $nota ) {
			$cuerpo .= '<p style="margin:0 0 8px;' . $fuente . 'font-size:13px;line-height:1.5;color:#666666;">' . esc_html( $nota ) . '</p>';
		}
	}

	$pie = 'Informe generado el ' . wp_date( 'j \d\e F \d\e Y \a \l\a\s H:i', $informe['generado'] ) . ' por el mu-plugin «ATM Sanse — Informe de estado» v' . ATM_INF_VERSION . '. Solo contiene cifras agregadas, sin datos personales.';

	return '<!DOCTYPE html><html lang="es"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width,initial-scale=1"></head>'
		. '<body style="margin:0;padding:0;background-color:#f2f2f2;">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background-color:#f2f2f2;padding:24px 12px;"><tr><td align="center">'
		. '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="max-width:680px;background-color:#ffffff;border-radius:6px;overflow:hidden;">'
		. '<tr><td style="background-color:#050505;padding:20px 28px;"><span style="color:#00e5ff;' . $fuente . 'font-size:13px;letter-spacing:3px;text-transform:uppercase;font-weight:bold;">ATM Sanse</span>'
		. '<span style="color:#ffffff;' . $fuente . 'font-size:13px;"> · Informe semanal de ' . esc_html( $informe['sitio'] ) . '</span></td></tr>'
		. '<tr><td style="padding:26px 28px 8px;">' . $cuerpo . '</td></tr>' // phpcs:ignore WordPress.Security.EscapeOutput.OutputNotEscaped -- HTML ya construido y escapado arriba.
		. '<tr><td style="padding:16px 28px;background-color:#f2f2f2;' . $fuente . 'font-size:12px;color:#888888;">' . esc_html( $pie ) . '</td></tr>'
		. '</table></td></tr></table></body></html>';
}

/**
 * Informe en texto plano (alternativa del correo y salida de `wp atm-informe mostrar`).
 *
 * @param array $informe Informe de `atm_inf_construir_informe()`.
 * @return string
 */
function atm_inf_renderizar_texto( array $informe ): string {
	$texto = atm_inf_asunto( $informe ) . "\n" . str_repeat( '=', 60 ) . "\n\n";

	if ( $informe['avisos'] ) {
		$texto .= "AVISOS\n";

		foreach ( $informe['avisos'] as $aviso ) {
			$texto .= '- [' . ( 'critico' === $aviso['nivel'] ? 'CRÍTICO' : 'ATENCIÓN' ) . '] ' . $aviso['texto'] . ' (' . $aviso['seccion'] . ")\n";
		}
	} else {
		$texto .= "Sin avisos esta semana.\n";
	}

	foreach ( $informe['secciones'] as $seccion ) {
		$texto .= "\n" . mb_strtoupper( $seccion['titulo'] ) . "\n" . str_repeat( '-', 60 ) . "\n";

		foreach ( $seccion['filas'] as $fila ) {
			$texto .= $fila[0] . ': ' . $fila[1] . "\n";
		}

		foreach ( $seccion['tablas'] as $tabla ) {
			if ( ! empty( $tabla['titulo'] ) ) {
				$texto .= "\n" . $tabla['titulo'] . "\n";
			}

			$texto .= implode( ' | ', $tabla['cabecera'] ) . "\n";

			foreach ( $tabla['filas'] as $fila ) {
				$texto .= implode( ' | ', array_map( 'strval', $fila ) ) . "\n";
			}
		}

		foreach ( $seccion['notas'] as $nota ) {
			$texto .= '* ' . $nota . "\n";
		}
	}

	return $texto . "\nInforme generado por el mu-plugin «ATM Sanse — Informe de estado» v" . ATM_INF_VERSION . ". Solo cifras agregadas, sin datos personales.\n";
}
