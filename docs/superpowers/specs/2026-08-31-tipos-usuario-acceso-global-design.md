# Acceso a aplicaciones por Tipo de Usuario (reemplaza el grant directo por usuario)

**Fecha:** 2026-08-31
**Estado:** Aprobado en brainstorming, pendiente de plan de implementación.
**Contexto:** sigue directo a `2026-08-07-sso-gateway-aplicaciones-externas-design.md` (el modelo
`AplicacionExterna`/`AplicacionSeccion`/`UsuarioAplicacion`/`UsuarioAplicacionSeccion` que ese spec
introdujo). Motivado por feedback directo del usuario tras usar el panel de superuser reskineado
el mismo día: "un usuario puede tener muchos tipos de usuario asignados, pero cada tipo de usuario
puede tener acceso a muchas utilidades de las apps, no debe ocurrir incongruencia o redundancias".

## Problema

Hoy existen DOS mecanismos independientes para decidir "¿puede este usuario ver la sección X de la
app Y?":
1. Grant directo por usuario (`usuarios_aplicaciones` + `usuario_aplicacion_secciones`), gestionado
   desde la pestaña "Aplicaciones" del panel superuser.
2. `TipoUsuario` existe como entidad pero hoy es puramente un label (`nombre`/`descripcion`/
   `activo`) sin ningún efecto sobre permisos — solo se adjunta como metadata opcional a una fila
   de `usuarios_proyectos` (que a su vez pertenece a un producto completamente distinto de apphub,
   el tracker de proyectos de construcción con 0 filas reales en producción).

Mantener dos mecanismos de grant en paralelo (usuario directo + tipo) es exactamente la
"incongruencia o redundancia" que el usuario pidió evitar: no hay una regla de qué gana si un
usuario tiene grant directo Y un tipo con un nivel distinto para la misma sección, y cualquier
cambio futuro tendría que actualizarse en dos lugares.

## Decisión

Un solo mecanismo: **el acceso a aplicaciones/secciones se otorga exclusivamente a través de
`TipoUsuario`**, nunca directo a un usuario. Un usuario obtiene su acceso efectivo como la unión de
lo que le dan TODOS los tipos que tiene asignados.

- `Usuario` ⟷ `TipoUsuario`: muchos-a-muchos (un usuario puede tener varios tipos).
- `TipoUsuario` ⟷ `AplicacionSeccion`: muchos-a-muchos con un `nivel` (`ver`|`editar`) por par.
- Regla de conflicto: si dos tipos del mismo usuario otorgan la misma sección con nivel distinto,
  **gana `editar`** (el nivel más permisivo) — unión de permisos, nunca intersección.
- Solo cuentan los tipos con `activo = true`; un tipo desactivado deja de aportar permisos a nadie
  sin necesidad de tocar las asignaciones usuario↔tipo.
- "¿Tiene acceso a la app X en absoluto?" (lo que necesita el launcher para decidir qué tarjetas
  mostrar) se **deriva**, no se guarda: es verdad si el usuario tiene al menos una sección
  efectiva cuya `aplicacion_id` sea X. Esto elimina la tabla `usuarios_aplicaciones` — hoy
  solo era necesaria porque el modelo antiguo separaba "acceso a la app" de "acceso a secciones
  específicas"; con tipos como fuente única, ambas preguntas se resuelven con la misma tabla.

## Fuera de alcance (explícitamente)

- El producto de "Proyectos" de apphub (`Proyecto`/`Area`/`Sistema`/`Subsistema`/`Quiebre`,
  gateado por `CheckProyectoAccess`/`CheckRole` vía `usuarios_proyectos`) **no se toca**. Tiene 0
  filas reales en producción (confirmado por consulta directa el 2026-08-31) pero sigue siendo un
  producto separado con código vivo — borrar `usuarios_proyectos` rompería sus middlewares. Se
  elimina únicamente la pestaña "Asignaciones" del panel superuser y su controller
  (`AsignacionController` + rutas `/admin/asignaciones*`), que hoy es la única forma de CREAR una
  fila nueva en `usuarios_proyectos` — como ya tiene 0 filas, no se pierde ninguna asignación real.
- No se agrega un rol/tipo especial para "Proyectos" ni se conecta ese producto al nuevo modelo de
  tipos — son dos sistemas de permisos completamente separados, a propósito.

## Modelo de datos

**Nuevas tablas:**

```
usuarios_tipos_usuario
  id, usuario_id (FK usuarios), tipo_usuario_id (FK tipos_usuario), timestamps
  unique(usuario_id, tipo_usuario_id)

tipo_usuario_aplicacion_secciones
  id, tipo_usuario_id (FK tipos_usuario), aplicacion_id (FK aplicaciones_externas),
  seccion_id (FK aplicaciones_secciones), nivel enum('ver','editar') default 'ver', timestamps
  unique(tipo_usuario_id, seccion_id)
```

Mismo shape que la `usuario_aplicacion_secciones` actual, solo cambia la columna dueña
(`tipo_usuario_id` en vez de `usuario_id`). `aplicacion_id` se mantiene denormalizado en la fila
(en vez de resolverse siempre vía `seccion.aplicacion_id`) por el mismo motivo que ya tenía el
modelo viejo: simplifica los queries de listado sin un join extra.

**Tablas eliminadas** (con migración de datos antes de dropear, ver abajo):
- `usuarios_aplicaciones`
- `usuario_aplicacion_secciones`

**Tabla que NO se toca:** `usuarios_proyectos` (ver "Fuera de alcance").

## Migración de datos (no solo de schema)

Al día de la decisión, producción tiene exactamente 1 usuario, 1 fila en `usuarios_aplicaciones` y
N filas en `usuario_aplicacion_secciones` (tu propio acceso a kpis-sso). La migración de Laravel
que dropea las tablas viejas debe, en su `up()`, ANTES de dropear:

1. Crear un `TipoUsuario` nuevo si no existe uno con `nombre = 'Acceso SSO (migrado)'`.
2. Copiar cada fila de `usuario_aplicacion_secciones` a `tipo_usuario_aplicacion_secciones` con
   ese `tipo_usuario_id` (mismo `aplicacion_id`/`seccion_id`/`nivel`).
3. Asignar ese tipo a cada `usuario_id` distinto que tuviera filas en `usuarios_aplicaciones`
   (insertar en `usuarios_tipos_usuario`).
4. Recién ahí dropear `usuarios_aplicaciones` y `usuario_aplicacion_secciones`.

Esto es idempotente y seguro de correr contra los datos reales de producción: nadie pierde acceso
a mitad del deploy. La migración `down()` es best-effort (no intenta reconstruir el estado
exacto pre-migración de las tablas viejas) porque revertir esta migración en producción no es un
escenario esperado.

## Backend

- `Usuario`:
  - Se quitan `aplicaciones()` y `seccionesAplicaciones()`.
  - Nuevo `tiposUsuario()`: `belongsToMany(TipoUsuario::class, 'usuarios_tipos_usuario', ...)`.
  - `seccionesDeAplicacion(string $codigoApp): array` se reescribe: recorre
    `tiposUsuario()->where('activo', true)`, junta sus secciones de esa app, agrupa por
    `seccion_id` quedándose con `editar` si hay conflicto. Firma pública sin cambios (lo sigue
    llamando `LauncherController` igual que hoy).
  - Nuevo `tieneAccesoA(string $codigoApp): bool` y `codigosDeAplicacionesConAcceso(): array[string]`
    (reemplazan el uso que `LauncherController` le daba a `aplicaciones()`).
- `TipoUsuario`:
  - Nuevo `usuarios()`: inverso de `Usuario::tiposUsuario()`.
  - Nuevo `secciones()`: `belongsToMany(AplicacionSeccion::class, 'tipo_usuario_aplicacion_secciones', ...)->withPivot('aplicacion_id', 'nivel')`.
- `TipoUsuarioController`:
  - `index()` incluye `secciones.aplicacion` eager-loaded.
  - `store()`/`update()` aceptan `secciones: [{seccion_id, nivel}]`, con la misma validación que
    hoy tiene `AccesoAplicacionController::store()` (la sección debe pertenecer a una aplicación
    real — no hace falta repetir `aplicacion_id` en el payload, se puede resolver desde
    `seccion_id` server-side, a diferencia del modelo viejo donde el frontend lo mandaba porque
    hacía falta para la validación de pertenencia; acá se puede validar igual sin pedírselo al
    cliente).
- `UsuarioController` (Admin): `store()`/`update()` aceptan `tipos: [tipo_id, ...]`, sincroniza
  `usuario->tiposUsuario()->sync(...)`. `index()` incluye `tiposUsuario:id,nombre` eager-loaded.
- `SolicitudController::approve()`: la validación cambia de `aplicaciones[].secciones[]` a
  `tipos: [tipo_id, ...]`; al crear el usuario, se le hace `sync()` de esos tipos en vez de crear
  grants directos.
- **Se eliminan por completo:** `AccesoAplicacionController.php`, `AsignacionController.php`, los
  modelos `UsuarioAplicacion.php`/`UsuarioAplicacionSeccion.php`, y las 6 rutas correspondientes en
  `routes/api.php` (`/admin/accesos-aplicacion*`, `/admin/asignaciones*`).
- `LauncherController::index()`/`entrar()`: cambian `$usuario->aplicaciones()->pluck('codigo')` →
  `$usuario->codigosDeAplicacionesConAcceso()`, y
  `$usuario->aplicaciones()->where(...)->exists()` → `$usuario->tieneAccesoA($app->codigo)`.

## Frontend (`superuser.html`)

- Sidebar: se quitan los ítems "Asignaciones" y "Aplicaciones" (quedan 5: Proyectos, Usuarios,
  Tipos de Usuario, Solicitudes, Logs).
- "Tipos de Usuario": el modal de crear/editar tipo suma el picker de secciones+nivel que hoy
  vive en el modal de "Aplicaciones" (checkbox por sección + select ver/editar, agrupado por
  aplicación ya que un tipo puede dar acceso a secciones de más de una app). La tabla suma una
  columna "Acceso" listando `app.seccion:nivel` de cada tipo (mismo formato que mostraba la
  columna "Secciones" de la vieja pestaña Aplicaciones).
- "Usuarios": el modal de crear/editar usuario suma un multi-select (checkboxes) de tipos activos.
- "Solicitudes": el modal de aprobar cambia el picker de secciones sueltas por un multi-select de
  tipos activos — mismo patrón que el modal de Usuarios.
- Ningún cambio de paleta/layout — esto se construye sobre el reskin ya desplegado el mismo día.

## Verificación

- Antes de escribir el plan de implementación: agente `architect` revisa este spec contra el
  código real (schema, la regla de "gana editar", seguridad del paso de migración de datos).
- Después de implementar, antes de desplegar a producción: `code-reviewer` + `security-reviewer`
  en paralelo sobre todo el diff — `AccesoAplicacionController`/`AsignacionController` manejaban
  permisos directamente, así que la revisión de seguridad no es opcional acá.
- Verificación manual obligatoria antes de dar por cerrado: el login real a kpis-sso (el handoff
  firmado de `LauncherController::entrar`) tiene que seguir funcionando de punta a punta después
  de la migración de datos — es el único camino de producción que depende de
  `seccionesDeAplicacion()`.

## Testing

- Reescribir `AccesoAplicacionControllerTest.php` como tests de `TipoUsuarioController` (crear/
  editar tipo con secciones, validación de pertenencia).
- Nuevos tests: `Usuario::seccionesDeAplicacion()` con 2 tipos en conflicto (confirma que gana
  `editar`), con un tipo `activo=false` (confirma que no aporta), `tieneAccesoA()`/
  `codigosDeAplicacionesConAcceso()`.
- `AplicacionesModeloTest.php` se actualiza a los modelos nuevos (`TipoUsuario::secciones()`,
  `Usuario::tiposUsuario()`).
- Test de la migración de datos: sembrar el estado viejo (usuario + grant directo), correr la
  migración, confirmar que el usuario termina con el tipo migrado y el mismo acceso efectivo que
  tenía antes.
- `AsignacionController`/`UsuarioProyecto` no se tocan — sus tests existentes (si los hay) quedan
  intactos.
