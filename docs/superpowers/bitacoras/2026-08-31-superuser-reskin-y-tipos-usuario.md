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

## Estado al cerrar esta sesión

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

## Por qué importa para retomar

Si se retoma esta sesión más adelante: leer primero el spec completo (ya tiene los 16 hallazgos
incorporados, no hace falta re-correr `architect` sobre el mismo diseño salvo que cambie algo
material). El dato más importante para no repetir investigación: **Proyectos/Asignaciones tiene 0
filas reales, es seguro no tocarlo de raíz pero no hay que borrar `usuarios_proyectos`** — y
**producción es Postgres, no confiar en `.env.example` del repo para saber el motor real.**
