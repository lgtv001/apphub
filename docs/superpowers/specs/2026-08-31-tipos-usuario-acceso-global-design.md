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

**Consecuencias aceptadas de este límite** (confirmadas por `architect` revisando
`CheckProyectoAccess`/`CheckRole`/`ProyectoController`, no descubiertas después):
- Sin `/admin/asignaciones`, no queda NINGUNA forma de crear una fila nueva en
  `usuarios_proyectos` — el producto "Proyectos" pasa a ser abrible únicamente por superusers
  (bypasean ambos middlewares por `rol_global`). La pestaña "Proyectos" del panel sigue
  existiendo y sigue permitiendo crear proyectos, pero ningún usuario no-superuser podrá abrirlos
  jamás. Aceptado: con 0 filas reales hoy, es una limitación sobre una feature ya inactiva.
- `usuarios_proyectos.tipo_id` (FK a `tipos_usuario`, `nullOnDelete`) queda sin ningún escritor
  (era el modal de Asignaciones) — no rompe nada, solo no se va a volver a poblar. Acoplamiento
  menor y ya existente entre los dos sistemas (borrar un tipo del nuevo modelo de permisos nulea
  esa columna vía `nullOnDelete`, comportamiento que ya tenía la tabla antes de este cambio).

## Modelo de datos

**Nuevas tablas:**

```
usuarios_tipos_usuario
  id, usuario_id (FK usuarios, onDelete cascade), tipo_usuario_id (FK tipos_usuario, onDelete cascade),
  timestamps
  unique(usuario_id, tipo_usuario_id)

tipo_usuario_aplicacion_secciones
  id, tipo_usuario_id (FK tipos_usuario, onDelete cascade),
  seccion_id (FK aplicaciones_secciones, onDelete cascade),
  nivel varchar con check ('ver'|'editar') default 'ver', timestamps
  unique(tipo_usuario_id, seccion_id)
```

**Cambio respecto a la versión anterior de este spec (revisión de `architect`, 2026-08-31):** se
quita la columna `aplicacion_id` denormalizada — la app de una sección se resuelve siempre vía
`seccion.aplicacion_id` (un solo camino, sin riesgo de que las dos columnas queden
desincronizadas). La tabla va a tener decenas de filas; el join extra es gratis a esa escala y
elimina una fuente de verdad duplicada (hallazgo `architect` #9).

`onDelete('cascade')` explícito en las 4 FKs nuevas (hallazgo #10): borrar un usuario o un tipo
limpia sus pivots solo. **`TipoUsuarioController::destroy()` gana una guarda:** si el tipo tiene
usuarios asignados (`$tipo->usuarios()->exists()`), devuelve 409 en vez de borrar en cascada
silenciosa — borrar un tipo hoy sería una revocación masiva sin confirmación explícita.

`tipos_usuario.nombre` suma un índice `unique` a nivel de columna (hoy solo lo valida el
controller) — la migración de datos hace `firstOrCreate` por nombre y necesita esa garantía
real (hallazgo #14).

**Tablas eliminadas** (con migración de datos antes de dropear, ver abajo):
- `usuarios_aplicaciones`
- `usuario_aplicacion_secciones`

**Tabla que NO se toca:** `usuarios_proyectos` (ver "Fuera de alcance").

## Migración de datos (no solo de schema)

**Corrección tras revisión de `architect`:** producción corre **Postgres**, no MySQL (verificado
por SSH contra el `.env` real del contenedor, `DB_CONNECTION=pgsql` — el `.env.example` del repo
dice `mysql` y está desactualizado/engañoso; de paso se encontró que
`SolicitudController::index()` usa `orderByRaw("FIELD(...))")`, sintaxis exclusiva de MySQL, que
probablemente esté rota contra Postgres en producción hoy — bug preexistente, fuera de alcance de
este spec, anotado para arreglar aparte). A diferencia de MySQL, Postgres soporta DDL
transaccional: Laravel envuelve cada archivo de migración en una transacción real cuando el
grammar lo soporta, así que copiar datos + dropear las tablas viejas **sí puede ir en un solo
archivo de migración con atomicidad real** — si algo falla a mitad, Postgres hace rollback
completo y la migración no queda registrada como corrida. No hace falta partirlo en
expand/contract de 2 deploys.

Al día de la decisión, producción tiene exactamente 1 usuario, 1 fila en `usuarios_aplicaciones` y
3 filas en `usuario_aplicacion_secciones` (tu propio acceso a kpis-sso: metricas/historial/cargar).
La migración de Laravel que dropea las tablas viejas debe, en su `up()`, ANTES de dropear:

1. **Pre-flight, aborta la migración si falla:** por cada fila de `usuarios_aplicaciones`, debe
   existir al menos una fila correspondiente en `usuario_aplicacion_secciones` para el mismo
   `usuario_id`+`aplicacion_id`. Si no, la migración lanza una excepción y no continúa —
   `usuarios_aplicaciones` permite hoy dar acceso a una app SIN secciones (el launcher muestra la
   tarjeta igual), un caso que el modelo nuevo no puede representar (acceso deriva de secciones).
   Con los datos reales de hoy este pre-flight pasa limpio; existe para no migrar en silencio a un
   estado con MENOS acceso si algún día aparece ese caso (hallazgo `architect` #8).
2. **Un `TipoUsuario` por usuario, nunca uno compartido:** por cada `usuario_id` distinto en
   `usuarios_aplicaciones`, crear (`firstOrCreate` por `nombre`) un tipo
   `"Acceso SSO (migrado) — {email}"`. Con 1 solo usuario hoy es inofensivo cualquier enfoque,
   pero un tipo compartido entre 2+ usuarios mezclaría sus permisos (unión de accesos entre
   usuarios que no la tenían) — hallazgo `architect` #7, se corrige de raíz con un tipo por
   usuario.
3. Copiar cada fila de `usuario_aplicacion_secciones` de ese usuario a
   `tipo_usuario_aplicacion_secciones` con el `tipo_usuario_id` de su tipo migrado (mismo
   `seccion_id`/`nivel`).
4. Asignar ese tipo a su usuario (`usuarios_tipos_usuario`).
5. Recién ahí dropear `usuarios_aplicaciones` y `usuario_aplicacion_secciones`.

**Idempotencia explícita** (hallazgo #13): pasos 2-4 usan `firstOrCreate`/`updateOrInsert` contra
las claves únicas correspondientes, nunca `create()`/`insert()` puros — reintentar la migración
después de un fallo (poco probable dado que Postgres hace rollback, pero por las dudas si se
corre `migrate` dos veces en escenarios de test) no duplica nada. La migración `down()` es
best-effort (no reconstruye el estado exacto pre-migración) porque revertirla en producción no es
un escenario esperado.

**Verificación post-deploy obligatoria:** confirmar por SSH que el usuario migrado puede loguearse
en kpis-sso de punta a punta (el handoff firmado de `LauncherController::entrar`) antes de dar la
tarea por cerrada. Dato que baja la ceremonia necesaria: el panel de superuser se autoriza por
`rol_global`, no por tipos — aunque algo saliera mal con la migración de datos, no hay riesgo de
lockout del panel, el peor caso es "reasignar el tipo a mano desde la UI", no "quedar afuera de
todo" (hallazgo `architect`, mitigante).

**Endurecimiento aparte, de bajo costo:** `backend/start.sh` corre `php artisan migrate --force`
sin `set -e` — si la migración fallara, el script sigue igual a `db:seed`/arranque de PHP,
sirviendo tráfico con el fallo silenciado. Agregar `set -e` (o chequear el exit code de
`migrate`) antes de este deploy, aunque el riesgo específico de esta migración ya esté cubierto
por la atomicidad de Postgres (hallazgo `architect` #2).

## Backend

- `Usuario`:
  - Se quitan `aplicaciones()` y `seccionesAplicaciones()`.
  - Nuevo `tiposUsuario()`: `belongsToMany(TipoUsuario::class, 'usuarios_tipos_usuario', ...)`.
  - `seccionesDeAplicacion(string $codigoApp): array` se reescribe como **un solo query con
    joins**, no "recorrer los tipos en PHP" (eso sería N+1 — hallazgo `architect` #6):
    `usuarios_tipos_usuario` → `tipos_usuario` (filtrando `tipos_usuario.activo = true`, calificado
    con el nombre de tabla porque hay joins encima) → `tipo_usuario_aplicacion_secciones` →
    `aplicaciones_secciones` → `aplicaciones_externas` (filtrando por `codigo`), trayendo
    `aplicaciones_secciones.codigo` + `nivel`. **La regla "gana editar" se resuelve explícito, NUNCA
    con `MAX(nivel)` sobre el enum/texto:** se probó que tanto en Postgres como en SQLite el enum
    se guarda como texto plano y ordena alfabéticamente (`'editar' < 'ver'`), así que `MAX()`
    devolvería `'ver'` — exactamente al revés de la regla (hallazgo `architect` #5, confirmado
    real en el motor de producción). Se resuelve con un `CASE WHEN nivel = 'editar' THEN 1 ELSE 0
    END` en el `ORDER BY`/agregación, o agrupando en PHP con una comparación explícita
    `'editar' > 'ver'` definida a mano — nunca dejarlo al orden natural del string. Firma pública
    sin cambios (lo sigue llamando `LauncherController` igual que hoy).
  - Nuevo `tieneAccesoA(string $codigoApp): bool` y `codigosDeAplicacionesConAcceso(): array[string]`
    (reemplazan el uso que `LauncherController` le daba a `aplicaciones()`) — misma query base,
    sin el filtro por código y con `distinct` sobre `aplicaciones_externas.codigo` para el segundo.
- `TipoUsuario`:
  - Nuevo `usuarios()`: inverso de `Usuario::tiposUsuario()`.
  - Nuevo `secciones()`: `belongsToMany(AplicacionSeccion::class, 'tipo_usuario_aplicacion_secciones', ...)->withPivot('nivel')` (sin `aplicacion_id` en el pivot, ver "Modelo de datos").
- `TipoUsuarioController`:
  - `index()` incluye `secciones.aplicacion` eager-loaded.
  - `store()`/`update()` aceptan `secciones: [{seccion_id, nivel}]` — validación:
    `secciones` array, `secciones.*.seccion_id` `integer|distinct|exists:aplicaciones_secciones,id`,
    `secciones.*.nivel` `required|in:ver,editar`. **`update()` sincroniza secciones SOLO si la
    clave `secciones` viene en el request** (`$request->has('secciones')`) — si no, deja las
    existentes intactas. Sin esto, editar el `nombre` o `activo` de un tipo desde el modal (que
    hoy manda payloads parciales) borraría en silencio todo lo que ese tipo otorgaba a todos sus
    usuarios (hallazgo `architect` #3, el más grave de los de severidad media-alta).
  - `destroy()` gana la guarda de "Modelo de datos" (409 si tiene usuarios asignados).
  - Cada `store()`/`update()`/`destroy()` que toque `secciones` debe loguear a
    `usuarios_aplicaciones_log` (se mantiene esa tabla, ver "Fuera de alcance" — es el audit trail
    de cambios de acceso, hoy lo escribía `AccesoAplicacionController` y no puede perderse:
    hallazgo `architect` #4).
- `UsuarioController` (Admin): `store()`/`update()` aceptan `tipos: [tipo_id, ...]` —
  `tipos` array, `tipos.*` `integer|distinct|exists:tipos_usuario,id`. **Mismo cuidado que arriba:
  `sync()` solo si `$request->has('tipos')`.** `index()` incluye `tiposUsuario:id,nombre`
  eager-loaded. El `sync()` de tipos también loguea a `usuarios_aplicaciones_log`.
- `SolicitudController::approve()`: la validación cambia de `aplicaciones[].secciones[]` a
  `tipos: [tipo_id, ...]` (`integer|distinct|exists:tipos_usuario,id`, decisión: no se exige que
  estén `activo` — un tipo inactivo asignado simplemente no aporta acceso hasta reactivarse); al
  crear el usuario, se le hace `sync()` de esos tipos en vez de crear grants directos. El
  `$validator->after()` de pertenencia sección↔app desaparece (ya no aplica, `tipos` no referencia
  secciones sueltas).
- **Se eliminan por completo:** `AccesoAplicacionController.php`, `AsignacionController.php`, los
  modelos `UsuarioAplicacion.php`/`UsuarioAplicacionSeccion.php`, el método
  `AplicacionExterna::usuarios()` (`belongsToMany` sobre la tabla dropeada, huérfano si no se
  quita — hallazgo `architect` #12), y las 6 rutas correspondientes en `routes/api.php`
  (`/admin/accesos-aplicacion*`, `/admin/asignaciones*`).
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

- **Hecho:** agente `architect` revisó este spec contra el código real antes del plan de
  implementación — 16 hallazgos, todos incorporados arriba (schema sin `aplicacion_id`
  denormalizado, `sync()` condicional, regla "gana editar" sin `MAX()`, un tipo por usuario en la
  migración, audit trail preservado, inventario completo de tests, `onDelete`/guarda de
  `destroy()`, corrección Postgres vs. MySQL).
- **Pendiente:** después de implementar, antes de desplegar a producción, `code-reviewer` +
  `security-reviewer` en paralelo sobre todo el diff — `AccesoAplicacionController`/
  `AsignacionController` manejaban permisos directamente, así que la revisión de seguridad no es
  opcional acá.
- **Pendiente:** verificación manual antes de dar por cerrado — el login real a kpis-sso (el
  handoff firmado de `LauncherController::entrar`) tiene que seguir funcionando de punta a punta
  después de la migración de datos, es el único camino de producción que depende de
  `seccionesDeAplicacion()`.

## Testing

**Inventario completo de tests afectados** (corregido tras revisión de `architect` — la versión
anterior de este spec solo nombraba 2 de los archivos reales):

- `AccesoAplicacionControllerTest.php`: se reescribe como tests de `TipoUsuarioController` (crear/
  editar tipo con secciones, validación de pertenencia, el 409 de `destroy()` con usuarios
  asignados, y que `update()` sin la clave `secciones` no borra nada).
- `AsignacionControllerTest.php` (si existe como archivo separado) y **los 4 tests de
  `AdminTest.php`** que pegan a `/api/admin/asignaciones*`
  (`test_superuser_puede_asignar_usuario_a_proyecto`, `test_asignacion_duplicada_falla`,
  `test_superuser_puede_revocar_asignacion`, `test_superuser_puede_listar_asignaciones`): se
  eliminan, las rutas ya no existen. `UsuarioProyectoFactory` puede quedar sin usos reales tras
  esto — no se borra (fuera de alcance), es inofensivo.
- `LauncherControllerTest.php`: tiene 6 usos de `aplicaciones()->attach(...)` que dejan de
  compilar/pasar — se reescriben para otorgar acceso vía un `TipoUsuario` de prueba en vez de
  `attach()` directo. Incluye casos que dan acceso a una app SIN secciones (comportamiento que
  este cambio retira a propósito, ver "Migración de datos") — esos casos de test se actualizan
  para reflejar la nueva semántica, no se preservan tal cual.
- `SolicitudControllerTest.php`: usa `UsuarioAplicacion` y asserta sobre `usuarios_aplicaciones`/
  `usuarios_aplicaciones_log` — se reescribe para el nuevo payload `tipos: [...]` y sigue
  aserteando contra `usuarios_aplicaciones_log` (esa tabla no se dropea, ver abajo).
- `AplicacionesModeloTest.php` se actualiza a los modelos nuevos (`TipoUsuario::secciones()`,
  `Usuario::tiposUsuario()`), y agrega el caso de `AplicacionExterna::usuarios()` ya no existiendo.
- Nuevos tests: `Usuario::seccionesDeAplicacion()` con 2 tipos en conflicto (confirma que gana
  `editar`, ejercitando el motor real de test — Postgres o el que corran los tests, no asumir que
  SQLite alcanza dado el hallazgo de `MAX()` sobre enums), con un tipo `activo=false` (confirma
  que no aporta), `tieneAccesoA()`/`codigosDeAplicacionesConAcceso()`.
- Test de la migración de datos: sembrar el estado viejo (usuario + grant directo sin y con
  secciones), correr la migración, confirmar que el pre-flight aborta ante un grant sin secciones,
  y que con datos válidos el usuario termina con su propio tipo migrado (no uno compartido si hay
  2+ usuarios en el seed de prueba) y el mismo acceso efectivo que tenía antes.
- **No se dropean las tablas `*_log`** (`usuarios_aplicaciones_log` incluida) — `LogController`
  tiene un `UNION ALL` fijo sobre ellas (`LogController::TABLAS`); borrarlas rompe la pestaña
  "Logs" con un error SQL. Se reusa `usuarios_aplicaciones_log` como el audit trail de "cambios de
  acceso" del nuevo sistema (tipos y secciones), sin crear una tabla `tipos_usuario_log` nueva.
- `AsignacionController.php`/`UsuarioProyecto` (el modelo, no el controller que sí se borra): las
  demás rutas/tests de `usuarios_proyectos` fuera de Asignaciones (si los hay) no se tocan.
