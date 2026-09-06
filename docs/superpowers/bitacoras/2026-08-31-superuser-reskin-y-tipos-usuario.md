# Bitácora — Reskin de superuser.html + rediseño de acceso por Tipo de Usuario

**Fecha:** 2026-08-31
**Spec relacionado:** `docs/superpowers/specs/2026-08-31-tipos-usuario-acceso-global-design.md`
**Spec anterior del que parte esto:** `docs/superpowers/specs/2026-08-07-sso-gateway-aplicaciones-externas-design.md`

## Qué se pidió

Dos pedidos en la misma sesión, uno llevó al otro:

1. "Mejorar el módulo de superusuario, está feo actualmente, debe seguir la misma estética actual
   de KPI-SSO" — reskin visual de `superuser.html` (apphub), sin tocar backend.
2. Después de ver el reskin en vivo: "elimina el de asignaciones y en tipo de usuario usalo para
   asignar aplicaciones ya cceso de forma global, y un usuario puede tener muchos tipos de usuarios
   asignados, pero cada tipo de usuario puede tener acceso a muchas utilidades de las apps, no debe
   ocurrir incongruencia o redundancias que arruinen el ecosistema, segundo, usa agentes que
   verifiquen esto."

## Parte 1 — Reskin visual (COMPLETO y desplegado)

`superuser.html` usaba el look propio y viejo de apphub (navbar azul/rojo, tabs, tabla genérica),
nunca había pasado por los rediseños que sí tuvo kpis-sso (sidebar fijo, paleta "sala de control",
tipografía IBM Plex Sans Condensed + DM Sans + IBM Plex Mono, tema claro/oscuro validado).

**Decisión de implementación:** en vez de tocar `assets/css/app.css` (compartido por login/
launcher/selector-proyecto/etc.), se sobreescribieron los MISMOS nombres de variable que ya usaba
la página (`--blue`, `--red`, `--bg-card`, `--text-muted`...) con los valores de la paleta de
kpis-sso, en un `<style>` local que carga después de `app.css` y gana por orden — cero riesgo para
el resto de apphub. Se agregaron alias con los nombres de kpis-sso (`--accent`, `--surface`,
`--muted`...) para poder copiar su CSS de sidebar casi textual.

Layout: navbar+tabs → sidebar de 220px colapsable, mismo mecanismo de `localStorage` que kpis-sso
(clave propia `apphub_su_sidebar_collapsed`, sin colisión posible con la de kpis-sso — son
orígenes distintos igual). Los 7 tabs pasaron a ser ítems de sidebar sin tocar la lógica JS de
mostrar/ocultar panel (`showPanel` original), solo el selector de qué botón dispara el toggle.

**Desplegado** el mismo día vía `docker cp` + `docker restart` del contenedor `apphub` (sin bind
mount, el archivo vive horneado en la imagen — mismo patrón ya usado con kpis-sso). Verificado con
`grep` que el archivo nuevo quedó adentro y el contenedor respondía 200 antes de pedir
confirmación visual al usuario.

## Parte 2 — De "reskin" a rediseño de arquitectura de permisos

Al pedir el cambio de Asignaciones/Tipos de Usuario, la investigación del código real (no
asumida) mostró que el pedido tocaba mucho más que la pestaña visible:

- **Hallazgo 1:** ya existía TODO el backend para "asignar acceso a módulos con nivel ver/editar"
  desde el 9-ago (`AplicacionExterna`/`AplicacionSeccion`/`UsuarioAplicacion`/
  `UsuarioAplicacionSeccion` + `AccesoAplicacionController`) — el pedido del usuario no era una
  feature nueva, era reemplazar el MECANISMO de grant (directo por usuario → por tipo).
- **Hallazgo 2:** `TipoUsuario` existía pero como label puro, sin ningún efecto en permisos —
  colgaba de `usuarios_proyectos` (Usuario+Proyecto+Rol+Tipo), que es el modelo de OTRO producto
  de apphub completamente distinto (tracker de proyectos de construcción: Área/Sistema/Subsistema/
  Quiebre), gateado por `CheckProyectoAccess`/`CheckRole`.
- **Hallazgo 3 (el que cambió el plan):** `usuarios_proyectos`/`proyectos` tienen **0 filas reales
  en producción** (confirmado por `php artisan tinker` contra el server) — ese otro producto nunca
  se usó de verdad. Esto hizo seguro borrar la pestaña "Asignaciones", pero **no** borrar la tabla
  completa: eso rompería middlewares de un producto separado con código vivo, aunque sin datos.

**Decisiones tomadas con el usuario (todas confirmadas explícitamente, no asumidas):**
- Se retira también la pestaña "Aplicaciones" (grant directo) — el usuario mismo señaló que
  mantener las dos formas de dar acceso en paralelo era la "incongruencia" que quería evitar.
- Los tipos de un usuario se asignan desde el modal de editar Usuario (multi-select), no en una
  pantalla aparte.
- Conflicto entre tipos con nivel distinto en la misma sección: gana el más permisivo (`editar`).
- De "Asignaciones" se borra `AsignacionController` + rutas + la pestaña — la tabla
  `usuarios_proyectos` y todo lo demás del producto de Proyectos queda intacto y dormido.

## Verificación con `architect` (pedido explícito del usuario: "usa agentes que verifiquen esto")

Se escribió el spec completo y se corrió el agente `architect` contra el spec + el código real
ANTES de pasar a plan de implementación. Encontró **16 hallazgos**, varios de severidad alta,
sobre un diseño conceptual que confirmó correcto:

- **Corrección de contexto grande:** producción corre **Postgres**, no MySQL como asumía el
  `.env.example` del repo (desactualizado) — esto cambió el análisis de atomicidad de la
  migración de datos (Postgres sí puede hacerla atómica en un solo paso; MySQL no). De paso salió
  a la luz que `SolicitudController::index()` usa sintaxis exclusiva de MySQL
  (`orderByRaw("FIELD(...)"))`) que probablemente esté rota en producción ahora mismo — bug
  preexistente, anotado aparte, fuera de alcance de este cambio.
- **Bug real que el spec original habría introducido:** un `sync()` de tipos/secciones sin
  chequear si la clave viene en el payload habría borrado todo el acceso de un usuario/tipo al
  editar cualquier otro campo suyo (ej. togglear `activo`).
- **Bug real #2:** la regla "gana editar" resuelta con `MAX(nivel)` sobre el enum da el resultado
  CONTRARIO al pretendido — el enum se guarda como texto y ordena alfabético (`'editar' < 'ver'`),
  confirmado tanto en Postgres como en SQLite.
- Migrar el único grant real de hoy a un tipo COMPARTIDO (en vez de uno por usuario) habría sido
  correcto con 1 solo usuario pero mezclaría permisos entre usuarios en cualquier otro escenario.
- Se habría perdido el audit log de cambios de acceso (pestaña "Logs") si los controllers nuevos
  no seguían escribiendo a `usuarios_aplicaciones_log`.
- Inventario de qué se rompe estaba incompleto: faltaban 4 tests de Asignaciones en
  `AdminTest.php`, más `LauncherControllerTest`/`SolicitudControllerTest` completos, y el método
  huérfano `AplicacionExterna::usuarios()`.

Todos los hallazgos se incorporaron al spec (commit `fe1b158`) antes de seguir. El diseño de fondo
(tipos como única fuente de verdad, unión de permisos, "gana editar", `activo` como kill-switch)
no cambió — lo que cambió fueron los detalles de CÓMO ejecutarlo sin romper nada.

## Estado al cerrar esta sesión (2026-08-31)

- Reskin: **desplegado en producción**, pendiente de que el usuario lo pruebe a fondo en el
  navegador (temas, las 6 secciones, el modal de Aplicaciones) y confirme antes de dar por
  cerrado del todo — el archivo local todavía no estaba commiteado al momento de pasar al punto 2
  (se va a reescribir de nuevo con el trabajo de tipos, así que se commitea todo junto al final).
- Spec de tipos de usuario: **aprobado y completo**, con la revisión de `architect` ya
  incorporada. Commiteado en `docs/superpowers/specs/2026-08-31-tipos-usuario-acceso-global-design.md`.
- **Siguiente paso pendiente:** invocar `writing-plans` sobre este spec para armar el plan de
  implementación (migraciones, modelos, controllers, frontend, tests) — no arrancado todavía.
- Después de implementar: pendiente correr `code-reviewer` + `security-reviewer` en paralelo
  sobre el diff completo (pedido explícito del usuario), y verificación manual de que el login a
  kpis-sso sigue funcionando de punta a punta tras la migración de datos.

## Sesión 2026-09-01 (no documentada acá en su momento, reconstruida el 2026-09-04)

Plan de 13 tareas escrito (`docs/superpowers/plans/2026-09-01-tipos-usuario-acceso-global.md`) y
ejecutado con `subagent-driven-development` sobre worktree nativo `apphub/.worktrees/tipos`
(branch `feat/tipos-usuario-acceso-global`). Las 12 tareas de código quedaron commiteadas y en
verde (127 tests, 349 assertions). La Tarea 13 (revisión final `code-reviewer`+`security-reviewer`
en paralelo, pedida por el spec) se dispatchó pero la sesión se cortó antes de registrar los
veredictos — quedó como trabajo invisible hasta que se retomó.

## Sesión 2026-09-04 — cierre real: revisión final, merge y deploy a producción

Retomada la Tarea 13. Ambos agentes, corridos de nuevo sobre el diff completo (`2d07b8c..b01f873`,
generando el SQL real contra la grammar de Postgres del `vendor/` del repo, no de memoria),
coincidieron **de forma independiente** en:

1. **CRITICAL/HIGH:** la migración que ensancha `usuarios_aplicaciones_log.accion` usaba
   `->change()` sobre un enum, que en Postgres genera `ALTER COLUMN ... TYPE varchar(255)
   check (...)` — sintaxis inválida ahí (`CHECK` no es válido dentro de `ALTER COLUMN...TYPE`).
   Habría abortado `migrate --force` en el primer intento de deploy real. Los tests daban verde
   porque `phpunit.xml` fuerza SQLite, donde `->change()` sí funciona (reconstruye la tabla).
   **Arreglado** (commit `63323fd`): SQL crudo driver-aware, `DROP`/`ADD CONSTRAINT` en Postgres.
2. **MEDIUM (contradecía el spec):** el modal de editar Usuario en `superuser.html` borraba en
   silencio los Tipos de Usuario **inactivos** asignados a una persona al guardar cualquier otro
   cambio — el spec dice explícito que la asignación debe sobrevivir la desactivación. **Arreglado**
   (commit `1321375`): los tipos inactivos-pero-asignados se muestran marcados+disabled con
   "(inactivo)", visibles y no desmarcables por accidente.
3. Quedaron **deferred por decisión explícita del usuario** ("solo los 2 que bloquean/contradicen
   el spec"): `entidad_id` ambiguo en el log de auditoría (mezcla ids de tipo y de usuario sin
   discriminador), pre-flight de la migración de datos unidireccional (no chequea secciones
   huérfanas sin grant padre), TOCTOU en `destroy()`, nombre de constraint mal formado en `down()`,
   código muerto (`populateSelectFromCache`), y un XSS preexistente (no introducido por esta rama)
   en el renderer de solicitudes. Detalle completo de cada uno en
   `.superpowers/sdd/2026-09-01-tipos-usuario-acceso-global/progress.md`.

**Merge + deploy real, en ese orden:**
- `main` local mergeado (fast-forward, 15 commits) y pusheado a GitHub — de paso se encontró y
  corrigió que el reskin del 31-ago (`2d07b8c`) nunca se había pusheado, `origin/main` estaba 2
  commits atrás desde esa fecha.
- Deploy vía `scp` + `docker cp` de los archivos cambiados al contenedor `apphub` en vivo (sin
  bind mount, mismo patrón que el reskin), seguido de `docker restart` — que corrió
  `migrate --force` real contra el Postgres de producción. **Las 3 migraciones nuevas corrieron sin
  error**, confirmando en producción real que el fix del bug de Postgres funcionaba.
- Verificado por script PHP corrido dentro del contenedor (lectura, sin tocar datos):
  `luisgarnica@hotmail.cl` quedó con su propio tipo migrado ("Acceso SSO (migrado) —
  luisgarnica@hotmail.cl"), acceso a `kpis-sso` con las 3 secciones exactas que tenía antes
  (`metricas`/`historial`/`cargar`, todas en `editar`), y las tablas viejas
  (`usuarios_aplicaciones`/`usuario_aplicacion_secciones`) quedaron dropeadas.
- **Hallazgo fuera de alcance del spec, pedido corregir en la misma sesión:** el usuario notó que
  `https://apphub.lglabproyect.com` (raíz) servía una versión vieja/mockup de apphub —
  `public/index.html` con su propio formulario de login (que pegaba al mismo `/api/auth/login`
  real, generando un segundo frente de entrada confuso), `public/dashboard.html` con tarjetas
  casi todas muertas (`href="#"`, ya marcado antes como "maqueta vieja sin función" en un commit
  de agosto), y `public/informe-desarrollo.html` como reporte estático. Nada de eso estaba
  referenciado desde `/app/*.html` (verificado con grep antes de tocar). Reemplazados los 3 por un
  redirect simple a `/app/login.html`, la única puerta de entrada real. Commiteado (`600f2cb`),
  pusheado, y desplegado igual que el resto (archivos estáticos, no hizo falta reiniciar).

**Login real de punta a punta: CONFIRMADO por el usuario en el navegador** (mismo día) —
`https://apphub.lglabproyect.com/app/login.html` → tarjeta de kpis-sso → handoff, sin error
("login correcto"). **Task 13 del plan queda 100% cerrada** con esto — las 4 checkboxes del
Step final marcadas en `docs/superpowers/plans/2026-09-01-tipos-usuario-acceso-global.md`.

Del login real salieron 3 hallazgos más, todos arreglados y desplegados la misma sesión:

1. **El botón "AppHub interno" del launcher** era visible para CUALQUIER usuario logueado sin
   ningún gate de rol, y hasta ese momento no existía ningún link real al panel de superuser desde
   el launcher (se llegaba solo escribiendo la URL a mano). Reemplazado por "Panel de
   Superusuario" → `/app/superuser.html`, que **no existe en el HTML estático** (queda un `<span>`
   vacío) y se inserta por JS solo si `isSuperuser()` — un usuario común no lo ve ni lo puede
   descubrir viendo el código fuente. Commit `2382129`.
2. **Auditoría de HTTPS/TLS de todo `*.lglabproyect.com`**, a pedido del usuario ("¿mi app trabaja
   con https?"): certificados Universal+Backup de Cloudflare correctos y vigentes, pero
   **"Always Use HTTPS" estaba apagado** — `http://apphub.lglabproyect.com` respondía 200 en texto
   plano sin redirigir. El usuario lo activó desde el dashboard de Cloudflare, reverificado en vivo
   con curl: ahora responde `301` → HTTPS. Detalle completo en
   `Desktop\Server Aprendizaje\bitacora-fase8-monitoreo.md` (sección 2026-09-05) — no repetir esa
   auditoría si se retoma este tema, ya está cerrada.
3. (Ya documentado arriba pero vale repetirlo acá porque salió de la misma ronda de login real):
   la raíz del dominio servía un mockup obsoleto — resuelto con el redirect a `/app/login.html`.

## Único pendiente cosmético (2026-09-06)

Worktree `apphub/.worktrees/tipos` ya borrado del disco (`git worktree remove` + limpieza manual,
tardó por un lock transitorio de Windows, resuelto con `rm -rf` directo). **La rama local
`feat/tipos-usuario-acceso-global` sigue existiendo** (ya mergeada a `main` sin conflictos,
fast-forward) — el usuario pidió dejar la decisión de borrarla para más tarde. No bloquea nada,
es cero riesgo, solo prolijidad de repo. Si se retoma: `git branch -d feat/tipos-usuario-acceso-global`
desde la raíz del repo (no hace falta `-D`, ya está mergeada).

## Por qué importa para retomar

**Todo lo del spec de tipos de usuario está implementado, revisado, desplegado en producción Y
verificado end-to-end por el usuario.** No queda nada pendiente de este spec — si algo parece
pendiente, revisar primero `.superpowers/sdd/2026-09-01-tipos-usuario-acceso-global/progress.md`
y el Task 13 del plan antes de asumir que no se hizo. El dato más importante para no repetir
investigación: **Proyectos/Asignaciones tiene 0 filas reales, es seguro no tocarlo de raíz pero no
hay que borrar `usuarios_proyectos`** — y **producción es Postgres, no confiar en `.env.example`
del repo para saber el motor real** (y no confiar tampoco en que `->change()` de Laravel sobre un
enum funcione igual en Postgres que en SQLite — no funciona).
