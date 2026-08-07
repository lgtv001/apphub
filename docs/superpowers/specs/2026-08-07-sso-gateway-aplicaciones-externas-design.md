# Apphub como gateway SSO + selector de aplicaciones externas

**Fecha:** 2026-08-07
**Contexto:** el pedido original era "mejorar el frontend de kpis-sso, con una página para login,
otra para elegir a qué app entrar, y otra para ver los KPIs". Al explorar, `apphub` ya tenía
construido casi todo el modelo de permisos que hacía falta (`Usuario`, `TipoUsuario`, `Proyecto`,
`UsuarioProyecto`, `SolicitudAcceso`, roles `superuser`/`admin`) — pero scopeado a los dashboards
internos de apphub (áreas/sistemas/subsistemas/avance), no a lanzar aplicaciones externas
separadas. El alcance creció de "rediseñar una pantalla" a "apphub se convierte en el gateway de
identidad y permisos para todo el ecosistema de apps SSO, empezando por kpis-sso".

**Alcance de este spec:** apphub como gateway + kpis-sso como primera app integrada. Las otras 3
apps del ecosistema SSO ([[project_informe_sso_ejecutivo]], [[project_informe_tv]],
[[project_informe_incidentes_gerencia]]) quedan explícitamente fuera — mismo patrón, se replica
en specs separados cuando toque cada una.

## Decisiones de arquitectura (aprobadas en brainstorming)

1. **Login único en apphub, no en cada app.** `kpis-sso` deja de tener login propio — su tabla
   `usuarios`/`AuthController::login` actual (Sanctum, hoy solo la cuenta admin
   `luisgarnica@hotmail.cl`) se retira del flujo de autenticación. La tabla `usuarios` de apphub
   pasa a ser la única fuente de verdad de identidad para todo el ecosistema.
2. **Permisos por app, gestionados por el superusuario** — mismo patrón que ya existe para
   `Proyecto`/`UsuarioProyecto` en apphub, aplicado a un concepto nuevo: `AplicacionExterna` +
   `UsuarioAplicacion`. Un usuario solo ve, en el launcher, las apps para las que el superusuario
   le dio acceso.
3. **SSO real vía sesión compartida (Opción A de las dos evaluadas), no token firmado por app.**
   Las dos apps comparten `APP_KEY` + una tabla `sessions` única (vive en la Postgres de
   Supabase, la misma base única del homelab desde la Fase 7) + cookie con
   `Domain=.lglabproyect.com`. Trade-off aceptado explícitamente: compartir `APP_KEY` amplía el
   círculo de confianza entre las dos apps (cualquiera de las dos puede en teoría descifrar
   valores cifrados de la otra) — aceptable porque son apps 100% internas, del mismo dueño, sin
   terceros de por medio. La alternativa (token firmado, endpoint de verificación en cada app)
   quedó descartada por ahora: mucho más trabajo (código nuevo en las 4 apps futuras en vez de
   solo configuración) para un beneficio de aislamiento que no se necesita en este contexto.
4. **Sin acoplar las bases de datos de las dos apps.** `kpis-sso` nunca consulta directo la base
   de apphub para saber qué permisos tiene un usuario — apphub guarda `apps_permitidas` (los
   códigos de las apps que el usuario puede abrir) directo en la sesión al loguearse, y como las
   dos apps leen la MISMA sesión (mismo store, misma cookie), `kpis-sso` solo necesita leer esa
   clave de la sesión que ya tiene disponible. **Trade-off aceptado:** `apps_permitidas` se
   calcula una vez, al momento del login — si el superusuario revoca el acceso de alguien
   mientras esa persona ya tiene una sesión abierta, sigue teniendo acceso hasta que esa sesión
   termine (logout o expiración), no al instante. Para el volumen de usuarios/urgencia de este
   caso (equipo interno, no un sistema con revocación crítica en tiempo real) es aceptable;
   documentado como limitación conocida, no un bug a resolver en esta fase.
5. **El rediseño visual queda fuera de este spec.** Con login y selector de apps viviendo en
   apphub, lo único que le queda a `kpis-sso` para rediseñar visualmente es su dashboard — eso se
   trabaja en una sesión de implementación aparte, con la skill `ui-ux-pro-max` para elegir
   dirección visual concreta (paleta/tipografía/layout). Este spec es de arquitectura de acceso,
   no de diseño visual.

## Cambios en apphub

### Modelos y migraciones nuevas

- `AplicacionExterna` (tabla `aplicaciones_externas`): `codigo` (string, único, ej. `'kpis-sso'`),
  `nombre` (string, ej. `'KPIs SSO El Abra'`), `url_base` (string, ej.
  `'https://kpis-sso.lglabproyect.com'`), `activo` (boolean). Semilla inicial: una fila para
  `kpis-sso` (`activo: true`); filas para las otras 3 apps con `activo: false` (existen como
  registro, no aparecen en el launcher todavía — evita tener que migrar de nuevo cuando se
  integren).
- `UsuarioAplicacion` (tabla `usuarios_aplicaciones`): `usuario_id`, `aplicacion_id`. Más simple
  que `UsuarioProyecto` — no necesita `rol`/`tipo_id`, es un grant binario (tenés acceso o no).
- `Usuario::aplicaciones()` — `belongsToMany(AplicacionExterna::class, 'usuarios_aplicaciones')`.

### Endpoints nuevos (mismo patrón que `AsignacionController`)

- `Admin\AplicacionController` — CRUD de `aplicaciones_externas`, solo `superuser`.
- `Admin\AccesoAplicacionController` — otorgar/revocar acceso de un usuario a una app, solo
  `superuser` (equivalente a `AsignacionController` pero para apps en vez de proyectos).
- `LauncherController::index()` — devuelve las apps activas a las que el usuario autenticado
  tiene acceso (`Auth::user()->aplicaciones()->where('activo', true)->get()`).

### Sesión compartida

- `config/session.php`: `domain` → `.lglabproyect.com`, `secure` → `true`.
- `.env` de apphub: mismo `APP_KEY` que `kpis-sso` (rotar y sincronizar una vez, documentar cuál
  es el valor canónico); `SESSION_DRIVER=database` apuntando a una tabla `sessions` en la
  Postgres de Supabase, compartida entre las dos apps (no una tabla `sessions` por app).
- Al loguearse (`AuthController::login`), además de lo que ya hace, guardar en la sesión:
  ```php
  session(['apps_permitidas' => $usuario->aplicaciones()->where('activo', true)->pluck('codigo')]);
  ```

### Launcher (frontend de apphub)

Página nueva (o sección de `dashboard.html`) que lista las apps de `apps_permitidas` como
tarjetas — clic en una redirige a su `url_base`. Sin necesidad de pasar ningún token en la URL:
al llegar a `kpis-sso`, el navegador ya manda la cookie de sesión compartida.

## Cambios en kpis-sso

### Retirar el login propio

- `public/index.html` — deja de ser un formulario de login. Pasa a ser (o se elimina en favor
  de) un simple chequeo: si no hay sesión válida con `'kpis-sso'` en `apps_permitidas`, redirige
  a `https://apphub.lglabproyect.com` (o el dominio que corresponda). No hay más POST a
  `/api/auth/login` local.
- `AuthController::login()` de kpis-sso deja de usarse para autenticar. Se evalúa en la
  implementación si conviene borrarlo o dejarlo inerte (bajo riesgo cualquiera de las dos, no es
  parte de las decisiones de este spec).
- Tabla `usuarios` de kpis-sso: sale del flujo de auth. No se borra en esta fase (bajo riesgo
  dejarla, se limpia después si sobra).

### Middleware de auth nuevo

- Reemplaza el guard `sanctum` de las rutas de `routes/api.php` por uno que:
  1. Lee la sesión compartida (mismo `SESSION_DRIVER`/tabla que apphub).
  2. Verifica que `'kpis-sso'` esté en `session('apps_permitidas', [])`.
  3. Si no, `401` (mismo comportamiento que hoy sin sesión válida).
- El resto de los endpoints de Métricas no cambian — siguen protegidos igual, solo cambia CÓMO
  se valida quién está adentro.

### `dashboard.html`

Sin cambios de arquitectura de acceso — sigue siendo la pantalla real de la app. El rediseño
visual (fuera de alcance de este spec, ver decisión 5) se hace después.

## Fuera de alcance (explícito)

- Las otras 3 apps del ecosistema SSO — mismo patrón, specs separados cuando toque cada una.
- Rediseño visual de `dashboard.html` — sesión de implementación aparte, con `ui-ux-pro-max`.
- Botón de "logout" que cierre sesión en toda la red vs. solo en la app actual — como comparten
  la misma sesión, cerrar sesión desde cualquiera de las dos cierra la sesión en ambas
  automáticamente (es literalmente la misma fila en `sessions`); no hace falta lógica especial,
  pero vale confirmarlo con una prueba manual en la implementación.
- Migrar/borrar la tabla `usuarios` de kpis-sso — se deja inerte, no se elimina en esta fase.
- `SolicitudAcceso` (pedir acceso a una app sin que el superusuario lo haya otorgado antes) —
  el flujo de solicitudes ya existe en apphub para proyectos; extenderlo a aplicaciones externas
  no se pidió, se puede evaluar después si hace falta.

## Verificación de humo (al terminar la implementación)

- Login en apphub → el launcher muestra solo `kpis-sso` (única app activa hoy) si el usuario
  tiene el grant; no la muestra si no lo tiene.
- Clic en la tarjeta de `kpis-sso` → entra directo al dashboard, sin pantalla de login de
  kpis-sso.
- Usuario sin grant para `kpis-sso` que intenta entrar directo por URL → rebota a apphub, no ve
  ningún dato.
- Cerrar sesión desde kpis-sso → volver a apphub también pide login de nuevo (misma sesión).
- Revocar el acceso a `kpis-sso` desde el admin de apphub, con la sesión ya abierta → sigue
  entrando hasta el próximo login (ver trade-off aceptado en decisión 4) — confirmar que este es
  el comportamiento real, no una revocación instantánea no implementada por descuido.
