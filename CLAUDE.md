# CLAUDE.md

Este archivo guía a Claude Code (claude.ai/code) al trabajar en este mu-plugin.

## Qué es esto

**ATM Sanse — Informe de estado** (`atm-informe-estado`): mu-plugin **autónomo** que cada lunes a las 7:30 (hora de Madrid) envía por correo a `webadmin@atmsanse.com` un informe de cómo está la web: servidor, actualizaciones pendientes con resumen de cambios, problemas, seguridad y actividad de la Zona de Alumnos en cifras agregadas. Solo **lee**; no expone ningún endpoint ni modifica nada del sitio.

- Repo propio (como `atm-cookies` y `atm-zona-alumnos`), separado del tema. Remoto `origin`: `ssh://git@ssh.github.com:443/rlcesar/atm-informe-estado.git` (como los otros repos, vía el puerto 443).
- Decisiones del cliente (2026-10-02): autónomo (sin rutina cloud ni token), repo propio, destinatario único `webadmin@`, el correo sale por el propio servidor (`wp_mail`, remitente `no-responder@` que fija el tema en `includes/remitente-correo.php`). Una rutina cloud con comprobaciones públicas se probó y se descartó por poco útil.
- **No cubre la caída del servicio**: si WordPress está caído no se envía nada. Eso lo cubre un monitor externo (UptimeRobot o similar), pendiente de dar de alta por el cliente.
- Requiere WordPress 6.5+ y PHP 8.0+. No hay build tooling ni Composer.

## Convenciones no negociables

Mismas que el tema, más:

- **Prefijo `atm_inf_`** (Informe de estado) en funciones, constantes, opciones y hooks; clase `Atm_Inf_*`. Comentarios en castellano. Autoría `César Rodríguez López` / `https://motormediapress.com`.
- **Estilo**: WordPress Coding Standards — tabs, llaves K&R, espacio dentro de paréntesis, **sin** espacio en los casts. DocBlock en toda función; tipos en parámetros y retorno.
- **Sin i18n a propósito**: el informe es interno, para una persona y en castellano; no se usa `__()`. Si algún día se traduce, hay que envolver todos los textos.
- **`CHANGELOG.txt`**: cada cambio añade una línea en `## [Sin publicar]`. "Haz el commit" = cerrar la versión semver en el mismo commit (mover `[Sin publicar]`, subir `Version:` en `atm-informe-estado.php`, en `loader/atm-informe-estado.php` **y** `ATM_INF_VERSION`). Mensaje: `Cerrar versión X.Y.Z: <resumen>`.
- **Documentar cada cambio en el mismo cambio**: línea de changelog + este `CLAUDE.md` si cambia un archivo, hook, opción o patrón + memoria si es una decisión del usuario.

## Invariantes (no romper)

- **Solo cifras agregadas.** Nunca nombres, correos, IPs, títulos de archivos ni IDs de usuario en el informe ni en los contadores. Los únicos nombres que salen son los de los cursos (contenido público). Los mensajes de error del log se acortan y se les quita la ruta del servidor y cualquier correo.
- **Solo lectura**: lo único que se escribe son los contadores y las banderas de este plugin (opciones `atm_inf_*`, sin autoload) y el evento de cron propio.
- **Nunca enviar desde fuera de producción**: el evento solo se programa si `atm_inf_es_produccion()` (dominio `atmsanse.com` / `www.`) y en cualquier otro dominio se borra, porque la base de datos de producción importada en Local trae el evento. `wp atm-informe enviar` exige `--a=<correo>` fuera de producción.
- Un recolector que lanza una excepción no debe impedir el informe (lo captura `atm_inf_construir_informe()`).
- Sin endpoints REST/AJAX ni formularios: si algún día se añade uno, comprobar capacidad **y** nonce/token y revisar `references/seguridad.md` de la skill `wordpress-dev`.

## Mapa de archivos

- `atm-informe-estado.php` — cabecera, constantes y `require_once` en orden. `loader/atm-informe-estado.php` — cargador que va **copiado** en `mu-plugins/` (WordPress no carga subcarpetas de `mu-plugins/`).
- `includes/contadores.php` — contadores diarios en la opción `atm_inf_contadores` (+ `atm_inf_contadores_desde`): `wp_login`, `wp_login_failed`, `wp_mail_failed` y `admin_post_atm_za_descargar_archivo` (prioridad 1, sin tocar el plugin de alumnos). "Usuarios distintos" = huellas HMAC que incluyen el día y se compactan a un número al cerrar el día. Retención 90 días. Cuenta **desde el despliegue**: WordPress no guarda histórico. Las descargas son peticiones con sesión (incluyen las rechazadas por permisos).
- `includes/programacion.php` — `atm_inf_es_produccion()`, `atm_inf_proximo_lunes()` (7:30 `Europe/Madrid`, respeta el cambio horario) y el evento único `atm_inf_informe_semanal` que se reprograma a sí mismo antes de enviar; no repite si ya envió hace menos de 3 días.
- `includes/recolector-estado.php` · `-actualizaciones.php` · `-problemas.php` · `-seguridad.php` · `-actividad.php` — una función `atm_inf_recolectar_*()` por sección, que devuelve la estructura de `atm_inf_seccion()` (filas, tablas, notas, avisos con nivel `critico`/`atencion`). Para añadir un chequeo: añadir filas/avisos en el recolector que toque; para una sección nueva, un archivo nuevo y una línea en `atm_inf_construir_informe()`.
- `includes/informe.php` — ensambla secciones, ordena avisos y calcula el veredicto (`CRÍTICO` si hay un aviso crítico, `ATENCIÓN` si hay alguno, `OK`).
- `includes/plantilla-correo.php` — asunto, HTML (estilos en línea, paleta de los correos de la Zona de Alumnos) y texto plano. `includes/envio.php` — `wp_mail()` con `AltBody`. `includes/wpcli.php` + `class-atm-inf-comando.php` — `wp atm-informe mostrar|enviar|estado`.

Qué es crítico y qué es atención: crítico = archivos del núcleo alterados, PHP en `uploads/`, `debug.log` descargable, sitio oculto a buscadores (`blog_public` = 0), menos de 10 % de disco; el resto de avisos (actualizaciones pendientes —aunque el changelog mencione seguridad la actualización se marca crítica—, fatales PHP, correos fallidos, Site Health crítico, cron atrasado, muchos accesos fallidos…) son atención.

## Despliegue (lo pide César aparte; ver también la memoria `produccion-ssh-siteground`)

1. Copia previa en el servidor; en producción: `wp-content/mu-plugins/atm-informe-estado/` (carpeta, **sin** `.git`, `CLAUDE.md`, `CHANGELOG.txt`, `.gitignore` ni `loader/`) y `wp-content/mu-plugins/atm-informe-estado.php` (el contenido de `loader/atm-informe-estado.php`). Si `mu-plugins/` no existe, crearla. Con rsync, flags escritos literalmente y **dry-run (`-n`) primero**.
2. Recomendado y **sin verificar en SiteGround**: un cron del sistema (Site Tools → Devs → Cron Jobs) cada 15 min con `/usr/local/bin/wp --path=/home/customer/www/atmsanse.com/public_html cron event run --due-now`; WP-Cron por visitas puede retrasar el informe horas si no hay tráfico el lunes a esa hora.
3. Comprobar: `wp atm-informe estado` (debe decir producción: sí y mostrar el próximo lunes) y `wp atm-informe mostrar`; para ver el correo real, `wp atm-informe enviar` (va a `webadmin@`).
4. Retirar: borrar los dos elementos de `mu-plugins/`, `wp option delete atm_inf_contadores atm_inf_contadores_desde atm_inf_ultimo_envio` y `wp cron event delete atm_inf_informe_semanal`. Un mu-plugin no tiene desinstalación automática.

En Local: copia `loader/atm-informe-estado.php` a `wp-content/mu-plugins/` (ya está). Allí el cron no se programa (no es el dominio de producción) y `wp atm-informe mostrar` imprime el informe sin enviar nada.

## Verificar un cambio

- Sintaxis: `"$HOME/Library/Application Support/Local/lightning-services/php-8.3.30+1/bin/darwin-arm64/bin/php" -l <archivo>` (la carpeta de versión puede cambiar).
- Lógica pura: se probó con stubs mínimos de WordPress en un script suelto **fuera del repo** (scratchpad): próximo lunes con cambio horario, contadores y compactación, análisis del log, resumen de changelog, escaneo de `uploads`, veredicto y escapado del HTML. Repetirlo si se toca esa lógica.
- Lo que depende de WordPress de verdad (Site Health, actualizaciones, checksums, envío, ganchos de login/descarga) se prueba con **WP-CLI contra el sitio de Local encendido** (probado el 2026-10-02): `php -c <run>/conf/php/php.ini /Applications/Local.app/Contents/Resources/extraResources/bin/wp-cli/wp-cli.phar --path=<…/app/public> atm-informe mostrar`, con el PHP de Local y `<run>` = `~/Library/Application Support/Local/run/<id del sitio>` (el `php.ini` de ahí trae el socket de MySQL; el id se ve en `pgrep -fl php-fpm`). `wp eval-file` sirve para provocar casos (un `.php` en `uploads/`, un `debug.log`, un transient `update_plugins` falso) y limpiarlos en un `finally`.
- Correo en Local: lo recoge Mailpit; API en `http://127.0.0.1:10005/api/v1/messages` (UI en `/view/<id>.html`). Para probar el gancho de cron sin escribir a `webadmin@`, filtrar `atm_inf_dominio_produccion` (al dominio de Local) y `atm_inf_destinatario` en el `eval`. Ojo: un envío manual previo fija `atm_inf_ultimo_envio` y el gancho no repite en 3 días; borrar esa opción antes de repetir la prueba, y borrar también `atm_inf_contadores*` al terminar.
- No se ha probado la detección de archivos del núcleo alterados (solo el caso «todo íntegro» con los checksums reales).

## Trampas conocidas

- WP-Cron solo corre con visitas: sin cron del sistema el informe puede salir tarde (ver despliegue).
- `plugins_api()` consulta wordpress.org hasta 15 veces por informe; los plugins premium/propios no tienen changelog ni aparecen como pendientes si no ofrecen su actualización.
- El informe puede tardar (HTTP + recorrido de `uploads/` + md5 del núcleo): `atm_inf_enviar_informe()` sube el límite de tiempo a 180 s y los recorridos tienen tope propio.
- Los datos de errores PHP dependen de que el hosting deje un log legible desde PHP (`error_log` de `php.ini`, `wp-content/debug.log` o `ABSPATH/error_log`); si no, el informe lo dice.
