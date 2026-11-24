# Plan: Correcciones de la revisión de atm-informe-estado

## Decisiones pendientes del usuario (antes de implementar las tareas marcadas)

- **D1 (T2)** Número máximo de reintentos tras un fallo de envío. Propuesta: 3 (a +1 h cada uno); después se espera al lunes siguiente.
  **Decidido (2026-11-23): 3 reintentos, uno por hora.**
- **D2 (T3)** ¿Un `wp atm-informe enviar` **sin** `--a` (al destinatario por defecto, `webadmin@`) cuenta como "el envío de la semana" y anula el del lunes? Propuesta: **no**; solo el cron actualiza `atm_inf_ultimo_envio`. Si se prefiere que sí cuente, T3 lo actualiza también cuando el destino es el por defecto.
  **Decidido (2026-11-23): no cuenta; solo el cron actualiza `atm_inf_ultimo_envio`.**
- **D3 (T5)** Disco: el dato de `disk_total_space()` es el volumen del servidor, no la cuota de SiteGround. Opciones: (a) mantener la fila marcada como "orientativo" y **quitar** los avisos crítico/atención; (b) mantenerla como orientativa y dejar solo el aviso de atención (<10 %); (c) quitar la fila entera. Propuesta: (a).
  **Decidido (2026-11-23): (b) orientativa, solo aviso de atención si queda < 10 %.**
- **D4 (T9)** ¿Se hace la optimización de los accesos fallidos (opcional)? Si no, T9 se descarta.
  **Decidido (2026-11-23): no; T9 se descarta.**
- **D5 (fuera del plan)** ¿Definir `DISABLE_WP_CRON` en el `wp-config.php` de producción, ya que hay cron del sistema? Reduciría la carrera de T1 en origen, pero toca producción y es decisión tuya; T1 no depende de ello.
  **Decidido (2026-11-24): no se desactiva WP-Cron; se mantienen las dos vías (visitas + cron del sistema de Site Tools).**
- **D6 (al cerrar versión)** Semver: el reintento es comportamiento nuevo → propuesta `1.1.0`.
- **D7** ¿`PLAN.md` se commitea o se añade a `.gitignore`? (No debe desplegarse en ningún caso.)

## Objetivo
Corregir los fallos de fiabilidad del envío semanal (doble envío, semana perdida si falla, envío manual que anula el del lunes), cerrar la fuga de IPs/rutas en los errores PHP, pulir detalles menores y dejar el repo con lint propio limpio. Solo código del mu-plugin y su documentación.

## Fuera de alcance
- Nada en producción: no se ejecuta ningún comando por SSH ni se despliega (la comprobación del cron real la hace el usuario, ver T10).
- El cargador `../atm-informe-estado.php` (copia de `loader/`) no cambia salvo el `Version:` al cerrar versión.
- Tema, `atm-zona-alumnos`, `atm-cookies`, FluentSMTP, `wp-config.php`.
- Rediseño del informe o nuevas secciones.

## Verificación de los hallazgos (hecha en el código)
Todos los puntos 1-12 se han comprobado y son ciertos. Matices que cambian el fix propuesto:
- **Punto 1**: `add_option()` y `set_transient()` **no** son atómicos (`add_option` hace `INSERT … ON DUPLICATE KEY UPDATE`; dos procesos pueden "ganar" los dos). El candado debe ser `INSERT IGNORE` + comprobar filas afectadas (el patrón de `WP_Upgrader::create_lock()` del núcleo). Ver T1.
- **Punto 2**: el reintento debe programarse **antes** de construir el informe; si solo se programa al detectar `false` de `wp_mail()`, un fatal (o un timeout a los 180 s) sigue perdiendo la semana.
- **Punto 6**: va dentro de T2 (misma función que se reestructura).
- **Punto 7**: el archivo no usa `strict_types`, así que el `string $user_login` no rompe; el riesgo real es el `WP_User $user` (TypeError en pleno login si un plugin de SSO dispara `wp_login` con otra cosa).
- **Punto 10**: el HTML ya sale bien (`esc_html()` no re-codifica entidades existentes); el fallo solo se ve en el texto plano y en el texto de los avisos.
- **Punto 12**: lint real con el ruleset adaptado: 13 errores (5 `DisallowShortTernary`, 4 `UsedPropertyNotSnakeCase` = `AltBody`, `Name` ×2, `Version`, 4 corregibles por `phpcbf`).

## Tareas

### T1 · Candado atómico contra el doble envío · [lógica] · ALTO
- Archivos: `includes/programacion.php`
- Qué hacer:
  - Nuevas funciones `atm_inf_tomar_candado(): bool` y `atm_inf_soltar_candado(): void`, opción `atm_inf_enviando` (constante `ATM_INF_OPCION_CANDADO`).
  - Tomar: `$wpdb->query( $wpdb->prepare( "INSERT IGNORE INTO {$wpdb->options} (option_name, option_value, autoload) VALUES (%s, %s, 'off')", … , time() ) )`; si afecta 0 filas, leer el valor directamente de BD (`$wpdb->get_var` preparado, sin caché) y, si es más antiguo que 15 min (candado huérfano tras un fatal), sustituirlo con un `UPDATE … WHERE option_value = <valor leído>` preparado y aceptar solo si afecta 1 fila. Tras escribir, `wp_cache_delete( 'notoptions', 'options' )` y la clave de la opción.
  - Soltar: `delete_option()`.
  - En `atm_inf_ejecutar_informe_programado()`: tomar el candado **antes** de comprobar `atm_inf_ultimo_envio` y de construir; si no se obtiene, salir. Releer `atm_inf_ultimo_envio` tras `wp_cache_delete()` (el otro proceso pudo actualizarla). Soltar en `finally`.
- Criterios de aceptación:
  - Dos ejecuciones simultáneas del gancho (simuladas en Local con dos `wp eval` en paralelo y los filtros de dominio/destinatario de "Verificar un cambio") producen **un solo** correo en Mailpit.
  - Con un candado de hace más de 15 min en la opción, el gancho envía; con uno de hace 1 min, no.
  - Todas las consultas van por `$wpdb->prepare()`.
- Documentación: línea en `CHANGELOG.txt` (`[Sin publicar]`); `CLAUDE.md` → mapa de `programacion.php` (candado) y paso 4 de "Despliegue · Retirar" (añadir `atm_inf_enviando` a `wp option delete`).

### T2 · Reintento tras fallo y comprobación de producción primero · [lógica] · ALTO (incluye BAJO 6)
- Depende de: T1. Decisión: D1.
- Archivos: `includes/programacion.php`
- Qué hacer:
  - Al principio del manejador: si `! atm_inf_es_produccion()`, `wp_clear_scheduled_hook()` de los dos ganchos y salir, **sin** reprogramar (punto 6).
  - Nuevo gancho `atm_inf_informe_reintento` (constante) y opción de intentos `atm_inf_reintentos` (sin autoload).
  - Flujo, con el candado de T1 tomado: reprogramar el lunes siguiente (como ahora) → **antes de construir**, si intentos < máximo, programar el reintento a `time() + HOUR_IN_SECONDS` e incrementar intentos → enviar → si `wp_mail()` devuelve `true`: desprogramar el reintento y borrar `atm_inf_reintentos`.
  - El reintento ejecuta el mismo flujo (candado + comprobación de 3 días) pero no reprograma el lunes. Constante del máximo con filtro `atm_inf_max_reintentos`.
  - `atm_inf_asegurar_programacion()` también borra el gancho de reintento fuera de producción.
- Criterios de aceptación:
  - Con `pre_wp_mail` devolviendo `false` en Local: queda un reintento a +1 h y `atm_inf_reintentos` = 1; tras N fallos no se programa ninguno más; el lunes siguiente sigue programado.
  - Con un fatal provocado tras la programación (p. ej. `atm_inf_construir_informe` lanzando un `Error` no capturado en un `eval`), el reintento queda programado.
  - Envío correcto: sin reintento pendiente y sin la opción de intentos.
  - Fuera de producción el manejador no programa nada.
- Documentación: `CHANGELOG.txt`; `CLAUDE.md` → mapa de `programacion.php` (reintento, orden de comprobaciones), paso 4 de "Retirar" (`atm_inf_reintentos`, `wp cron event delete atm_inf_informe_reintento`) y la invariante "Nunca enviar desde fuera de producción" (mencionar los dos ganchos). `wp atm-informe estado` (`class-atm-inf-comando.php`): añadir la línea "Reintento pendiente".

### T3 · El envío manual no anula el del lunes · [lógica] · MEDIO
- Depende de: T2. Decisión: D2.
- Archivos: `includes/envio.php`, `includes/programacion.php`
- Qué hacer: quitar `update_option( ATM_INF_OPCION_ENVIO … )` de `atm_inf_enviar_informe()` y hacerlo en el manejador del cron (y del reintento) cuando el envío devuelve `true`. Según D2, opcionalmente también en `Atm_Inf_Comando::enviar()` cuando el destino es el por defecto.
- Criterios de aceptación: `wp atm-informe enviar --a=otro@ejemplo.com` no cambia `atm_inf_ultimo_envio` (comprobar con `wp atm-informe estado`); el gancho del cron sí la actualiza.
- Documentación: `CHANGELOG.txt`; `CLAUDE.md` → retirar la advertencia "un envío manual previo fija `atm_inf_ultimo_envio`…" de "Verificar un cambio" (o ajustarla a D2).

### T4 · Quitar IPs y rutas absolutas de los errores PHP · [lógica] · MEDIO (privacidad)
- Archivos: `includes/recolector-problemas.php` (líneas 89-91)
- Qué hacer: además de `ABSPATH` y correos, sustituir por `[ip]` las IPv4 (con validación de octetos o `filter_var(…, FILTER_VALIDATE_IP)` sobre cada candidata) y las IPv6 (candidatas con al menos dos `:` y grupos hex, validadas con `filter_var(…, FILTER_FLAG_IPV6)` para no comerse horas tipo `12:30:45`), y por `…/` cualquier ruta absoluta restante (`/home/…`, `/usr/…`, `/tmp/…`, `C:\…`), conservando el nombre de archivo final. Sacarlo a una función pura `atm_inf_sanear_mensaje_log( string $mensaje ): string` para poder probarla.
- Criterios de aceptación (script con stubs en el scratchpad, fuera del repo):
  - `… from 192.168.1.10 in /home/customer/www/x/public_html/wp-content/plugins/p/a.php on line 3` → sin IP ni `/home/customer`, conserva `a.php on line 3`.
  - IPv6 (`2001:db8::1`, `::ffff:10.0.0.1`) se sustituyen; `12:30:45` y `Array::map` no.
  - Correos siguen saliendo como `[correo]`; el mensaje sigue truncado a 170 caracteres **después** de sanear.
- Documentación: `CHANGELOG.txt`; `CLAUDE.md` → invariante "Solo cifras agregadas" (mencionar IPs y rutas absolutas en el saneado de errores).

### T5 · Disco: dato orientativo · [lógica] · MEDIO
- Depende de: D3.
- Archivos: `includes/recolector-estado.php` (116-127)
- Qué hacer: según D3. Con (a): etiqueta de fila `Disco (volumen del servidor, orientativo)`, nota que explique que la cuota de SiteGround se ve en Site Tools, y fuera los dos `atm_inf_anadir_aviso` de disco.
- Criterios de aceptación: `wp atm-informe mostrar` en Local muestra la fila nueva; ningún porcentaje de disco puede cambiar el veredicto (salvo que D3 diga lo contrario).
- Documentación: `CHANGELOG.txt`; `CLAUDE.md` → frase "Qué es crítico y qué es atención" (quitar "menos de 10 % de disco" de crítico).

### T6 · Callback de `wp_login` tolerante · [lógica] · BAJO
- Archivos: `includes/contadores.php` (151)
- Qué hacer: firma `atm_inf_hook_login( $user_login, $user ): void` sin tipos en los parámetros (DocBlock `@param mixed`), y `if ( ! $user instanceof WP_User ) { return; }` al principio.
- Criterios de aceptación: `do_action( 'wp_login', 'x', null )` en un `wp eval` no lanza TypeError ni cuenta nada; un login normal sigue contando.
- Documentación: `CHANGELOG.txt`.

### T7 · No leer `atm_inf_contadores_desde` en cada petición · [lógica] · BAJO
- Archivos: `includes/contadores.php` (138-143)
- Qué hacer: quitar el `add_action( 'init', … )` y llamar a `atm_inf_asegurar_inicio_contadores()` desde `atm_inf_registrar_evento()` (ya hace una escritura, el coste es marginal). En producción la opción ya existe desde el despliegue, así que la fecha no cambia.
- Criterios de aceptación: con la opción borrada en Local, cargar una página no la crea; un login sí. `wp atm-informe estado` sigue mostrándola.
- Documentación: `CHANGELOG.txt`; `CLAUDE.md` → mapa de `contadores.php` (cuándo se fija `_desde`).

### T8 · Títulos de curso sin entidades HTML · [lógica] · BAJO (toca escapado)
- Archivos: `includes/recolector-actividad.php` (118, 126)
- Qué hacer: calcular el título una vez por curso con `html_entity_decode( wp_strip_all_tags( get_the_title( $curso_id ) ), ENT_QUOTES, 'UTF-8' )` (con el respaldo `'Curso ' . $curso_id` sin ternario corto) y reutilizarlo en la fila y en `$inactivos`. El HTML lo sigue escapando `plantilla-correo.php` con `esc_html()`: no se toca.
- Criterios de aceptación: un curso titulado `Taller "Grease" & Co's` se ve igual en texto plano (`wp atm-informe mostrar`) y en HTML (`--html`, escapado correcto, sin `&amp;#8217;`).
- Documentación: `CHANGELOG.txt`.

### T9 · Accesos fallidos sin reescribir la opción en cada intento · [lógica] · BAJO · OPCIONAL
- Depende de: D4.
- Archivos: `includes/contadores.php`
- Qué hacer: acumular `login_fallidos` en un transient del día (`atm_inf_fallidos_<AAAA-MM-DD>`, caducidad 2 días) y volcarlo a `atm_inf_contadores` al leer (`atm_inf_totales_ventana()`) y al cerrar el día. Aceptar la pérdida ocasional por carrera, como ya documenta el archivo.
- Criterios de aceptación: 50 `do_action( 'wp_login_failed', 'x' )` → la opción `atm_inf_contadores` no se reescribe 50 veces (contar `update_option` con el filtro `pre_update_option_atm_inf_contadores`) y el informe suma 50.
- Documentación: `CHANGELOG.txt`; DocBlock del archivo y mapa de `contadores.php` en `CLAUDE.md`; paso 4 de "Retirar" si deja transients.

### T10 · Documentación pendiente · [mecánica] · DOCUMENTACIÓN
- Archivos: `CLAUDE.md`
- Qué hacer:
  - "Verificar un cambio": sustituir el PHP de Local por `php -l` y `phpcs -n` (están en el PATH). La parte de WP-CLI contra Local con el `php.ini` de Local se mantiene (lo necesita por el socket de MySQL).
  - Despliegue paso 2: cambiar "Sin ver todavía una ejecución real del cron…" por **"Pendiente (César): comprobar en producción con `wp atm-informe estado` que el último envío es de un lunes posterior al 2026-10-02"**. No ejecutar nada en producción.
  - "Qué es crítico y qué es atención": reescribir la frase de actualizaciones: la actualización pendiente es **atención**, y si el changelog menciona seguridad se añade **además** un aviso **crítico** aparte.
  - Despliegue paso 1: añadir `phpcs.xml.dist` y `PLAN.md` a la lista de lo que no se sube.
- Criterios de aceptación: ninguna referencia al binario de PHP de Local para lint; la frase de crítico/atención coincide con `recolector-actualizaciones.php:168-172`.
- Documentación: `CHANGELOG.txt` no (solo documentación); avisar al orquestador de que la memoria `php-lint-con-php-de-local.md` ha quedado obsoleta (no está en el repo).

### T11 · Lint propio del plugin · [mecánica] · LINT (última)
- Depende de: T1-T10 terminadas.
- Archivos: `phpcs.xml.dist` (nuevo), los `includes/*.php` y `atm-informe-estado.php` que marque el lint.
- Qué hacer:
  - `phpcs.xml.dist` copiado de `../../themes/telex-teatro-lights/phpcs.xml.dist` con: text domain `atm-informe-estado`, prefijo `atm_inf`, `minimum_wp_version` 6.5; comentario de cabecera en castellano.
  - `phpcbf` sobre los archivos con errores corregibles (espaciado de arrays asociativos, comentario de bloque).
  - Cambiar los ternarios cortos `?:` que queden (hoy: `envio.php:38`, `class-atm-inf-comando.php:88`, `recolector-actualizaciones.php:213`; los de `recolector-actividad.php` los quita T8) por ternarios completos equivalentes.
  - `// phpcs:ignore WordPress.NamingConventions.ValidVariableName.UsedPropertyNotSnakeCase -- propiedad de PHPMailer/WP_Plugin_Upgrader; no la nombra este código.` en `envio.php` (`AltBody`) y `recolector-actualizaciones.php` (`->Name`, `->Version`).
- Criterios de aceptación: `phpcs -n` desde la raíz del repo → 0 errores; `php -l` en cada archivo tocado; sin cambios de comportamiento (diff solo de formato, ternarios y comentarios).
- Documentación: `CHANGELOG.txt`; `CLAUDE.md` → "Verificar un cambio" (`phpcs -n` usa `phpcs.xml.dist` propio).

## Riesgos
- **Concurrencia/BD (T1)**: consultas SQL directas a `wp_options`; obligatorio `$wpdb->prepare()` y limpiar la caché de opciones. Un candado mal liberado bloquearía los envíos: la caducidad de 15 min debe superar el tiempo máximo de construcción (180 s).
- **Envíos de más (T2)**: un bug en el contador de intentos podría mandar un correo por hora; el máximo es un tope duro y la comprobación de 3 días sigue aplicando.
- **Producción**: la opción `atm_inf_ultimo_envio` y el evento ya existen allí; ningún cambio debe exigir migración. La comprobación real del cron es del usuario (T10). No se despliega.
- **Privacidad (T4)**: regex demasiado laxas borrarían información útil; demasiado estrictas dejarían pasar IPs. Probar con los casos de los criterios.
- **Compatibilidad**: PHP 8.0+ y WP 6.5+ (no usar sintaxis de PHP 8.1+ como `readonly` o enums). El `autoload` `'off'` es de WP 6.6+: con 6.5 usar `'no'` o subir el requisito (decidir en T1; propuesta: `'no'`, que WP 6.6+ sigue aceptando).
- **Lint durante T1-T10**: el hook de lint marcará errores heredados en archivos tocados; no corregirlos fuera de T11 para mantener los diffs separados.
