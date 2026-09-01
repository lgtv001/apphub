# Acceso a aplicaciones por Tipo de Usuario — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Reemplazar el grant directo por usuario (pestañas "Asignaciones" y "Aplicaciones" del panel superuser) por un único mecanismo — el acceso a aplicaciones/secciones se otorga exclusivamente vía `TipoUsuario`, unión de permisos entre todos los tipos activos del usuario.

**Architecture:** Dos tablas pivote nuevas (`usuarios_tipos_usuario`, `tipo_usuario_aplicacion_secciones`) reemplazan `usuarios_aplicaciones`/`usuario_aplicacion_secciones`. Una migración de datos (con pre-flight y un tipo por usuario, nunca compartido) copia el único grant real de producción antes de dropear las tablas viejas. `Usuario::seccionesDeAplicacion()` pasa de "leer un grant directo" a "resolver la unión de secciones de todos los tipos activos, con `editar` ganando por `CASE WHEN`, nunca `MAX()` sobre el enum". El cutover se hace en orden: (1) schema aditivo, (2) todos los controllers/tests migran a las tablas nuevas mientras las viejas siguen vivas sin usarse, (3) recién ahí se migran los datos y se dropean las tablas viejas — así el test suite queda verde después de cada tarea.

**Tech Stack:** Laravel 12 (PHP), Postgres en producción / SQLite en tests (`phpunit.xml`, `DB_CONNECTION=sqlite`, `:memory:`), Sanctum para auth, PHPUnit (`php artisan test`), frontend vanilla JS en `backend/public/app/superuser.html` consumiendo `/assets/js/api.js`.

**Spec:** `docs/superpowers/specs/2026-08-31-tipos-usuario-acceso-global-design.md` (aprobado, con los 16 hallazgos de `architect` ya incorporados — este plan asume ese spec leído).

## Global Constraints

- Producción corre **Postgres**, no MySQL — `.env.example` está desactualizado, no confiar en él (hallazgo `architect`, verificado por SSH).
- Tests corren contra **SQLite in-memory** (`phpunit.xml`). Cualquier SQL debe funcionar en ambos motores — en particular, la regla "gana editar" se resuelve con `CASE WHEN nivel = 'editar' THEN 1 ELSE 0 END` en el `ORDER BY`/agregación, **nunca** con `MAX(nivel)` directo sobre el enum: el enum se guarda como texto y ordena alfabético (`'editar' < 'ver'`) tanto en Postgres como en SQLite — confirmado real, no supuesto (hallazgo `architect` #5).
- **Convención de logging a `usuarios_aplicaciones_log`** (se reusa esa tabla como audit trail del nuevo sistema, no se crea `tipos_usuario_log`, hallazgo `architect` #4):
  - Editar las **secciones de un tipo** (`TipoUsuarioController::store()`/`update()`/`destroy()`) → `entidad_id` = id del **tipo**.
  - Sincronizar los **tipos de un usuario** (`UsuarioController::store()`/`update()`, `SolicitudController::approve()`) → `entidad_id` = id del **usuario**.
  - `update()` en ambos controllers loguea (y hace `sync()`) **solo si la clave correspondiente vino en el request** (`$request->has('secciones')` / `$request->has('tipos')`) — si no, no se toca nada (hallazgo `architect` #3, el más grave: sin esto, un PUT parcial borraría en silencio todo el acceso).
- **No tocar** `usuarios_proyectos`/`Proyecto`/`Area`/`Sistema`/`Subsistema` ni sus middlewares (`CheckProyectoAccess`/`CheckRole`) — 0 filas reales en producción pero producto separado con código vivo. Solo se borra la pestaña "Asignaciones" y su controller.
- Cada task deja el test suite completo en verde (`php artisan test`) antes de pasar a la siguiente — el orden de las tasks está pensado para que las tablas/controllers viejos sigan funcionando hasta que **nada** los use, recién ahí se dropean/borran (Task 10).
- Sin placeholders: todo el código de este plan es real y compila contra el código actual del repo (verificado leyendo los archivos reales antes de escribir este plan).

---

## File Structure

**Nuevas migraciones** (`backend/database/migrations/`):
- `2026_09_01_000001_widen_usuarios_aplicaciones_log_accion.php`
- `2026_09_01_000002_create_tipo_usuario_pivots.php`
- `2026_09_01_000003_migrar_accesos_directos_a_tipos_usuario.php`

**Nuevo archivo de soporte:**
- `backend/app/Support/AccesoLegacyMigrator.php` — lógica de la migración de datos, aislada para poder testearla sin depender del orden real de las migraciones.

**Modelos tocados:**
- `backend/app/Models/Usuario.php` — nuevas relaciones/queries, se quitan `aplicaciones()`/`seccionesAplicaciones()` (Task 10).
- `backend/app/Models/TipoUsuario.php` — nuevas relaciones `usuarios()`/`secciones()`.
- `backend/app/Models/AplicacionExterna.php` — se quita el método huérfano `usuarios()` (Task 10).
- Se borran: `backend/app/Models/UsuarioAplicacion.php`, `backend/app/Models/UsuarioAplicacionSeccion.php` (Task 10).

**Controllers tocados:**
- `backend/app/Http/Controllers/Admin/TipoUsuarioController.php` — reescrito.
- `backend/app/Http/Controllers/Admin/UsuarioController.php` — suma `tipos`.
- `backend/app/Http/Controllers/Admin/SolicitudController.php` — `aplicaciones` → `tipos`.
- `backend/app/Http/Controllers/LauncherController.php` — usa las queries nuevas.
- Se borran: `backend/app/Http/Controllers/Admin/AccesoAplicacionController.php` (Task 6), `backend/app/Http/Controllers/Admin/AsignacionController.php` (Task 9).

**Rutas:** `backend/routes/api.php` — se quitan `/admin/accesos-aplicacion*` (Task 6) y `/admin/asignaciones*` (Task 9).

**Tests:**
- Nuevo: `backend/tests/Feature/AccesoLegacyMigratorTest.php`, `backend/tests/Feature/TipoUsuarioControllerTest.php`.
- Reescritos: `backend/tests/Feature/LauncherControllerTest.php`, `backend/tests/Feature/SolicitudControllerTest.php`, `backend/tests/Feature/AplicacionesModeloTest.php`, `backend/tests/Feature/AdminTest.php` (se agregan tests de tipos en Usuarios, se quitan los de Asignaciones).
- Se borra: `backend/tests/Feature/AccesoAplicacionControllerTest.php` (Task 6, reemplazado por `TipoUsuarioControllerTest.php`).

**Frontend:**
- `backend/public/app/superuser.html` — se quitan las pestañas/modales de Asignaciones y Aplicaciones; el modal de Tipo suma el picker de secciones; los modales de Usuario y Solicitud suman el multi-select de tipos.

**Otros:**
- `backend/start.sh` — se agrega `set -e` antes de `migrate --force` (hallazgo `architect` #2, endurecimiento de bajo costo).

---

### Task 1: Migración — ensanchar el enum de `usuarios_aplicaciones_log.accion`

**Hallazgo real (no estaba en el spec):** `TipoUsuarioController::update()` (Task 6) necesita loguear `accion = 'UPDATE'` en `usuarios_aplicaciones_log`, pero esa tabla se creó (2026-08-09) heredando el enum `['CREATE','DELETE']` de cuando solo servía al grant directo, que nunca tenía un "editar". Sin ensanchar el enum, el primer `UPDATE` real violaría el `CHECK` constraint tanto en Postgres como en SQLite.

**Files:**
- Create: `backend/database/migrations/2026_09_01_000001_widen_usuarios_aplicaciones_log_accion.php`
- Test: `backend/tests/Feature/LogTest.php` (se agrega un método)

**Interfaces:**
- Produces: la tabla `usuarios_aplicaciones_log` acepta `accion IN ('CREATE','UPDATE','DELETE')`.

- [ ] **Step 1: Escribir el test que falla**

Agregar a `backend/tests/Feature/LogTest.php` (ver el patrón ya usado ahí con `LogService::log`):

```php
public function test_usuarios_aplicaciones_log_acepta_accion_update(): void
{
    $token = $this->superuserToken();
    $su    = Usuario::where('rol_global', 'superuser')->first();

    LogService::log('usuarios_aplicaciones', null, $su->id, 'UPDATE', 777);

    $response = $this->withToken($token)->getJson('/api/admin/logs');
    $response->assertStatus(200);

    $origenes = collect($response->json('data'))->pluck('origen');
    $this->assertTrue($origenes->contains('usuarios_aplicaciones'));
}
```

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter test_usuarios_aplicaciones_log_acepta_accion_update`
Expected: FAIL — violación del `CHECK` constraint de SQLite sobre la columna `accion`.

- [ ] **Step 3: Escribir la migración**

```php
<?php
// backend/database/migrations/2026_09_01_000001_widen_usuarios_aplicaciones_log_accion.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel 12 altera enums de forma nativa (sin doctrine/dbal) tanto en Postgres como en
        // SQLite -- reconstruye la tabla por debajo si hace falta.
        Schema::table('usuarios_aplicaciones_log', function (Blueprint $table) {
            $table->enum('accion', ['CREATE', 'UPDATE', 'DELETE'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios_aplicaciones_log', function (Blueprint $table) {
            $table->enum('accion', ['CREATE', 'DELETE'])->change();
        });
    }
};
```

- [ ] **Step 4: Correr el test, confirmar que pasa**

Run: `php artisan test --filter test_usuarios_aplicaciones_log_acepta_accion_update`
Expected: PASS

- [ ] **Step 5: Correr el resto del suite (nada más debería cambiar todavía)**

Run: `php artisan test`
Expected: PASS completo (ningún otro test toca esta columna todavía).

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_09_01_000001_widen_usuarios_aplicaciones_log_accion.php backend/tests/Feature/LogTest.php
git commit -m "feat: permitir accion UPDATE en usuarios_aplicaciones_log"
```

---

### Task 2: Migración — tablas nuevas de Tipo de Usuario

**Files:**
- Create: `backend/database/migrations/2026_09_01_000002_create_tipo_usuario_pivots.php`
- Test: `backend/tests/Feature/AdminTest.php` (se agrega un método a la sección "Tipos de usuario")

**Interfaces:**
- Produces: tablas `usuarios_tipos_usuario` (`usuario_id`, `tipo_usuario_id`) y `tipo_usuario_aplicacion_secciones` (`tipo_usuario_id`, `seccion_id`, `nivel`), más `tipos_usuario.nombre` con índice `unique` real a nivel de columna.

- [ ] **Step 1: Escribir el test que falla**

Agregar a `backend/tests/Feature/AdminTest.php`, en la sección `// ─── Tipos de usuario ───`:

```php
public function test_nombre_de_tipo_usuario_es_unico_a_nivel_de_base_de_datos(): void
{
    TipoUsuario::factory()->create(['nombre' => 'Calidad']);

    $this->expectException(\Illuminate\Database\QueryException::class);
    TipoUsuario::query()->insert(['nombre' => 'Calidad', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
}
```

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter test_nombre_de_tipo_usuario_es_unico_a_nivel_de_base_de_datos`
Expected: FAIL — hoy `tipos_usuario.nombre` no tiene índice único a nivel de columna, el segundo `insert()` (que evita el `unique:` del `FormRequest`, ya que va directo a la base) pasa sin error.

- [ ] **Step 3: Escribir la migración**

```php
<?php
// backend/database/migrations/2026_09_01_000002_create_tipo_usuario_pivots.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AccesoLegacyMigrator (Task 3) hace firstOrCreate por nombre durante la migración de
        // datos y depende de que esta garantía sea real, no solo del controller (hallazgo
        // architect #14).
        Schema::table('tipos_usuario', function (Blueprint $table) {
            $table->unique('nombre');
        });

        Schema::create('usuarios_tipos_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('tipo_usuario_id')->constrained('tipos_usuario')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['usuario_id', 'tipo_usuario_id']);
        });

        // Sin columna aplicacion_id denormalizada (a diferencia de usuario_aplicacion_secciones):
        // la app de una sección se resuelve siempre vía seccion.aplicacion_id, un solo camino,
        // sin riesgo de que dos columnas queden desincronizadas (hallazgo architect #9).
        Schema::create('tipo_usuario_aplicacion_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_usuario_id')->constrained('tipos_usuario')->onDelete('cascade');
            $table->foreignId('seccion_id')->constrained('aplicaciones_secciones')->onDelete('cascade');
            $table->enum('nivel', ['ver', 'editar'])->default('ver');
            $table->timestamps();
            $table->unique(['tipo_usuario_id', 'seccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_tipos_usuario');
        Schema::table('tipos_usuario', function (Blueprint $table) {
            $table->dropUnique(['tipos_usuario_nombre_unique']);
        });
    }
};
```

- [ ] **Step 4: Correr el test, confirmar que pasa**

Run: `php artisan test --filter test_nombre_de_tipo_usuario_es_unico_a_nivel_de_base_de_datos`
Expected: PASS

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo.

- [ ] **Step 6: Commit**

```bash
git add backend/database/migrations/2026_09_01_000002_create_tipo_usuario_pivots.php backend/tests/Feature/AdminTest.php
git commit -m "feat: tablas pivote usuarios_tipos_usuario y tipo_usuario_aplicacion_secciones"
```

---

### Task 3: `AccesoLegacyMigrator` — lógica de migración de datos, aislada y testeada

**Files:**
- Create: `backend/app/Support/AccesoLegacyMigrator.php`
- Test: `backend/tests/Feature/AccesoLegacyMigratorTest.php`

**Interfaces:**
- Produces: `App\Support\AccesoLegacyMigrator::migrar(): void` — lee `usuarios_aplicaciones`/`usuario_aplicacion_secciones` (deben existir en el schema en el momento en que se llama), escribe `tipos_usuario`/`usuarios_tipos_usuario`/`tipo_usuario_aplicacion_secciones`. No dropea nada — eso lo hace la migración que la invoca (Task 10).
- Consumes: las tablas nuevas de Task 2.

Esta clase se prueba de forma aislada, recreando el shape de las tablas viejas *dentro del propio test* con `Schema::create` — así el test sigue siendo válido después de Task 10, cuando las tablas reales ya no existan en el schema base.

- [ ] **Step 1: Escribir los tests que fallan**

```php
<?php
// backend/tests/Feature/AccesoLegacyMigratorTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\Usuario;
use App\Support\AccesoLegacyMigrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AccesoLegacyMigratorTest extends TestCase
{
    use RefreshDatabase;

    private function crearTablasLegacy(): void
    {
        Schema::create('usuarios_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('aplicacion_id');
            $table->timestamps();
        });
        Schema::create('usuario_aplicacion_secciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('aplicacion_id');
            $table->unsignedBigInteger('seccion_id');
            $table->string('nivel', 20);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_aplicaciones');
        parent::tearDown();
    }

    public function test_migra_un_grant_directo_a_un_tipo_propio_del_usuario(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create(['email' => 'migrado@test.com']);

        DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('usuario_aplicacion_secciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'seccion_id' => $seccion->id, 'nivel' => 'editar', 'created_at' => now(), 'updated_at' => now()]);

        AccesoLegacyMigrator::migrar();

        $tipoId = DB::table('tipos_usuario')->where('nombre', 'Acceso SSO (migrado) — migrado@test.com')->value('id');
        $this->assertNotNull($tipoId);
        $this->assertDatabaseHas('usuarios_tipos_usuario', ['usuario_id' => $usuario->id, 'tipo_usuario_id' => $tipoId]);
        $this->assertDatabaseHas('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipoId, 'seccion_id' => $seccion->id, 'nivel' => 'editar']);
    }

    public function test_dos_usuarios_con_grants_distintos_reciben_tipos_separados(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $u1 = Usuario::factory()->create(['email' => 'uno@test.com']);
        $u2 = Usuario::factory()->create(['email' => 'dos@test.com']);

        foreach ([$u1, $u2] as $u) {
            DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $u->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('usuario_aplicacion_secciones')->insert(['usuario_id' => $u->id, 'aplicacion_id' => $app->id, 'seccion_id' => $seccion->id, 'nivel' => 'ver', 'created_at' => now(), 'updated_at' => now()]);
        }

        AccesoLegacyMigrator::migrar();

        $this->assertSame(2, DB::table('tipos_usuario')->where('nombre', 'like', 'Acceso SSO (migrado)%')->count());
    }

    public function test_aborta_si_un_grant_no_tiene_ninguna_seccion(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $usuario = Usuario::factory()->create();

        DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
        // sin filas en usuario_aplicacion_secciones -- caso que el modelo nuevo no representa

        $this->expectException(RuntimeException::class);

        AccesoLegacyMigrator::migrar();
    }

    public function test_es_idempotente_si_se_corre_dos_veces(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create(['email' => 'idem@test.com']);

        DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('usuario_aplicacion_secciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'seccion_id' => $seccion->id, 'nivel' => 'ver', 'created_at' => now(), 'updated_at' => now()]);

        AccesoLegacyMigrator::migrar();
        AccesoLegacyMigrator::migrar();

        $this->assertSame(1, DB::table('tipos_usuario')->where('nombre', 'Acceso SSO (migrado) — idem@test.com')->count());
        $this->assertSame(1, DB::table('usuarios_tipos_usuario')->where('usuario_id', $usuario->id)->count());
    }
}
```

- [ ] **Step 2: Correr los tests, confirmar que fallan**

Run: `php artisan test --filter AccesoLegacyMigratorTest`
Expected: FAIL — `App\Support\AccesoLegacyMigrator` no existe todavía.

- [ ] **Step 3: Escribir la clase**

```php
<?php
// backend/app/Support/AccesoLegacyMigrator.php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copia los datos del mecanismo de grant directo (usuarios_aplicaciones +
 * usuario_aplicacion_secciones) al mecanismo nuevo de Tipo de Usuario, sin tocar el schema.
 * Separado de la migración que la invoca (2026_09_01_000003_...) para poder probarla de forma
 * aislada -- ver AccesoLegacyMigratorTest.
 */
class AccesoLegacyMigrator
{
    public static function migrar(): void
    {
        $secciones = DB::table('usuario_aplicacion_secciones')->get();
        $seccionesPorUsuarioApp = $secciones->groupBy(fn ($s) => "{$s->usuario_id}:{$s->aplicacion_id}");

        $grants = DB::table('usuarios_aplicaciones')->get();

        // Pre-flight (hallazgo architect #8): abortar si algún grant no tiene ninguna sección --
        // usuarios_aplicaciones permite hoy dar acceso a una app sin secciones (el launcher
        // muestra la tarjeta igual), un caso que el modelo nuevo no puede representar (el acceso
        // se deriva de las secciones). Mejor abortar que migrar en silencio a MENOS acceso.
        foreach ($grants as $grant) {
            $clave = "{$grant->usuario_id}:{$grant->aplicacion_id}";
            if (!isset($seccionesPorUsuarioApp[$clave]) || $seccionesPorUsuarioApp[$clave]->isEmpty()) {
                throw new RuntimeException(
                    "Migración abortada: el usuario {$grant->usuario_id} tiene acceso a la ".
                    "aplicación {$grant->aplicacion_id} sin ninguna sección -- el modelo de Tipo ".
                    "de Usuario no puede representar ese caso. Revisar manualmente antes de reintentar."
                );
            }
        }

        foreach ($grants as $grant) {
            // Un tipo por usuario, nunca compartido (hallazgo architect #7): mezclar 2+ usuarios
            // en el mismo tipo migrado uniría sus accesos entre sí.
            $usuario = DB::table('usuarios')->where('id', $grant->usuario_id)->first();
            $nombreTipo = "Acceso SSO (migrado) — {$usuario->email}";

            $tipoId = DB::table('tipos_usuario')->where('nombre', $nombreTipo)->value('id');
            if (!$tipoId) {
                $tipoId = DB::table('tipos_usuario')->insertGetId([
                    'nombre'      => $nombreTipo,
                    'descripcion' => 'Generado automáticamente al migrar el grant directo del 2026-08-31.',
                    'activo'      => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            $clave = "{$grant->usuario_id}:{$grant->aplicacion_id}";
            foreach ($seccionesPorUsuarioApp[$clave] as $seccion) {
                // Idempotente (hallazgo architect #13): updateOrInsert, nunca insert() puro.
                DB::table('tipo_usuario_aplicacion_secciones')->updateOrInsert(
                    ['tipo_usuario_id' => $tipoId, 'seccion_id' => $seccion->seccion_id],
                    ['nivel' => $seccion->nivel, 'created_at' => now(), 'updated_at' => now()]
                );
            }

            DB::table('usuarios_tipos_usuario')->updateOrInsert(
                ['usuario_id' => $grant->usuario_id, 'tipo_usuario_id' => $tipoId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
```

- [ ] **Step 4: Correr los tests, confirmar que pasan**

Run: `php artisan test --filter AccesoLegacyMigratorTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Support/AccesoLegacyMigrator.php backend/tests/Feature/AccesoLegacyMigratorTest.php
git commit -m "feat: AccesoLegacyMigrator para migrar grants directos a tipos de usuario"
```

---

### Task 4: Modelos — relaciones nuevas (aditivo, no rompe nada existente)

**Files:**
- Modify: `backend/app/Models/TipoUsuario.php`
- Modify: `backend/app/Models/Usuario.php`
- Test: `backend/tests/Feature/AdminTest.php` (se agrega un método)

**Interfaces:**
- Produces: `TipoUsuario::usuarios()`, `TipoUsuario::secciones()` (con pivot `nivel`), `Usuario::tiposUsuario()`.
- Nota: `Usuario::aplicaciones()`/`seccionesAplicaciones()` **se mantienen sin tocar** en esta task — todavía las usan `AccesoAplicacionController`, `SolicitudController` y varios tests. Se quitan recién en Task 10.

- [ ] **Step 1: Escribir el test que falla**

```php
public function test_tipo_usuario_tiene_usuarios_y_secciones_con_nivel(): void
{
    $app = \App\Models\AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
    $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
    $tipo = TipoUsuario::factory()->create();
    $tipo->secciones()->attach($seccion->id, ['nivel' => 'editar']);
    $usuario = Usuario::factory()->create();
    $usuario->tiposUsuario()->attach($tipo->id);

    $this->assertTrue($tipo->usuarios->contains($usuario));
    $this->assertSame('editar', $tipo->secciones->first()->pivot->nivel);
    $this->assertTrue($usuario->tiposUsuario->contains($tipo));
}
```

(Agregar al bloque `// ─── Tipos de usuario ───` de `AdminTest.php`.)

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter test_tipo_usuario_tiene_usuarios_y_secciones_con_nivel`
Expected: FAIL — `Usuario::tiposUsuario()` no existe.

- [ ] **Step 3: Agregar las relaciones**

`backend/app/Models/TipoUsuario.php` — agregar al final de la clase, antes del `}`:

```php
    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'usuarios_tipos_usuario', 'tipo_usuario_id', 'usuario_id')
            ->withTimestamps();
    }

    public function secciones()
    {
        return $this->belongsToMany(AplicacionSeccion::class, 'tipo_usuario_aplicacion_secciones', 'tipo_usuario_id', 'seccion_id')
            ->withPivot('nivel')
            ->withTimestamps();
    }
```

`backend/app/Models/Usuario.php` — agregar después del método `asignaciones()` existente:

```php
    public function tiposUsuario()
    {
        return $this->belongsToMany(TipoUsuario::class, 'usuarios_tipos_usuario', 'usuario_id', 'tipo_usuario_id')
            ->withTimestamps();
    }
```

- [ ] **Step 4: Correr el test, confirmar que pasa**

Run: `php artisan test --filter test_tipo_usuario_tiene_usuarios_y_secciones_con_nivel`
Expected: PASS

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Models/TipoUsuario.php backend/app/Models/Usuario.php backend/tests/Feature/AdminTest.php
git commit -m "feat: relaciones TipoUsuario::usuarios()/secciones() y Usuario::tiposUsuario()"
```

---

### Task 5: `Usuario` — queries de acceso efectivo + cutover de `LauncherController`

**Files:**
- Modify: `backend/app/Models/Usuario.php`
- Modify: `backend/app/Http/Controllers/LauncherController.php`
- Modify: `backend/tests/Feature/LauncherControllerTest.php`

**Interfaces:**
- Produces: `Usuario::seccionesDeAplicacion(string): array` (misma firma pública que antes, ahora resuelta por unión de tipos), `Usuario::tieneAccesoA(string): bool`, `Usuario::codigosDeAplicacionesConAcceso(): array`.
- Consumes: `Usuario::tiposUsuario()` (Task 4), tablas de Task 2.

- [ ] **Step 1: Reescribir `LauncherControllerTest.php` (RED primero: falla porque el controller todavía no cambió)**

Reemplazar el contenido completo de `backend/tests/Feature/LauncherControllerTest.php`:

```php
<?php
// apphub/backend/tests/Feature/LauncherControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LauncherControllerTest extends TestCase
{
    use RefreshDatabase;

    /** Otorga acceso vía un TipoUsuario de prueba -- el mecanismo nuevo, sin attach() directo. */
    private function otorgarAcceso(Usuario $usuario, AplicacionExterna $app, array $secciones = []): void
    {
        $tipo = TipoUsuario::factory()->create();
        foreach ($secciones as $seccionId => $nivel) {
            $tipo->secciones()->attach($seccionId, ['nivel' => $nivel]);
        }
        if (empty($secciones)) {
            // Compatibilidad con los tests viejos que daban "acceso a la app sin secciones":
            // el modelo nuevo no puede representarlo tal cual (ver spec, Migración de datos),
            // así que se le da una sección real para que el tipo aporte acceso de verdad.
            $seccion = $app->secciones()->firstOrCreate(['codigo' => 'default'], ['nombre' => 'Acceso general']);
            $tipo->secciones()->attach($seccion->id, ['nivel' => 'ver']);
        }
        $usuario->tiposUsuario()->attach($tipo->id);
    }

    public function test_muestra_apps_inactivas_como_proximamente_para_cualquier_usuario(): void
    {
        AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI de Accidentes', 'url_base' => 'https://x', 'activo' => true]);
        AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false]);
        $usuario = Usuario::factory()->create(); // sin ningún tipo asignado

        $token = $usuario->createToken('t')->plainTextToken;
        $resp = $this->withToken($token)->getJson('/api/launcher/aplicaciones')->assertStatus(200);

        $data = collect($resp->json('data'));
        $vcc = $data->firstWhere('codigo', 'vcc');
        $this->assertTrue($vcc['proximamente']);
        $this->assertNull($vcc['url_base'] ?: null);
    }

    public function test_app_activa_solo_aparece_clickeable_si_tiene_acceso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI de Accidentes', 'url_base' => 'https://x', 'activo' => true]);
        $sinAcceso = Usuario::factory()->create();
        $conAcceso = Usuario::factory()->create();
        $this->otorgarAcceso($conAcceso, $app);

        $dataSinAcceso = collect($this->withToken($sinAcceso->createToken('t')->plainTextToken)
            ->getJson('/api/launcher/aplicaciones')->json('data'));
        $dataConAcceso = collect($this->withToken($conAcceso->createToken('t')->plainTextToken)
            ->getJson('/api/launcher/aplicaciones')->json('data'));

        $this->assertNull($dataSinAcceso->firstWhere('codigo', 'kpis-sso'));
        $this->assertNotNull($dataConAcceso->firstWhere('codigo', 'kpis-sso'));
    }

    public function test_entrar_devuelve_url_con_handoff_si_tiene_acceso(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();
        $this->otorgarAcceso($usuario, $app, [$seccion->id => 'ver']);

        $antes = now()->timestamp;
        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(200);

        $url = $resp->json('url');
        $this->assertStringStartsWith('https://kpis-sso.test/sso/entrar?handoff=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        [$codificado, $firma] = explode('.', $query['handoff'], 2);
        $this->assertSame(hash_hmac('sha256', $codificado, 'secreto-de-test'), $firma, 'la firma debe corresponder al secreto configurado');

        $payload = json_decode(base64_decode($codificado), true);
        $this->assertSame($usuario->email, $payload['sub']);
        $this->assertSame($usuario->nombre, $payload['nombre']);
        $this->assertSame('kpis-sso', $payload['app']);
        $this->assertSame(['metricas' => 'ver'], $payload['secciones']);
        $this->assertNotEmpty($payload['nonce']);
        $this->assertGreaterThanOrEqual($antes + 55, $payload['exp']);
        $this->assertLessThanOrEqual($antes + 65, $payload['exp']);
    }

    public function test_entrar_incluye_el_tema_en_el_payload_si_se_manda_uno_valido(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();
        $this->otorgarAcceso($usuario, $app);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar', ['tema' => 'light'])
            ->assertStatus(200);

        parse_str(parse_url($resp->json('url'), PHP_URL_QUERY), $query);
        [$codificado] = explode('.', $query['handoff'], 2);
        $payload = json_decode(base64_decode($codificado), true);

        $this->assertSame('light', $payload['tema']);
    }

    public function test_entrar_convierte_a_auto_un_valor_de_tema_que_no_sea_light_o_dark(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();
        $this->otorgarAcceso($usuario, $app);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar', ['tema' => 'psicodelico'])
            ->assertStatus(200);

        parse_str(parse_url($resp->json('url'), PHP_URL_QUERY), $query);
        [$codificado] = explode('.', $query['handoff'], 2);
        $payload = json_decode(base64_decode($codificado), true);

        $this->assertSame('auto', $payload['tema']);
    }

    public function test_entrar_usa_auto_si_no_se_manda_el_campo_tema(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();
        $this->otorgarAcceso($usuario, $app);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar', [])
            ->assertStatus(200);

        parse_str(parse_url($resp->json('url'), PHP_URL_QUERY), $query);
        [$codificado] = explode('.', $query['handoff'], 2);
        $payload = json_decode(base64_decode($codificado), true);

        $this->assertSame('auto', $payload['tema']);
    }

    public function test_entrar_da_403_sin_acceso(): void
    {
        AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();

        $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(403);
    }

    public function test_entrar_da_404_si_la_app_no_existe_o_esta_inactiva(): void
    {
        AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false]);
        $usuario = Usuario::factory()->create();

        $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/vcc/entrar')
            ->assertStatus(404);
    }

    public function test_entrar_da_403_si_el_usuario_esta_inactivo_aunque_tenga_acceso(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $usuario = Usuario::factory()->inactivo()->create();
        $this->otorgarAcceso($usuario, $app);

        $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(403);
    }
}
```

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter LauncherControllerTest`
Expected: FAIL — `Usuario::tiposUsuario()->attach()` funciona, pero el controller sigue llamando a `$usuario->aplicaciones()` (que ahora no tiene ningún grant seteado por estos tests nuevos).

- [ ] **Step 3: Agregar las queries a `Usuario` y cambiar `LauncherController`**

En `backend/app/Models/Usuario.php`, agregar (después de `tiposUsuario()` de Task 4, dejando `seccionesDeAplicacion()` vieja intacta todavía — **no la borres en esta task**, solo agregá las 3 nuevas antes de ella):

```php
    public function tieneAccesoA(string $codigoApp): bool
    {
        return $this->accesoEfectivoQuery()->where('a.codigo', $codigoApp)->exists();
    }

    /** @return string[] códigos de aplicaciones a las que el usuario tiene al menos una sección efectiva */
    public function codigosDeAplicacionesConAcceso(): array
    {
        return $this->accesoEfectivoQuery()->distinct()->pluck('a.codigo')->all();
    }

    /** @return array<string,string> codigo de sección => nivel efectivo ('ver'|'editar') para una app */
    public function seccionesDeAplicacionPorTipo(string $codigoApp): array
    {
        return $this->accesoEfectivoQuery()
            ->where('a.codigo', $codigoApp)
            ->select('s.codigo as seccion_codigo', \Illuminate\Support\Facades\DB::raw("MAX(CASE WHEN tas.nivel = 'editar' THEN 1 ELSE 0 END) as gana_editar"))
            ->groupBy('s.codigo')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->seccion_codigo => $row->gana_editar ? 'editar' : 'ver'])
            ->all();
    }

    /**
     * Base de la unión de permisos: todos los tipos ACTIVOS asignados al usuario, unidos a las
     * secciones que cada uno otorga. "Gana editar" se resuelve en el llamador vía CASE WHEN,
     * NUNCA con MAX() directo sobre la columna nivel -- el enum se guarda como texto y ordena
     * alfabético ('editar' < 'ver'), así que MAX() de texto da el resultado contrario al
     * pretendido (confirmado real en Postgres y SQLite, hallazgo architect #5).
     */
    private function accesoEfectivoQuery()
    {
        return \Illuminate\Support\Facades\DB::table('usuarios_tipos_usuario as ut')
            ->join('tipos_usuario as t', 't.id', '=', 'ut.tipo_usuario_id')
            ->join('tipo_usuario_aplicacion_secciones as tas', 'tas.tipo_usuario_id', '=', 't.id')
            ->join('aplicaciones_secciones as s', 's.id', '=', 'tas.seccion_id')
            ->join('aplicaciones_externas as a', 'a.id', '=', 's.aplicacion_id')
            ->where('ut.usuario_id', $this->id)
            ->where('t.activo', true);
    }
```

Nota: se llama `seccionesDeAplicacionPorTipo()` (no `seccionesDeAplicacion()`) para no chocar con el método viejo que `SolicitudController`/`AccesoAplicacionController` todavía usan en esta task. En Task 10, cuando se borren esos consumidores viejos, este método se **renombra** a `seccionesDeAplicacion()` (reemplazando al viejo) — ver Task 10, Step 3.

En `backend/app/Http/Controllers/LauncherController.php`, reemplazar el contenido completo:

```php
<?php
// apphub/backend/app/Http/Controllers/LauncherController.php
namespace App\Http\Controllers;

use App\Models\AplicacionExterna;
use App\Services\SsoHandoffService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LauncherController extends Controller
{
    public function __construct(private SsoHandoffService $firmador) {}

    public function index(Request $request)
    {
        $usuario = $request->user();
        $codigosConAcceso = $usuario->codigosDeAplicacionesConAcceso();

        $data = AplicacionExterna::orderBy('nombre')->get()
            ->filter(fn ($app) => $app->activo ? in_array($app->codigo, $codigosConAcceso, true) : true)
            ->map(fn ($app) => [
                'codigo' => $app->codigo,
                'nombre' => $app->nombre,
                'url_base' => $app->activo ? $app->url_base : null,
                'proximamente' => !$app->activo,
                'secciones' => $app->activo ? $usuario->seccionesDeAplicacionPorTipo($app->codigo) : null,
            ])
            ->values();

        return response()->json(['data' => $data]);
    }

    public function entrar(Request $request, string $codigo)
    {
        $app = AplicacionExterna::where('codigo', $codigo)->where('activo', true)->first();
        if (!$app) {
            return response()->json(['message' => 'Aplicación no encontrada'], 404);
        }

        $usuario = $request->user();
        if (!$usuario->activo) {
            abort(403, 'Usuario inactivo');
        }
        if (!$usuario->tieneAccesoA($app->codigo)) {
            return response()->json(['message' => 'Sin acceso a esta aplicación'], 403);
        }

        // Pedido 2026-08-14, corregido el mismo día: propagar el tema claro/oscuro/automático
        // elegido en apphub a la app de destino. "auto" es un valor explícito, nunca se omite --
        // el estado de apphub siempre gana al entrar. Se valida contra una lista blanca en vez de
        // confiar en el string tal cual del cliente.
        $tema = $request->input('tema');
        $tema = in_array($tema, ['light', 'dark', 'auto'], true) ? $tema : 'auto';

        $handoff = $this->firmador->firmar([
            'sub'       => $usuario->email,
            'nombre'    => $usuario->nombre,
            'app'       => $app->codigo,
            'secciones' => $usuario->seccionesDeAplicacionPorTipo($app->codigo),
            'tema'      => $tema,
            'nonce'     => Str::random(32),
            'exp'       => now()->addSeconds(60)->timestamp,
        ]);

        return response()->json(['url' => "{$app->url_base}/sso/entrar?handoff=" . urlencode($handoff)]);
    }
}
```

- [ ] **Step 4: Correr el test, confirmar que pasa**

Run: `php artisan test --filter LauncherControllerTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo (`AccesoAplicacionControllerTest`/`SolicitudControllerTest`/`AplicacionesModeloTest` siguen usando `Usuario::aplicaciones()`, intacto).

- [ ] **Step 6: Commit**

```bash
git add backend/app/Models/Usuario.php backend/app/Http/Controllers/LauncherController.php backend/tests/Feature/LauncherControllerTest.php
git commit -m "feat: LauncherController usa acceso efectivo por Tipo de Usuario"
```

---

### Task 6: `TipoUsuarioController` — CRUD con secciones, guard de borrado, logging; se retira `AccesoAplicacionController`

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/TipoUsuarioController.php`
- Delete: `backend/app/Http/Controllers/Admin/AccesoAplicacionController.php`
- Delete: `backend/tests/Feature/AccesoAplicacionControllerTest.php`
- Create: `backend/tests/Feature/TipoUsuarioControllerTest.php`
- Modify: `backend/routes/api.php`

**Interfaces:**
- Produces: `POST/PUT /api/admin/tipos-usuario` aceptan `secciones: [{seccion_id, nivel}]`; `DELETE` devuelve 409 si el tipo tiene usuarios asignados.
- Consumes: `TipoUsuario::secciones()`/`usuarios()` (Task 4).

- [ ] **Step 1: Escribir el test que falla**

```php
<?php
// backend/tests/Feature/TipoUsuarioControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TipoUsuarioControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        return Usuario::factory()->state(['rol_global' => 'superuser'])->create()->createToken('t')->plainTextToken;
    }

    public function test_crea_tipo_con_secciones_y_niveles(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $cargar = $app->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $metricas = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);

        $this->withToken($this->superuserToken())->postJson('/api/admin/tipos-usuario', [
            'nombre' => 'Calidad',
            'secciones' => [
                ['seccion_id' => $cargar->id, 'nivel' => 'editar'],
                ['seccion_id' => $metricas->id, 'nivel' => 'ver'],
            ],
        ])->assertStatus(201);

        $tipo = TipoUsuario::where('nombre', 'Calidad')->firstOrFail();
        $this->assertDatabaseHas('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipo->id, 'seccion_id' => $cargar->id, 'nivel' => 'editar']);
        $this->assertDatabaseHas('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipo->id, 'seccion_id' => $metricas->id, 'nivel' => 'ver']);
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $tipo->id, 'accion' => 'CREATE']);
    }

    public function test_seccion_inexistente_es_rechazada(): void
    {
        $this->withToken($this->superuserToken())->postJson('/api/admin/tipos-usuario', [
            'nombre' => 'Calidad',
            'secciones' => [['seccion_id' => 9999, 'nivel' => 'ver']],
        ])->assertStatus(422);
    }

    public function test_editar_sin_mandar_secciones_no_borra_las_existentes(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create(['nombre' => 'Original']);
        $tipo->secciones()->attach($seccion->id, ['nivel' => 'ver']);

        $this->withToken($this->superuserToken())->putJson("/api/admin/tipos-usuario/{$tipo->id}", [
            'activo' => false,
        ])->assertStatus(200);

        $this->assertDatabaseHas('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipo->id, 'seccion_id' => $seccion->id, 'nivel' => 'ver']);
        $this->assertDatabaseMissing('usuarios_aplicaciones_log', ['entidad_id' => $tipo->id, 'accion' => 'UPDATE']);
    }

    public function test_editar_mandando_secciones_reemplaza_las_existentes(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $vieja = $app->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $nueva = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create();
        $tipo->secciones()->attach($vieja->id, ['nivel' => 'ver']);

        $this->withToken($this->superuserToken())->putJson("/api/admin/tipos-usuario/{$tipo->id}", [
            'secciones' => [['seccion_id' => $nueva->id, 'nivel' => 'editar']],
        ])->assertStatus(200);

        $this->assertDatabaseMissing('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipo->id, 'seccion_id' => $vieja->id]);
        $this->assertDatabaseHas('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipo->id, 'seccion_id' => $nueva->id, 'nivel' => 'editar']);
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $tipo->id, 'accion' => 'UPDATE']);
    }

    public function test_destroy_da_409_si_el_tipo_tiene_usuarios_asignados(): void
    {
        $tipo = TipoUsuario::factory()->create();
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach($tipo->id);

        $this->withToken($this->superuserToken())
            ->deleteJson("/api/admin/tipos-usuario/{$tipo->id}")
            ->assertStatus(409);

        $this->assertDatabaseHas('tipos_usuario', ['id' => $tipo->id]);
    }

    public function test_destroy_funciona_sin_usuarios_asignados(): void
    {
        $tipo = TipoUsuario::factory()->create();

        $this->withToken($this->superuserToken())
            ->deleteJson("/api/admin/tipos-usuario/{$tipo->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('tipos_usuario', ['id' => $tipo->id]);
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $tipo->id, 'accion' => 'DELETE']);
    }
}
```

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter TipoUsuarioControllerTest`
Expected: FAIL — el controller actual no acepta `secciones` (error 422 de validación al ver un campo no reconocido no es el problema; el problema es que no se guarda nada en `tipo_usuario_aplicacion_secciones` ni hay guard de 409).

- [ ] **Step 3: Reescribir el controller, borrar el viejo, actualizar rutas**

Reemplazar `backend/app/Http/Controllers/Admin/TipoUsuarioController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoUsuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TipoUsuarioController extends Controller
{
    public function index()
    {
        $tipos = TipoUsuario::with('secciones.aplicacion')->orderBy('nombre')->get();

        $tipos->each(function ($tipo) {
            $tipo->secciones_resumen = $tipo->secciones->map(fn ($s) => [
                'seccion_id'         => $s->id,
                'codigo'             => $s->codigo,
                'nombre'             => $s->nombre,
                'aplicacion'         => $s->aplicacion->nombre,
                'aplicacion_codigo'  => $s->aplicacion->codigo,
                'nivel'              => $s->pivot->nivel,
            ])->values();
        });

        return response()->json(['data' => $tipos]);
    }

    public function store(Request $request)
    {
        $data = $this->validarPayload($request);

        $tipo = DB::transaction(function () use ($data, $request) {
            $tipo = TipoUsuario::create([
                'nombre'      => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'activo'      => $data['activo'] ?? true,
            ]);

            if ($request->has('secciones')) {
                $tipo->secciones()->sync($this->pivotDeSecciones($data['secciones']));
            }

            return $tipo;
        });

        LogService::log(
            tabla:        'usuarios_aplicaciones',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $tipo->id,
            datosDespues: ['tipo_id' => $tipo->id, 'nombre' => $tipo->nombre, 'secciones' => $data['secciones'] ?? []],
            ip:           $request->ip()
        );

        return response()->json($tipo->fresh('secciones.aplicacion'), 201);
    }

    public function update(Request $request, int $id)
    {
        $tipo = TipoUsuario::findOrFail($id);
        $data = $this->validarPayload($request, $id);

        // Hallazgo architect #3 (el más grave): sync() SOLO si la clave "secciones" vino en el
        // payload. El modal de editar tipo a veces manda solo {activo} -- sincronizar con []
        // en ese caso borraría en silencio todo el acceso que ese tipo otorgaba.
        $antes = $tipo->secciones->map(fn ($s) => ['seccion_id' => $s->id, 'nivel' => $s->pivot->nivel])->values()->all();
        $tocaSecciones = $request->has('secciones');

        DB::transaction(function () use ($tipo, $data, $tocaSecciones) {
            $tipo->update(array_intersect_key($data, array_flip(['nombre', 'descripcion', 'activo'])));

            if ($tocaSecciones) {
                $tipo->secciones()->sync($this->pivotDeSecciones($data['secciones']));
            }
        });

        if ($tocaSecciones) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'UPDATE',
                entidadId:    $tipo->id,
                datosAntes:   ['secciones' => $antes],
                datosDespues: ['secciones' => $data['secciones']],
                ip:           $request->ip()
            );
        }

        return response()->json($tipo->fresh('secciones.aplicacion'));
    }

    public function destroy(Request $request, int $id)
    {
        $tipo = TipoUsuario::findOrFail($id);

        // Un tipo con usuarios asignados no se borra en cascada silenciosa -- sería una
        // revocación masiva sin confirmación explícita (ver spec, Modelo de datos).
        if ($tipo->usuarios()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay usuarios con este tipo asignado. Quítaselo primero.',
            ], 409);
        }

        $secciones = $tipo->secciones->map(fn ($s) => ['seccion_id' => $s->id, 'nivel' => $s->pivot->nivel])->values()->all();
        $nombre = $tipo->nombre;

        $tipo->delete();

        LogService::log(
            tabla:      'usuarios_aplicaciones',
            proyectoId: null,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $id,
            datosAntes: ['tipo_id' => $id, 'nombre' => $nombre, 'secciones' => $secciones],
            ip:         $request->ip()
        );

        return response()->noContent();
    }

    private function validarPayload(Request $request, ?int $ignorarId = null): array
    {
        return $request->validate([
            'nombre'                 => [$ignorarId ? 'string' : 'required|string', 'max:100', Rule::unique('tipos_usuario', 'nombre')->ignore($ignorarId)],
            'descripcion'             => 'nullable|string|max:255',
            'activo'                  => 'boolean',
            'secciones'               => 'array',
            'secciones.*.seccion_id'  => 'required_with:secciones|integer|distinct|exists:aplicaciones_secciones,id',
            'secciones.*.nivel'       => 'required_with:secciones|in:ver,editar',
        ]);
    }

    private function pivotDeSecciones(array $secciones): array
    {
        return collect($secciones)->mapWithKeys(fn ($s) => [$s['seccion_id'] => ['nivel' => $s['nivel']]])->all();
    }
}
```

Borrar: `backend/app/Http/Controllers/Admin/AccesoAplicacionController.php` y `backend/tests/Feature/AccesoAplicacionControllerTest.php`.

En `backend/routes/api.php`:
- Quitar `use App\Http\Controllers\Admin\AccesoAplicacionController;`
- Quitar el bloque:
```php
        Route::get('/accesos-aplicacion',    [AccesoAplicacionController::class, 'index']);
        Route::post('/accesos-aplicacion',   [AccesoAplicacionController::class, 'store']);
        Route::delete('/accesos-aplicacion/{usuarioId}/{aplicacionId}', [AccesoAplicacionController::class, 'destroy']);
```

- [ ] **Step 4: Correr el test, confirmar que pasa**

Run: `php artisan test --filter TipoUsuarioControllerTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo (`SolicitudController`/`AplicacionesModeloTest` siguen intactos, todavía no tocan tipos).

- [ ] **Step 6: Commit**

```bash
git rm backend/app/Http/Controllers/Admin/AccesoAplicacionController.php backend/tests/Feature/AccesoAplicacionControllerTest.php
git add backend/app/Http/Controllers/Admin/TipoUsuarioController.php backend/tests/Feature/TipoUsuarioControllerTest.php backend/routes/api.php
git commit -m "feat: TipoUsuarioController con secciones/nivel; retira AccesoAplicacionController"
```

---

### Task 7: `UsuarioController` (Admin) — asignar tipos al crear/editar

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/UsuarioController.php`
- Modify: `backend/tests/Feature/AdminTest.php`

**Interfaces:**
- Produces: `POST/PUT /api/admin/usuarios` aceptan `tipos: [tipo_id,...]`; `index()` incluye `tiposUsuario:id,nombre`.

- [ ] **Step 1: Escribir los tests que fallan**

Agregar a `backend/tests/Feature/AdminTest.php`, en la sección `// ─── Usuarios ───`:

```php
public function test_crear_usuario_con_tipos_los_asigna(): void
{
    [, $token] = $this->superuserToken();
    $tipo = TipoUsuario::factory()->create();

    $resp = $this->withToken($token)->postJson('/api/admin/usuarios', [
        'nombre'    => 'Con Tipo',
        'email'     => 'contipo@test.com',
        'password'  => 'secret1234',
        'rol_global'=> 'usuario',
        'tipos'     => [$tipo->id],
    ])->assertStatus(201);

    $usuario = Usuario::where('email', 'contipo@test.com')->firstOrFail();
    $this->assertTrue($usuario->tiposUsuario->contains($tipo));
    $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $usuario->id, 'accion' => 'CREATE']);
}

public function test_editar_usuario_sin_mandar_tipos_no_los_borra(): void
{
    [, $token] = $this->superuserToken();
    $tipo = TipoUsuario::factory()->create();
    $usuario = Usuario::factory()->create();
    $usuario->tiposUsuario()->attach($tipo->id);

    $this->withToken($token)->putJson("/api/admin/usuarios/{$usuario->id}", [
        'activo' => false,
    ])->assertStatus(200);

    $this->assertTrue($usuario->fresh()->tiposUsuario->contains($tipo));
}

public function test_editar_usuario_mandando_tipos_los_reemplaza(): void
{
    [, $token] = $this->superuserToken();
    $tipoViejo = TipoUsuario::factory()->create();
    $tipoNuevo = TipoUsuario::factory()->create();
    $usuario = Usuario::factory()->create();
    $usuario->tiposUsuario()->attach($tipoViejo->id);

    $this->withToken($token)->putJson("/api/admin/usuarios/{$usuario->id}", [
        'tipos' => [$tipoNuevo->id],
    ])->assertStatus(200);

    $usuario->refresh();
    $this->assertFalse($usuario->tiposUsuario->contains($tipoViejo));
    $this->assertTrue($usuario->tiposUsuario->contains($tipoNuevo));
    $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $usuario->id, 'accion' => 'UPDATE']);
}
```

Agregar `use App\Models\TipoUsuario;` al `use` de `AdminTest.php` si no está (ya está, se usó en Task 4).

- [ ] **Step 2: Correr los tests, confirmar que fallan**

Run: `php artisan test --filter "test_crear_usuario_con_tipos_los_asigna|test_editar_usuario_sin_mandar_tipos_no_los_borra|test_editar_usuario_mandando_tipos_los_reemplaza"`
Expected: FAIL — `UsuarioController` no acepta `tipos` todavía.

- [ ] **Step 3: Reescribir el controller**

Reemplazar `backend/app/Http/Controllers/Admin/UsuarioController.php`:

```php
<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Usuario::with('tiposUsuario:id,nombre')
                ->orderBy('nombre')
                ->get(['id','nombre','email','rol_global','activo','created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'in:admin,usuario',
            'tipos'      => 'array',
            'tipos.*'    => 'integer|distinct|exists:tipos_usuario,id',
        ]);

        $usuario = DB::transaction(function () use ($data, $request) {
            $usuario = Usuario::create([
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'rol_global'    => $data['rol_global'] ?? 'usuario',
            ]);

            if ($request->has('tipos')) {
                $usuario->tiposUsuario()->sync($data['tipos']);
            }

            return $usuario;
        });

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $usuario->id,
            datosDespues: $usuario->only(['id','nombre','email','rol_global']),
            ip:           $request->ip()
        );

        if ($request->has('tipos')) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'CREATE',
                entidadId:    $usuario->id,
                datosDespues: ['tipos' => $data['tipos']],
                ip:           $request->ip()
            );
        }

        return response()->json(
            $usuario->only(['id','nombre','email','rol_global','activo']), 201
        );
    }

    public function update(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        $data = $request->validate([
            'nombre'     => 'string|max:255',
            'email'      => "email|unique:usuarios,email,{$id}",
            'password'   => 'string|min:8',
            'rol_global' => 'in:admin,usuario',
            'activo'     => 'boolean',
            'tipos'      => 'array',
            'tipos.*'    => 'integer|distinct|exists:tipos_usuario,id',
        ]);

        $antes = $usuario->only(['id','nombre','email','rol_global','activo']);
        $tiposAntes = $usuario->tiposUsuario()->pluck('tipos_usuario.id')->all();

        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        // Mismo cuidado que TipoUsuarioController::update() (hallazgo architect #3): sync() SOLO
        // si la clave "tipos" vino en el payload -- si no, un PUT que solo cambia `activo`
        // borraría en silencio todos los tipos del usuario.
        $tocaTipos = $request->has('tipos');
        $tiposNuevos = $data['tipos'] ?? [];
        unset($data['tipos']);

        DB::transaction(function () use ($usuario, $data, $tocaTipos, $tiposNuevos) {
            $usuario->update($data);
            if ($tocaTipos) {
                $usuario->tiposUsuario()->sync($tiposNuevos);
            }
        });

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $usuario->id,
            datosAntes:   $antes,
            datosDespues: $usuario->fresh()->only(['id','nombre','email','rol_global','activo']),
            ip:           $request->ip()
        );

        if ($tocaTipos) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'UPDATE',
                entidadId:    $usuario->id,
                datosAntes:   ['tipos' => $tiposAntes],
                datosDespues: ['tipos' => $tiposNuevos],
                ip:           $request->ip()
            );
        }

        return response()->json(
            $usuario->fresh()->only(['id','nombre','email','rol_global','activo'])
        );
    }

    public function destroy(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        LogService::log(
            tabla:      'usuarios',
            proyectoId: null,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $usuario->id,
            datosAntes: $usuario->only(['id','nombre','email','rol_global']),
            ip:         $request->ip()
        );

        $usuario->delete();

        return response()->noContent();
    }
}
```

- [ ] **Step 4: Correr los tests, confirmar que pasan**

Run: `php artisan test --filter AdminTest`
Expected: PASS.

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Admin/UsuarioController.php backend/tests/Feature/AdminTest.php
git commit -m "feat: UsuarioController sincroniza tipos al crear/editar"
```

---

### Task 8: `SolicitudController::approve()` — de `aplicaciones` a `tipos`

**Files:**
- Modify: `backend/app/Http/Controllers/Admin/SolicitudController.php`
- Modify: `backend/tests/Feature/SolicitudControllerTest.php`

**Interfaces:**
- Produces: `POST /api/admin/solicitudes/{id}/aprobar` acepta `tipos: [tipo_id,...]` en vez de `aplicaciones: [...]`.
- Consumes: `Usuario::tiposUsuario()`, `Usuario::seccionesDeAplicacionPorTipo()`.

- [ ] **Step 1: Reescribir el test (RED)**

Reemplazar `backend/tests/Feature/SolicitudControllerTest.php`:

```php
<?php
// apphub/backend/tests/Feature/SolicitudControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\SolicitudAcceso;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        return Usuario::factory()->state(['rol_global' => 'superuser'])->create()->createToken('t')->plainTextToken;
    }

    public function test_aprobar_solicitud_sin_tipos_sigue_funcionando_como_antes(): void
    {
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'provider' => 'github', 'provider_id' => '123',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_aprobar_solicitud_con_tipos_otorga_el_acceso_en_el_mismo_paso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create();
        $tipo->secciones()->attach($seccion->id, ['nivel' => 'ver']);

        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'provider' => 'github', 'provider_id' => '456',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'tipos' => [$tipo->id],
        ])->assertStatus(201);

        $usuario = Usuario::where('email', 'nuevo2@test.com')->firstOrFail();
        $this->assertSame(['metricas' => 'ver'], $usuario->seccionesDeAplicacionPorTipo('kpis-sso'));
    }

    public function test_aprobar_solicitud_con_tipos_registra_en_log(): void
    {
        $tipo = TipoUsuario::factory()->create();
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo3@test.com', 'provider' => 'github', 'provider_id' => '789',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo3@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'tipos' => [$tipo->id],
        ])->assertStatus(201);

        $usuarioNuevo = Usuario::where('email', 'nuevo3@test.com')->firstOrFail();
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $usuarioNuevo->id, 'accion' => 'CREATE']);
    }

    public function test_tipo_inexistente_es_rechazado_al_aprobar(): void
    {
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo4@test.com', 'provider' => 'github', 'provider_id' => '999',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo4@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'tipos' => [9999],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('usuarios', ['email' => 'nuevo4@test.com']);
    }
}
```

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter SolicitudControllerTest`
Expected: FAIL — el controller actual espera `aplicaciones`, no `tipos`.

- [ ] **Step 3: Reescribir el controller**

Reemplazar `backend/app/Http/Controllers/Admin/SolicitudController.php`:

```php
<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SolicitudAcceso;
use App\Models\Usuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SolicitudController extends Controller
{
    public function index()
    {
        $rows = SolicitudAcceso::orderByRaw("FIELD(estado,'pendiente','aprobado','rechazado')")
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rows);
    }

    public function approve(int $id, Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'required|in:superuser,admin,usuario',
            'tipos'      => 'array',
            'tipos.*'    => 'integer|distinct|exists:tipos_usuario,id',
        ]);

        $solicitud = SolicitudAcceso::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'La solicitud ya fue procesada.'], 422);
        }

        $usuario = DB::transaction(function () use ($data) {
            $usuario = Usuario::create([
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'rol_global'    => $data['rol_global'],
                'activo'        => true,
            ]);

            if (!empty($data['tipos'])) {
                $usuario->tiposUsuario()->sync($data['tipos']);
            }

            return $usuario;
        });

        if (!empty($data['tipos'])) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'CREATE',
                entidadId:    $usuario->id,
                datosDespues: ['tipos' => $data['tipos']],
                ip:           $request->ip()
            );
        }

        $solicitud->update(['estado' => 'aprobado']);

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'usuario' => $usuario->only(['id', 'nombre', 'email', 'rol_global']),
        ], 201);
    }

    public function reject(int $id)
    {
        $solicitud = SolicitudAcceso::findOrFail($id);
        $solicitud->update(['estado' => 'rechazado']);
        return response()->noContent();
    }
}
```

(Nota: `orderByRaw("FIELD(...))")` en `index()` usa sintaxis exclusiva de MySQL y probablemente esté rota contra Postgres en producción hoy -- **bug preexistente, fuera de alcance de este plan**, tal como lo marcó `architect` en el spec. No tocarlo acá.)

- [ ] **Step 4: Correr el test, confirmar que pasa**

Run: `php artisan test --filter SolicitudControllerTest`
Expected: PASS (4 tests).

- [ ] **Step 5: Correr el resto del suite**

Run: `php artisan test`
Expected: PASS completo.

- [ ] **Step 6: Commit**

```bash
git add backend/app/Http/Controllers/Admin/SolicitudController.php backend/tests/Feature/SolicitudControllerTest.php
git commit -m "feat: SolicitudController::approve() otorga acceso via tipos"
```

---

### Task 9: Retirar "Asignaciones" (`AsignacionController` + rutas)

**Files:**
- Delete: `backend/app/Http/Controllers/Admin/AsignacionController.php`
- Modify: `backend/routes/api.php`
- Modify: `backend/tests/Feature/AdminTest.php`

**Interfaces:**
- Produces: nada nuevo — solo retira. `usuarios_proyectos` y `UsuarioProyecto` (modelo) **no se tocan** (fuera de alcance del spec).

- [ ] **Step 1: Quitar los 4 tests de Asignaciones de `AdminTest.php` (RED implícito: dejan de existir)**

Borrar de `backend/tests/Feature/AdminTest.php` todo el bloque `// ─── Asignaciones ───` (los 4 métodos `test_superuser_puede_asignar_usuario_a_proyecto`, `test_asignacion_duplicada_falla`, `test_superuser_puede_revocar_asignacion`, `test_superuser_puede_listar_asignaciones`). Los imports `Proyecto`/`UsuarioProyecto` en el `use` de arriba pueden quedar sin uso — dejarlos si `Proyecto::factory()` se sigue usando en otro test del archivo (no es el caso acá, así que también se pueden quitar esos dos `use` si ya no se referencian).

- [ ] **Step 2: Borrar el controller y las rutas**

Borrar `backend/app/Http/Controllers/Admin/AsignacionController.php`.

En `backend/routes/api.php`:
- Quitar `use App\Http\Controllers\Admin\AsignacionController;`
- Quitar el bloque:
```php
        Route::get('/asignaciones',          [AsignacionController::class, 'index']);
        Route::post('/asignaciones',         [AsignacionController::class, 'store']);
        Route::delete('/asignaciones/{id}',  [AsignacionController::class, 'destroy']);
```

- [ ] **Step 3: Correr el suite completo**

Run: `php artisan test`
Expected: PASS completo. `UsuarioProyectoFactory` puede quedar sin usos reales en el suite — inofensivo, no se borra (fuera de alcance, tal como marca el spec).

- [ ] **Step 4: Commit**

```bash
git rm backend/app/Http/Controllers/Admin/AsignacionController.php
git add backend/routes/api.php backend/tests/Feature/AdminTest.php
git commit -m "feat: retira la pestaña Asignaciones (grant directo a Proyectos)"
```

---

### Task 10: Cutover final — migrar datos, dropear tablas viejas, limpiar modelos

**Este es el punto donde nada en el código de la app referencia ya `usuarios_aplicaciones`/`usuario_aplicacion_secciones` ni `Usuario::aplicaciones()`/`seccionesAplicaciones()` — es seguro dropear.**

**Files:**
- Create: `backend/database/migrations/2026_09_01_000003_migrar_accesos_directos_a_tipos_usuario.php`
- Modify: `backend/app/Models/Usuario.php` (quita `aplicaciones()`/`seccionesAplicaciones()`, renombra `seccionesDeAplicacionPorTipo()` → `seccionesDeAplicacion()`)
- Modify: `backend/app/Models/AplicacionExterna.php` (quita `usuarios()`)
- Delete: `backend/app/Models/UsuarioAplicacion.php`, `backend/app/Models/UsuarioAplicacionSeccion.php`
- Modify: `backend/tests/Feature/AplicacionesModeloTest.php`
- Modify: `backend/app/Http/Controllers/LauncherController.php` (renombra la llamada de vuelta a `seccionesDeAplicacion()`)

**Interfaces:**
- Produces: `Usuario::seccionesDeAplicacion(string): array` vuelve a ser el nombre público final (mismo que tenía la API vieja, ahora resuelto por tipos).

- [ ] **Step 1: Reescribir `AplicacionesModeloTest.php` (RED: usa relaciones que después de este test todavía no cambiaron de nombre, así que primero falla por el nombre viejo del método)**

Reemplazar `backend/tests/Feature/AplicacionesModeloTest.php`:

```php
<?php
// apphub/backend/tests/Feature/AplicacionesModeloTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\AplicacionSeccion;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AplicacionesModeloTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplicacion_tiene_secciones_y_el_acceso_efectivo_se_deriva_del_tipo(): void
    {
        $app = AplicacionExterna::create([
            'codigo' => 'kpis-sso', 'nombre' => 'KPIs SSO El Abra',
            'url_base' => 'https://kpis-sso.lglabproyect.com', 'activo' => true,
        ]);
        $seccionCargar = AplicacionSeccion::create(['aplicacion_id' => $app->id, 'codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $seccionMetricas = AplicacionSeccion::create(['aplicacion_id' => $app->id, 'codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create();
        $tipo->secciones()->attach([
            $seccionCargar->id   => ['nivel' => 'editar'],
            $seccionMetricas->id => ['nivel' => 'ver'],
        ]);
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach($tipo->id);

        $this->assertCount(2, $app->fresh()->secciones);
        $this->assertTrue($usuario->tieneAccesoA('kpis-sso'));
        $this->assertSame(['kpis-sso'], $usuario->codigosDeAplicacionesConAcceso());
        $this->assertSame(
            ['cargar' => 'editar', 'metricas' => 'ver'],
            $usuario->seccionesDeAplicacion('kpis-sso')
        );
    }

    public function test_seccionesDeAplicacion_vacio_si_no_tiene_tipos(): void
    {
        AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPIs SSO', 'url_base' => 'https://x', 'activo' => true]);
        $usuario = Usuario::factory()->create();

        $this->assertSame([], $usuario->seccionesDeAplicacion('kpis-sso'));
        $this->assertFalse($usuario->tieneAccesoA('kpis-sso'));
    }

    public function test_dos_tipos_en_conflicto_de_nivel_gana_editar(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipoVer = TipoUsuario::factory()->create();
        $tipoVer->secciones()->attach($seccion->id, ['nivel' => 'ver']);
        $tipoEditar = TipoUsuario::factory()->create();
        $tipoEditar->secciones()->attach($seccion->id, ['nivel' => 'editar']);
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach([$tipoVer->id, $tipoEditar->id]);

        $this->assertSame(['metricas' => 'editar'], $usuario->seccionesDeAplicacion('kpis-sso'));
    }

    public function test_tipo_inactivo_no_aporta_acceso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create(['activo' => false]);
        $tipo->secciones()->attach($seccion->id, ['nivel' => 'ver']);
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach($tipo->id);

        $this->assertSame([], $usuario->seccionesDeAplicacion('kpis-sso'));
        $this->assertFalse($usuario->tieneAccesoA('kpis-sso'));
    }
}
```

- [ ] **Step 2: Correr el test, confirmar que falla**

Run: `php artisan test --filter AplicacionesModeloTest`
Expected: FAIL — el método todavía se llama `seccionesDeAplicacionPorTipo()`, no `seccionesDeAplicacion()` (que todavía existe con la implementación VIEJA basada en `usuarios_aplicaciones`).

- [ ] **Step 3: Renombrar el método en `Usuario`, quitar las relaciones viejas, limpiar `AplicacionExterna`**

En `backend/app/Models/Usuario.php`:
- Borrar el método viejo `seccionesDeAplicacion()` (el que usa `seccionesAplicaciones()->whereHas(...)`).
- Borrar los métodos `aplicaciones()` y `seccionesAplicaciones()`.
- Renombrar `seccionesDeAplicacionPorTipo()` → `seccionesDeAplicacion()`.
- Quitar los `use App\Models\AplicacionExterna;` y `use App\Models\AplicacionSeccion;` del encabezado (ya no se usan como tipos de retorno explícitos en este archivo).

El archivo final queda:

```php
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\DB;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'email',
        'password_hash',
        'rol_global',
        'activo',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'activo'        => 'boolean',
        'password_hash' => 'hashed',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function proyectos()
    {
        return $this->belongsToMany(Proyecto::class, 'usuarios_proyectos', 'usuario_id', 'proyecto_id')
            ->withPivot('rol', 'tipo_id')
            ->withTimestamps();
    }

    public function asignaciones()
    {
        return $this->hasMany(UsuarioProyecto::class, 'usuario_id');
    }

    public function tiposUsuario()
    {
        return $this->belongsToMany(TipoUsuario::class, 'usuarios_tipos_usuario', 'usuario_id', 'tipo_usuario_id')
            ->withTimestamps();
    }

    public function tieneAccesoA(string $codigoApp): bool
    {
        return $this->accesoEfectivoQuery()->where('a.codigo', $codigoApp)->exists();
    }

    /** @return string[] códigos de aplicaciones a las que el usuario tiene al menos una sección efectiva */
    public function codigosDeAplicacionesConAcceso(): array
    {
        return $this->accesoEfectivoQuery()->distinct()->pluck('a.codigo')->all();
    }

    /** @return array<string,string> codigo de sección => nivel efectivo ('ver'|'editar') para una app */
    public function seccionesDeAplicacion(string $codigoApp): array
    {
        return $this->accesoEfectivoQuery()
            ->where('a.codigo', $codigoApp)
            ->select('s.codigo as seccion_codigo', DB::raw("MAX(CASE WHEN tas.nivel = 'editar' THEN 1 ELSE 0 END) as gana_editar"))
            ->groupBy('s.codigo')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->seccion_codigo => $row->gana_editar ? 'editar' : 'ver'])
            ->all();
    }

    /**
     * Base de la unión de permisos: todos los tipos ACTIVOS asignados al usuario, unidos a las
     * secciones que cada uno otorga. "Gana editar" se resuelve en el llamador vía CASE WHEN,
     * NUNCA con MAX() directo sobre la columna nivel -- el enum se guarda como texto y ordena
     * alfabético ('editar' < 'ver'), así que MAX() de texto da el resultado contrario al
     * pretendido (confirmado real en Postgres y SQLite, hallazgo architect #5).
     */
    private function accesoEfectivoQuery()
    {
        return DB::table('usuarios_tipos_usuario as ut')
            ->join('tipos_usuario as t', 't.id', '=', 'ut.tipo_usuario_id')
            ->join('tipo_usuario_aplicacion_secciones as tas', 'tas.tipo_usuario_id', '=', 't.id')
            ->join('aplicaciones_secciones as s', 's.id', '=', 'tas.seccion_id')
            ->join('aplicaciones_externas as a', 'a.id', '=', 's.aplicacion_id')
            ->where('ut.usuario_id', $this->id)
            ->where('t.activo', true);
    }
}
```

En `backend/app/Http/Controllers/LauncherController.php`, reemplazar las 2 llamadas a `seccionesDeAplicacionPorTipo(` por `seccionesDeAplicacion(`.

En `backend/app/Models/AplicacionExterna.php`, quitar el método `usuarios()` huérfano (hallazgo `architect` #12 — un `belongsToMany` sobre `usuarios_aplicaciones`, que ya no existe, y que nada en el código llama):

```php
<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AplicacionExterna extends Model
{
    protected $table = 'aplicaciones_externas';
    protected $fillable = ['codigo', 'nombre', 'url_base', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function secciones()
    {
        return $this->hasMany(AplicacionSeccion::class, 'aplicacion_id');
    }
}
```

Borrar `backend/app/Models/UsuarioAplicacion.php` y `backend/app/Models/UsuarioAplicacionSeccion.php`.

- [ ] **Step 4: Escribir y correr la migración de datos + drop**

```php
<?php
// backend/database/migrations/2026_09_01_000003_migrar_accesos_directos_a_tipos_usuario.php
use App\Support\AccesoLegacyMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        AccesoLegacyMigrator::migrar();

        Schema::dropIfExists('usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_aplicaciones');
    }

    public function down(): void
    {
        // Best-effort (hallazgo architect #13): revertir esta migración en producción no es un
        // escenario esperado -- no se reconstruye el estado exacto pre-migración.
        throw new \RuntimeException(
            'Esta migración no es reversible: recrear usuarios_aplicaciones/usuario_aplicacion_secciones '.
            'desde tipos_usuario perdería la distinción "grant directo" original. Restaurar desde backup si hace falta revertir.'
        );
    }
};
```

Agregar un test de humo a `AplicacionesModeloTest.php` (al final de la clase, antes del `}`):

```php
    public function test_las_tablas_legacy_ya_no_existen_despues_de_migrar(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('usuarios_aplicaciones'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('usuario_aplicacion_secciones'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('usuarios_tipos_usuario'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('tipo_usuario_aplicacion_secciones'));
    }
```

- [ ] **Step 5: Correr el test, confirmar que pasa**

Run: `php artisan test --filter AplicacionesModeloTest`
Expected: PASS (5 tests).

- [ ] **Step 6: Correr el suite completo**

Run: `php artisan test`
Expected: PASS completo — este es el checkpoint más importante del plan: si algo todavía referenciaba las tablas/relaciones viejas, explota acá.

- [ ] **Step 7: Commit**

```bash
git rm backend/app/Models/UsuarioAplicacion.php backend/app/Models/UsuarioAplicacionSeccion.php
git add backend/database/migrations/2026_09_01_000003_migrar_accesos_directos_a_tipos_usuario.php backend/app/Models/Usuario.php backend/app/Models/AplicacionExterna.php backend/app/Http/Controllers/LauncherController.php backend/tests/Feature/AplicacionesModeloTest.php
git commit -m "feat: migra datos y dropea usuarios_aplicaciones/usuario_aplicacion_secciones"
```

---

### Task 11: Endurecimiento — `backend/start.sh` corta si `migrate` falla

**Hallazgo del spec (`architect` #2):** `backend/start.sh` corre `php artisan migrate --force` sin `set -e` — si la migración fallara, el script sigue igual a `db:seed`/arranque de PHP, sirviendo tráfico con el fallo silenciado. El riesgo específico de la migración de Task 10 ya está cubierto por la atomicidad de Postgres, pero esto es un endurecimiento de bajo costo que el spec pide de todas formas.

**Files:**
- Modify: `backend/start.sh`

- [ ] **Step 1: Leer el archivo actual**

Antes de editar, correr `cat backend/start.sh` para ver el shape exacto (no hay test automatizado razonable para un script de arranque de contenedor — la verificación es manual, ver Step 3).

- [ ] **Step 2: Agregar `set -e` al principio del script** (si no lo tiene ya) — o, si el script ya usa `set -e` pero el `migrate --force` está en una línea con `|| true` u otro supresor de error, quitar ese supresor. El objetivo: que un `migrate --force` fallido corte el arranque del contenedor en vez de seguir a `db:seed`/`php-fpm`.

- [ ] **Step 3: Verificación manual**

No hay test automatizado para esto (es un script de shell de arranque de contenedor, fuera del suite de PHPUnit). Verificar manualmente: `bash -n backend/start.sh` (chequeo de sintaxis) y, si es posible antes de desplegar, forzar un fallo de migración en un entorno de prueba (ej. Postgres apagado) y confirmar que el contenedor no llega a levantar `php-fpm`.

- [ ] **Step 4: Commit**

```bash
git add backend/start.sh
git commit -m "fix: start.sh corta el arranque si migrate --force falla"
```

---

### Task 12: Frontend — `superuser.html` cutover

**Files:**
- Modify: `backend/public/app/superuser.html`

**Interfaces:**
- Consumes: `GET/POST/PUT/DELETE /api/admin/tipos-usuario` (con `secciones`), `POST/PUT /api/admin/usuarios` (con `tipos`), `POST /api/admin/solicitudes/{id}/aprobar` (con `tipos`).

Este archivo ya tiene el reskin visual desplegado (2026-08-31) — esta task solo cambia estructura/lógica, no toca paleta/layout.

- [ ] **Step 1: Sidebar — quitar los ítems "Asignaciones" y "Aplicaciones"**

En el `<nav class="sidebar-nav">`, borrar los dos bloques `<button type="button" class="nav-item" data-tab="asignaciones">...</button>` y `<button type="button" class="nav-item" data-tab="aplicaciones">...</button>` completos (quedan 5 ítems: Proyectos, Usuarios, Tipos de Usuario, Solicitudes, Logs).

- [ ] **Step 2: Quitar los `<div id="tab-asignaciones">` y `<div id="tab-aplicaciones">` (paneles de tab)**

Borrar ambos bloques completos de `<main>`.

- [ ] **Step 3: Actualizar la tabla de "Tipos de Usuario" — sumar columna "Acceso"**

Reemplazar el `<thead>`/`<tbody>` del bloque `<div id="tab-tipos">`:

```html
    <!-- TAB: Tipos de Usuario -->
    <div id="tab-tipos" class="tab-panel hidden">
      <div style="display:flex;justify-content:flex-end;margin-bottom:12px">
        <button class="btn btn-primary btn-sm" id="btn-new-tipo">+ Nuevo Tipo</button>
      </div>
      <div class="card" style="padding:0;overflow:hidden">
        <table class="admin-table">
          <thead><tr><th>Nombre</th><th>Descripción</th><th>Acceso</th><th>Activo</th><th style="width:80px"></th></tr></thead>
          <tbody id="tbody-tipos"><tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
        </table>
      </div>
    </div>
```

- [ ] **Step 4: Quitar los modales "Asignación" y "Acceso a Aplicación"**

Borrar `<div class="modal-overlay" id="modal-asignacion">...</div>` y `<div class="modal-overlay" id="modal-acceso-aplicacion">...</div>` completos.

- [ ] **Step 5: Modal "Tipo de Usuario" — sumar el picker de secciones**

Insertar, dentro de `<form id="form-tipo">`, justo antes del `<div style="display:flex;gap:8px;justify-content:flex-end;...">` de los botones:

```html
        <div class="form-group">
          <label>Acceso a aplicaciones</label>
          <div id="tipo-secciones-lista" style="display:flex;flex-direction:column;gap:12px"></div>
        </div>
```

- [ ] **Step 6: Modal "Usuario" — sumar multi-select de tipos**

Insertar, dentro de `<form id="form-usuario">`, justo antes del `<div style="display:flex;gap:8px;...">` final:

```html
        <div class="form-group">
          <label>Tipos de Usuario</label>
          <div id="usuario-tipos-lista" style="display:flex;flex-direction:column;gap:6px"></div>
        </div>
```

- [ ] **Step 7: Modal "Solicitud" — reemplazar el picker por app por el multi-select de tipos**

Reemplazar el bloque:
```html
        <div class="form-group">
          <label>Aplicaciones (opcional)</label>
          <div id="solicitud-secciones-lista"></div>
        </div>
```
por:
```html
        <div class="form-group">
          <label>Tipos de Usuario (opcional)</label>
          <div id="solicitud-tipos-lista" style="display:flex;flex-direction:column;gap:6px"></div>
        </div>
```

- [ ] **Step 8: JS — `loadTab()`, quitar los casos retirados**

```javascript
    function loadTab(tab) {
      if (tab === 'proyectos')    loadProyectos();
      if (tab === 'usuarios')     loadUsuarios();
      if (tab === 'tipos')        loadTipos();
      if (tab === 'solicitudes')  loadSolicitudes();
      if (tab === 'logs')         loadLogs();
    }
```

- [ ] **Step 9: JS — reescribir el bloque `// ── TAB: Usuarios ──` completo**

```javascript
    // ── TAB: Usuarios ──────────────────────────────────────────────
    async function loadUsuarios() {
      if (!tiposCache.length) {
        const r = await apiGet('/admin/tipos-usuario');
        tiposCache = r?.data ?? [];
      }

      const resp = await apiGet('/admin/usuarios');
      usuariosCache = resp?.data ?? [];
      const tbody = document.getElementById('tbody-usuarios');
      if (!usuariosCache.length) {
        tbody.innerHTML = `<tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Sin usuarios.</td></tr>`;
        return;
      }
      tbody.innerHTML = usuariosCache.map(u => `
        <tr>
          <td>
            <span class="user-avatar">${initials(u.nombre)}</span>
            ${escHtml(u.nombre)}
          </td>
          <td style="color:var(--text-muted)">${escHtml(u.email)}</td>
          <td><span class="role-badge role-${u.rol_global === 'superuser' ? 'su' : u.rol_global === 'admin' ? 'admin' : 'usuario'}">${escHtml(u.rol_global)}</span></td>
          <td>${u.activo ? '<span style="color:var(--green)">Activo</span>' : '<span style="color:var(--text-dim)">Inactivo</span>'}</td>
          <td><div class="btn-row">
            <button class="btn-tbl" onclick="openEditUsuario(${u.id})">✏</button>
            ${u.rol_global !== 'superuser' ? `<button class="btn-tbl danger" onclick="deleteUsuario(${u.id})">✕</button>` : ''}
          </div></td>
        </tr>
      `).join('');
    }

    function initials(nombre) {
      return (nombre ?? '').split(' ').slice(0,2).map(w => w[0]?.toUpperCase()).join('');
    }

    function renderTiposDelUsuario(containerId, tiposSeleccionadosIds = []) {
      const cont = document.getElementById(containerId);
      if (!tiposCache.length) {
        cont.innerHTML = `<span style="color:var(--text-muted);font-size:12px">No hay tipos de usuario definidos todavía.</span>`;
        return;
      }
      cont.innerHTML = tiposCache.filter(t => t.activo).map(t => `
        <label style="display:flex;align-items:center;gap:8px;font-weight:400;color:var(--text)">
          <input type="checkbox" class="chk-usuario-tipo" data-container="${containerId}" value="${t.id}" ${tiposSeleccionadosIds.includes(t.id) ? 'checked' : ''}/>
          <span>${escHtml(t.nombre)}</span>
        </label>
      `).join('');
    }

    let editUsuarioId = null;

    document.getElementById('btn-new-usuario').addEventListener('click', () => {
      editUsuarioId = null;
      document.getElementById('title-modal-usuario').textContent = 'Nuevo Usuario';
      document.getElementById('form-usuario').reset();
      document.getElementById('label-u-password').textContent = 'Contraseña *';
      document.getElementById('u-password').placeholder = 'Mínimo 8 caracteres';
      clearErrors('form-usuario');
      renderTiposDelUsuario('usuario-tipos-lista', []);
      document.getElementById('modal-usuario').classList.add('open');
    });

    window.openEditUsuario = (id) => {
      editUsuarioId = id;
      const u = usuariosCache.find(x => x.id === id);
      document.getElementById('title-modal-usuario').textContent = 'Editar Usuario';
      document.getElementById('u-nombre').value = u.nombre;
      document.getElementById('u-email').value  = u.email;
      document.getElementById('u-rol').value    = u.rol_global;
      document.getElementById('u-activo').value = u.activo ? '1' : '0';
      document.getElementById('u-password').value = '';
      document.getElementById('label-u-password').textContent = 'Contraseña (dejar vacío para no cambiar)';
      document.getElementById('u-password').placeholder = 'Dejar vacío para mantener';
      clearErrors('form-usuario');
      renderTiposDelUsuario('usuario-tipos-lista', (u.tipos_usuario ?? []).map(t => t.id));
      document.getElementById('modal-usuario').classList.add('open');
    };

    window.deleteUsuario = async (id) => {
      const u = usuariosCache.find(x => x.id === id);
      if (!confirm(`¿Eliminar a ${u.nombre}? Esta acción no se puede deshacer.`)) return;
      try {
        await apiDelete(`/admin/usuarios/${id}`);
        loadUsuarios();
      } catch (err) { alert(err?.message ?? 'Error al eliminar.'); }
    };

    document.getElementById('close-modal-usuario').addEventListener('click', () => document.getElementById('modal-usuario').classList.remove('open'));
    document.getElementById('cancel-usuario').addEventListener('click', () => document.getElementById('modal-usuario').classList.remove('open'));

    document.getElementById('form-usuario').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-usuario');
      const nombre   = document.getElementById('u-nombre').value.trim();
      const email    = document.getElementById('u-email').value.trim();
      const password = document.getElementById('u-password').value;
      const rol      = document.getElementById('u-rol').value;
      const activo   = document.getElementById('u-activo').value === '1';
      const tipos = [...document.querySelectorAll('.chk-usuario-tipo[data-container="usuario-tipos-lista"]:checked')].map(chk => Number(chk.value));

      let valid = true;
      if (!nombre) { showErr('err-u-nombre'); valid = false; }
      if (!email)  { showErr('err-u-email');  valid = false; }
      if (!editUsuarioId && !password) { showErr('err-u-password'); valid = false; }
      if (!valid) return;

      const body = { nombre, email, rol_global: rol, activo, tipos };
      if (password) body.password = password;

      const btn = document.getElementById('submit-usuario');
      btn.disabled = true;
      try {
        if (editUsuarioId) {
          await apiPut(`/admin/usuarios/${editUsuarioId}`, body);
        } else {
          await apiPost('/admin/usuarios', body);
        }
        document.getElementById('modal-usuario').classList.remove('open');
        loadUsuarios();
      } catch (err) {
        handleFormError(err, { nombre: 'err-u-nombre', email: 'err-u-email', password: 'err-u-password' });
      } finally { btn.disabled = false; }
    });
```

- [ ] **Step 10: JS — reescribir el bloque `// ── TAB: Tipos de Usuario ──` completo (reemplaza también la vieja "TAB: Asignaciones" y "TAB: Aplicaciones" que se borran enteras)**

```javascript
    // ── TAB: Tipos de Usuario ──────────────────────────────────────
    let aplicacionesCache = [];

    async function loadTipos() {
      if (!aplicacionesCache.length) {
        const r = await apiGet('/admin/aplicaciones');
        aplicacionesCache = r?.data ?? [];
      }

      const resp = await apiGet('/admin/tipos-usuario');
      tiposCache = resp?.data ?? [];
      const tbody = document.getElementById('tbody-tipos');
      if (!tiposCache.length) {
        tbody.innerHTML = `<tr><td colspan="5" style="padding:20px;text-align:center;color:var(--text-muted)">Sin tipos definidos.</td></tr>`;
        return;
      }
      tbody.innerHTML = tiposCache.map(t => `
        <tr>
          <td>${escHtml(t.nombre)}</td>
          <td style="color:var(--text-muted)">${escHtml(t.descripcion ?? '—')}</td>
          <td style="color:var(--text-muted);font-size:11px">${(t.secciones_resumen ?? []).map(s => `${escHtml(s.aplicacion_codigo)}.${escHtml(s.codigo)}:${escHtml(s.nivel)}`).join(', ') || '—'}</td>
          <td>${t.activo ? '<span style="color:var(--green)">Sí</span>' : '<span style="color:var(--text-dim)">No</span>'}</td>
          <td><div class="btn-row">
            <button class="btn-tbl" onclick="openEditTipo(${t.id})">✏</button>
            <button class="btn-tbl danger" onclick="deleteTipo(${t.id})">✕</button>
          </div></td>
        </tr>
      `).join('');
    }

    function renderSeccionesDelTipo(seccionesSeleccionadas = []) {
      const cont = document.getElementById('tipo-secciones-lista');
      const nivelPorSeccion = new Map(seccionesSeleccionadas.map(s => [s.seccion_id, s.nivel]));
      if (!aplicacionesCache.length) {
        cont.innerHTML = `<span style="color:var(--text-muted);font-size:12px">No hay aplicaciones definidas todavía.</span>`;
        return;
      }
      cont.innerHTML = aplicacionesCache.map(app => `
        <fieldset style="border:1px solid var(--border);border-radius:8px;padding:8px 10px">
          <legend style="font-size:11px;color:var(--text-muted)">${escHtml(app.nombre)}</legend>
          ${(app.secciones ?? []).map(s => {
            const marcado = nivelPorSeccion.has(s.id);
            const nivel = nivelPorSeccion.get(s.id) ?? 'ver';
            return `
              <label style="display:flex;align-items:center;gap:8px;font-weight:400;color:var(--text);margin:4px 0">
                <input type="checkbox" class="chk-tipo-seccion" value="${s.id}" ${marcado ? 'checked' : ''}/>
                <span style="flex:1">${escHtml(s.nombre)}</span>
                <select class="nivel-tipo-seccion" data-seccion="${s.id}" style="width:auto">
                  <option value="ver" ${nivel === 'ver' ? 'selected' : ''}>Ver</option>
                  <option value="editar" ${nivel === 'editar' ? 'selected' : ''}>Ver y editar/cargar</option>
                </select>
              </label>`;
          }).join('') || '<span style="color:var(--text-muted);font-size:12px">Sin secciones.</span>'}
        </fieldset>
      `).join('');
    }

    let editTipoId = null;

    document.getElementById('btn-new-tipo').addEventListener('click', () => {
      editTipoId = null;
      document.getElementById('title-modal-tipo').textContent = 'Nuevo Tipo de Usuario';
      document.getElementById('form-tipo').reset();
      clearErrors('form-tipo');
      renderSeccionesDelTipo([]);
      document.getElementById('modal-tipo').classList.add('open');
    });

    window.openEditTipo = (id) => {
      editTipoId = id;
      const t = tiposCache.find(x => x.id === id);
      document.getElementById('title-modal-tipo').textContent = 'Editar Tipo';
      document.getElementById('t-nombre').value      = t.nombre;
      document.getElementById('t-descripcion').value = t.descripcion ?? '';
      document.getElementById('t-activo').value      = t.activo ? '1' : '0';
      clearErrors('form-tipo');
      renderSeccionesDelTipo(t.secciones_resumen ?? []);
      document.getElementById('modal-tipo').classList.add('open');
    };

    window.deleteTipo = async (id) => {
      const t = tiposCache.find(x => x.id === id);
      if (!confirm(`¿Eliminar tipo "${t.nombre}"?`)) return;
      try {
        await apiDelete(`/admin/tipos-usuario/${id}`);
        loadTipos();
      } catch (err) {
        // El backend devuelve 409 si el tipo tiene usuarios asignados -- mostrar el mensaje tal cual.
        alert(err?.message ?? 'Error.');
      }
    };

    document.getElementById('close-modal-tipo').addEventListener('click', () => document.getElementById('modal-tipo').classList.remove('open'));
    document.getElementById('cancel-tipo').addEventListener('click', () => document.getElementById('modal-tipo').classList.remove('open'));

    document.getElementById('form-tipo').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-tipo');
      const nombre      = document.getElementById('t-nombre').value.trim();
      const descripcion = document.getElementById('t-descripcion').value.trim();
      const activo      = document.getElementById('t-activo').value === '1';
      const secciones = [...document.querySelectorAll('.chk-tipo-seccion:checked')].map(chk => ({
        seccion_id: Number(chk.value),
        nivel: document.querySelector(`.nivel-tipo-seccion[data-seccion="${chk.value}"]`).value,
      }));

      if (!nombre) { showErr('err-t-nombre'); return; }

      const btn = document.getElementById('submit-tipo');
      btn.disabled = true;
      try {
        if (editTipoId) {
          await apiPut(`/admin/tipos-usuario/${editTipoId}`, { nombre, descripcion, activo, secciones });
        } else {
          await apiPost('/admin/tipos-usuario', { nombre, descripcion, activo, secciones });
        }
        document.getElementById('modal-tipo').classList.remove('open');
        loadTipos();
      } catch (err) {
        handleFormError(err, { nombre: 'err-t-nombre' });
      } finally { btn.disabled = false; }
    });
```

- [ ] **Step 11: JS — reescribir el bloque `// ── TAB: Solicitudes ──` (solo `openApprove` y el submit de `form-solicitud` cambian; `loadSolicitudes`/`rejectSolicitud` quedan igual)**

Reemplazar `window.openApprove` y el `addEventListener('submit', ...)` de `form-solicitud`:

```javascript
    window.openApprove = async (id) => {
      const r = solicitudesCache.find(x => x.id === id);
      if (!r) return;
      document.getElementById('s-id').value       = r.id;
      document.getElementById('s-nombre').value   = r.nombre;
      document.getElementById('s-email').value    = r.email;
      document.getElementById('s-password').value = '';
      document.getElementById('s-rol').value      = 'usuario';
      clearErrors('form-solicitud');

      if (!tiposCache.length) {
        const r2 = await apiGet('/admin/tipos-usuario');
        tiposCache = r2?.data ?? [];
      }
      renderTiposDelUsuario('solicitud-tipos-lista', []);

      document.getElementById('modal-solicitud').classList.add('open');
    };

    window.rejectSolicitud = async (id) => {
      if (!confirm('¿Rechazar esta solicitud de acceso?')) return;
      try {
        await apiPost(`/admin/solicitudes/${id}/rechazar`);
        loadSolicitudes();
      } catch (err) { alert(err?.message ?? 'Error.'); }
    };

    document.getElementById('close-modal-solicitud').addEventListener('click', () => document.getElementById('modal-solicitud').classList.remove('open'));
    document.getElementById('cancel-solicitud').addEventListener('click', () => document.getElementById('modal-solicitud').classList.remove('open'));

    document.getElementById('form-solicitud').addEventListener('submit', async e => {
      e.preventDefault();
      clearErrors('form-solicitud');
      const id       = document.getElementById('s-id').value;
      const nombre   = document.getElementById('s-nombre').value.trim();
      const email    = document.getElementById('s-email').value.trim();
      const password = document.getElementById('s-password').value;
      const rol      = document.getElementById('s-rol').value;
      const tipos = [...document.querySelectorAll('.chk-usuario-tipo[data-container="solicitud-tipos-lista"]:checked')].map(chk => Number(chk.value));

      let valid = true;
      if (!nombre)   { showErr('err-s-nombre');   valid = false; }
      if (!email)    { showErr('err-s-email');     valid = false; }
      if (!password) { showErr('err-s-password');  valid = false; }
      if (!valid) return;

      const btn = document.getElementById('submit-solicitud');
      btn.disabled = true;
      try {
        await apiPost(`/admin/solicitudes/${id}/aprobar`, { nombre, email, password, rol_global: rol, tipos });
        document.getElementById('modal-solicitud').classList.remove('open');
        loadSolicitudes();
        loadUsuarios();
      } catch (err) {
        handleFormError(err, { nombre: 'err-s-nombre', email: 'err-s-email', password: 'err-s-password' });
      } finally { btn.disabled = false; }
    });

    document.getElementById('btn-reload-solicitudes').addEventListener('click', loadSolicitudes);

    let solicitudesCache = [];
```

- [ ] **Step 12: Borrar del todo lo que quedó huérfano**

Confirmar que ya no existen en el archivo: `loadAsignaciones`, `deleteAsignacion`, los listeners de `btn-new-asignacion`/`close-modal-asignacion`/`cancel-asignacion`/`form-asignacion`, `loadAccesosAplicacion`, `renderSeccionesDelForm`, `revocarAcceso`, los listeners de `btn-reload-aplicaciones`/`btn-nuevo-acceso`/`close-modal-acceso-aplicacion`/`cancel-acceso-aplicacion`/`acceso-aplicacion-id`/`form-acceso-aplicacion`. (Buscar con `grep -n "asignacion\|acceso-aplicacion" backend/public/app/superuser.html` — case-insensitive — debe devolver 0 resultados de JS/HTML activo relacionado a esos dos mecanismos retirados.)

- [ ] **Step 13: Verificación manual en el navegador**

No hay test automatizado de frontend en este repo (sin Playwright configurado para apphub). Verificación manual, con el contenedor `apphub` corriendo la imagen nueva:
1. Entrar como superuser, confirmar que el sidebar tiene 5 ítems (sin Asignaciones/Aplicaciones).
2. Crear un Tipo de Usuario con 2 secciones de niveles distintos, confirmar que aparecen en la columna "Acceso".
3. Editar ese tipo solo tocando "Activo" sin tocar el picker — confirmar que las secciones siguen ahí después de guardar (recargando la tabla).
4. Crear un Usuario asignándole ese tipo, confirmar en la tabla/modal que quedó asociado.
5. Aprobar una Solicitud de prueba asignándole un tipo, confirmar que el usuario nuevo puede ver la app correspondiente en su Launcher.
6. Intentar borrar un tipo con usuarios asignados — confirmar el 409 con el mensaje.

- [ ] **Step 14: Commit**

```bash
git add backend/public/app/superuser.html
git commit -m "feat: superuser.html -- acceso por Tipo de Usuario reemplaza Asignaciones/Aplicaciones"
```

---

### Task 13: Verificación final y cierre

**Files:** ninguno nuevo — solo verificación.

- [ ] **Step 1: Suite completo**

Run: `php artisan test`
Expected: PASS completo, 0 failures.

- [ ] **Step 2: `code-reviewer` + `security-reviewer` en paralelo sobre el diff completo**

Pedido explícito del spec ("Verificación", pendiente): `AccesoAplicacionController`/`AsignacionController` manejaban permisos directamente, la revisión de seguridad no es opcional acá. Correr ambos agentes contra `git diff main...HEAD` (o el rango de commits de este plan) antes de mergear.

- [ ] **Step 3: Verificación manual en producción (post-deploy) — login de punta a punta**

Confirmar por SSH que el usuario real (`luisgarnica@hotmail.cl`) migrado por `AccesoLegacyMigrator` puede loguearse en kpis-sso de punta a punta (el handoff firmado de `LauncherController::entrar`) antes de dar la tarea por cerrada — es el único camino de producción que depende de `seccionesDeAplicacion()`. El panel de superuser se autoriza por `rol_global`, no por tipos, así que aunque algo saliera mal con la migración de datos no hay riesgo de lockout del panel (peor caso: reasignar el tipo a mano desde la UI).

- [ ] **Step 4: Actualizar la bitácora**

Agregar una entrada a `docs/superpowers/bitacoras/2026-08-31-superuser-reskin-y-tipos-usuario.md` (o una nueva bitácora fechada el día del deploy) documentando: que se implementó, la fecha real de deploy, el resultado de `code-reviewer`/`security-reviewer`, y la confirmación del login end-to-end.

---

## Self-Review

**Cobertura del spec:**
- Modelo de datos (tablas nuevas, sin `aplicacion_id` denormalizado, `onDelete cascade`, guarda 409, unique en `nombre`) → Tasks 2, 6.
- Migración de datos (pre-flight, un tipo por usuario, idempotencia, atomicidad Postgres) → Tasks 3, 10.
- Backend (`Usuario`, `TipoUsuario`, controllers, eliminación de `AccesoAplicacionController`/`AsignacionController`/modelos/rutas) → Tasks 4-11.
- Frontend (sidebar, modal de Tipo con picker, modal de Usuario con tipos, modal de Solicitud con tipos) → Task 12.
- Testing (inventario completo: `AccesoAplicacionControllerTest`→`TipoUsuarioControllerTest`, 4 tests de Asignaciones en `AdminTest`, `LauncherControllerTest` con 6 usos de `attach()`, `SolicitudControllerTest`, `AplicacionesModeloTest`, tests nuevos de conflicto/tipo inactivo/`tieneAccesoA`/`codigosDeAplicacionesConAcceso`, test de la migración de datos) → cubierto en cada task correspondiente.
- Hallazgo no cubierto por el spec pero real (enum de `usuarios_aplicaciones_log` sin `UPDATE`) → Task 1.
- Endurecimiento de `start.sh` → Task 11.
- `code-reviewer`/`security-reviewer` + verificación de login end-to-end → Task 13.

**Sin placeholders:** todo el código de este plan compila contra los archivos reales leídos del repo (verificado antes de escribir el plan) — sin "TODO", sin "similar a la Task N" sin repetir el código, sin pasos sin código para pasos de código.

**Consistencia de tipos:** `seccionesDeAplicacion(string): array` mantiene la misma firma pública en `Usuario` de principio a fin (Task 5 la introduce temporalmente como `seccionesDeAplicacionPorTipo()` para no chocar con la versión vieja que todavía usan `SolicitudController`/tests hasta Task 8; Task 10 la renombra de vuelta y borra la versión vieja — verificado que todas las llamadras se actualizan en el mismo Step). `TipoUsuarioController`/`UsuarioController` usan la misma convención de logging (`entidad_id` = tipo o usuario según cuál lado del pivot se edita) documentada en Global Constraints y aplicada igual en Tasks 6, 7, 8.

## Execution Handoff

Plan completo y guardado en `docs/superpowers/plans/2026-09-01-tipos-usuario-acceso-global.md`. Dos opciones de ejecución:

**1. Subagent-Driven (recomendado)** — despacho un subagente fresco por task, reviso entre tasks, iteración rápida.

**2. Inline Execution** — ejecuto las tasks en esta sesión con executing-plans, ejecución por lotes con checkpoints.

**¿Cuál preferís?**
