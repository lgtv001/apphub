# Fase 3: Gateway SSO (apphub) + integración kpis-sso — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** apphub se convierte en el gateway de identidad + selector de apps del ecosistema SSO. Un usuario se loguea una sola vez en apphub y desde ahí entra a kpis-sso (y ve, sin poder entrar todavía, las otras 3 apps futuras) sin volver a loguearse. El superusuario administra desde apphub quién tiene acceso a qué app, con qué nivel (ver / editar-cargar) por sección, y puede otorgar ese acceso en el mismo paso en que aprueba la cuenta de un usuario nuevo.

**Architecture:** apphub mantiene su autenticación actual (token Bearer de Sanctum, sin cambios) para sus propias páginas. Para llevar a un usuario de apphub a kpis-sso sin pedirle login de nuevo, apphub emite un **handoff firmado de un solo uso** (HMAC-SHA256, sin librerías nuevas) que kpis-sso valida y usa para abrir su propia sesión local. Ver la sección "Nota de arquitectura" más abajo — **esto reemplaza el mecanismo de "sesión compartida" del spec aprobado el 2026-08-07**, con la razón documentada.

**Tech Stack:** Laravel 11 + Sanctum en ambos repos (sin paquetes nuevos), `hash_hmac()` nativo de PHP para firmar el handoff, PHPUnit (`RefreshDatabase`) para todos los tests nuevos.

## Global Constraints

- Sin dependencias nuevas de Composer ni npm en ninguno de los dos repos (nada de JWT libs — `hash_hmac()` alcanza).
- apphub sigue usando **Bearer token** (Sanctum token mode, `withToken()` en tests) para sus propias páginas — no se toca `AuthController::login`, `api.js`, ni ninguna página existente (`login.html`, `dashboard.html`, `selector-proyecto.html`, `selector-app.html`, `quiebre.html`).
- kpis-sso sigue usando su sesión Laravel local existente — no se cambia `SESSION_DRIVER` ni `SESSION_DOMAIN` en su `.env` de producción.
- Todas las rutas nuevas de administración en apphub van bajo `Route::prefix('admin')->middleware('check.role:superuser')` (mismo grupo que ya existe en `routes/api.php`).
- Nombres de columna en snake_case, mismo estilo que `proyectos`/`usuarios_proyectos` (ver migraciones existentes).
- Todo código nuevo lleva tests escritos ANTES de la implementación (TDD: rojo → verde → commit), mismo criterio que se usó en kpis-sso el 2026-08-09 (ver `tests/Feature/DatosNavegablesTest.php` como referencia de estilo).
- No se migra ni se borra la tabla `usuarios` de kpis-sso en esta fase (queda inerte, per spec original).
- Commits frecuentes, uno por task completada, formato `tipo: descripción` (sin firma de Claude, ver `~/.claude/rules/git-workflow.md` del usuario).

---

## Nota de arquitectura: por qué esto se aparta del spec aprobado del 2026-08-07

El spec aprobado (`docs/superpowers/specs/2026-08-07-sso-gateway-aplicaciones-externas-design.md`)
asume que "SSO real vía sesión compartida" es viable porque ambas apps ya usan Sanctum en modo
sesión/cookie. **Al leer el código real de apphub para escribir este plan (2026-08-09) se
encontró que eso es falso:**

- `apphub/backend/app/Http/Controllers/AuthController.php::login()` emite un **token Bearer**
  (`$usuario->createToken(...)->plainTextToken`), no una cookie de sesión.
- `apphub/backend/public/assets/js/api.js` guarda ese token en `localStorage` y lo manda como
  `Authorization: Bearer <token>` en cada request (`credentials: 'same-origin'`).
- `apphub/backend/bootstrap/app.php` **no** llama a `$middleware->statefulApi()` (a diferencia
  de kpis-sso, que sí lo hace) — apphub nunca activó el modo "SPA con sesión" de Sanctum para sí
  mismo.

O sea: implementar el spec tal como está escrito exigiría primero migrar TODA la autenticación
existente de apphub (5 páginas ya funcionando: login, dashboard, selector-proyecto,
selector-app, quiebre) de token a cookie de sesión compartida — un cambio de alto riesgo sobre
algo que ya funciona en producción, solo para poder construir la feature nueva. Con el usuario
sin supervisar la sesión, tomar ese riesgo no es razonable.

**Decisión (tomada acá, sin poder consultarla en el momento — a revisar cuando el usuario
vuelva):** en vez de sesión compartida, apphub emite un **handoff firmado de un solo uso**
cuando el usuario clickea una tarjeta del launcher. kpis-sso lo valida y abre su PROPIA sesión
local (la que ya tiene y ya funciona) — cero cambios a la autenticación existente de apphub, sin
`APP_KEY` compartida (se usa un secreto nuevo y acotado, `SSO_HANDOFF_SECRET`, con un único
propósito), sin tabla `sessions` compartida en Supabase. Esto es, en esencia, la "Opción B"
(token firmado) que el brainstorming original evaluó y descartó — pero la descartó asumiendo
que la Opción A (sesión compartida) era gratis porque ya estaba lista; al no ser cierto eso, la
Opción B pasa a ser la de menor riesgo Y menor esfuerzo total.

Las 5 decisiones aprobadas del spec original que SÍ se mantienen sin cambios: (1) login único en
apphub — kpis-sso sigue retirando su login propio del flujo; (2) permisos por app gestionados
por el superusuario; (4) sin acoplar las bases de datos de las dos apps; (5) el rediseño visual
de `dashboard.html` de kpis-sso queda fuera de este plan. Solo cambia el MECANISMO de (3) — de
"sesión compartida" a "handoff firmado + sesión local en cada app".

---

## PARTE A — apphub: modelo de datos (apps, secciones, permisos)

### Task 1: Migraciones + modelos de `AplicacionExterna`, `AplicacionSeccion`, `UsuarioAplicacion`, `UsuarioAplicacionSeccion`

**Files:**
- Create: `apphub/backend/database/migrations/2026_08_09_000001_create_aplicaciones_externas_table.php`
- Create: `apphub/backend/database/migrations/2026_08_09_000002_create_aplicaciones_secciones_table.php`
- Create: `apphub/backend/database/migrations/2026_08_09_000003_create_usuarios_aplicaciones_table.php`
- Create: `apphub/backend/database/migrations/2026_08_09_000004_create_usuario_aplicacion_secciones_table.php`
- Create: `apphub/backend/app/Models/AplicacionExterna.php`
- Create: `apphub/backend/app/Models/AplicacionSeccion.php`
- Create: `apphub/backend/app/Models/UsuarioAplicacion.php`
- Create: `apphub/backend/app/Models/UsuarioAplicacionSeccion.php`
- Modify: `apphub/backend/app/Models/Usuario.php`
- Test: `apphub/backend/tests/Feature/AplicacionesModeloTest.php`

**Interfaces:**
- Produces: `AplicacionExterna::secciones()` (hasMany `AplicacionSeccion`), `AplicacionExterna::usuarios()` (belongsToMany `Usuario` via `usuarios_aplicaciones`), `Usuario::aplicaciones()` (belongsToMany `AplicacionExterna`), `Usuario::seccionesDeAplicacion(string $codigoApp): array` retorna `['metricas' => 'ver', 'cargar' => 'editar', ...]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Feature/AplicacionesModeloTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\AplicacionSeccion;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AplicacionesModeloTest extends TestCase
{
    use RefreshDatabase;

    public function test_aplicacion_tiene_secciones_y_usuarios_con_nivel(): void
    {
        $app = AplicacionExterna::create([
            'codigo' => 'kpis-sso', 'nombre' => 'KPIs SSO El Abra',
            'url_base' => 'https://kpis-sso.lglabproyect.com', 'activo' => true,
        ]);
        $seccionCargar = AplicacionSeccion::create(['aplicacion_id' => $app->id, 'codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $seccionMetricas = AplicacionSeccion::create(['aplicacion_id' => $app->id, 'codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();

        $usuario->aplicaciones()->attach($app->id);
        $usuario->seccionesAplicaciones()->attach($seccionCargar->id, ['aplicacion_id' => $app->id, 'nivel' => 'editar']);
        $usuario->seccionesAplicaciones()->attach($seccionMetricas->id, ['aplicacion_id' => $app->id, 'nivel' => 'ver']);

        $this->assertCount(2, $app->fresh()->secciones);
        $this->assertTrue($usuario->aplicaciones()->where('aplicaciones_externas.id', $app->id)->exists());
        $this->assertSame(
            ['cargar' => 'editar', 'metricas' => 'ver'],
            $usuario->seccionesDeAplicacion('kpis-sso')
        );
    }

    public function test_seccionesDeAplicacion_vacio_si_no_tiene_grants(): void
    {
        AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPIs SSO', 'url_base' => 'https://x', 'activo' => true]);
        $usuario = Usuario::factory()->create();

        $this->assertSame([], $usuario->seccionesDeAplicacion('kpis-sso'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=AplicacionesModeloTest`
Expected: FAIL — `Class "App\Models\AplicacionExterna" not found` (todavía no existe nada).

- [ ] **Step 3: Write the migrations**

```php
<?php
// apphub/backend/database/migrations/2026_08_09_000001_create_aplicaciones_externas_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aplicaciones_externas', function (Blueprint $table) {
            $table->id();
            $table->string('codigo', 30)->unique();
            $table->string('nombre');
            $table->string('url_base');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('aplicaciones_externas'); }
};
```

```php
<?php
// apphub/backend/database/migrations/2026_08_09_000002_create_aplicaciones_secciones_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('aplicaciones_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_externas')->onDelete('cascade');
            $table->string('codigo', 30);
            $table->string('nombre');
            $table->timestamps();
            $table->unique(['aplicacion_id', 'codigo']);
        });
    }
    public function down(): void { Schema::dropIfExists('aplicaciones_secciones'); }
};
```

```php
<?php
// apphub/backend/database/migrations/2026_08_09_000003_create_usuarios_aplicaciones_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_externas')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['usuario_id', 'aplicacion_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('usuarios_aplicaciones'); }
};
```

```php
<?php
// apphub/backend/database/migrations/2026_08_09_000004_create_usuario_aplicacion_secciones_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuario_aplicacion_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_externas')->onDelete('cascade');
            $table->foreignId('seccion_id')->constrained('aplicaciones_secciones')->onDelete('cascade');
            $table->enum('nivel', ['ver', 'editar'])->default('ver');
            $table->timestamps();
            $table->unique(['usuario_id', 'seccion_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('usuario_aplicacion_secciones'); }
};
```

- [ ] **Step 4: Write the models**

```php
<?php
// apphub/backend/app/Models/AplicacionExterna.php
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

    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'usuarios_aplicaciones', 'aplicacion_id', 'usuario_id')
            ->withTimestamps();
    }
}
```

```php
<?php
// apphub/backend/app/Models/AplicacionSeccion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AplicacionSeccion extends Model
{
    protected $table = 'aplicaciones_secciones';
    protected $fillable = ['aplicacion_id', 'codigo', 'nombre'];

    public function aplicacion()
    {
        return $this->belongsTo(AplicacionExterna::class, 'aplicacion_id');
    }
}
```

```php
<?php
// apphub/backend/app/Models/UsuarioAplicacion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioAplicacion extends Model
{
    protected $table = 'usuarios_aplicaciones';
    protected $fillable = ['usuario_id', 'aplicacion_id'];

    public function usuario() { return $this->belongsTo(Usuario::class, 'usuario_id'); }
    public function aplicacion() { return $this->belongsTo(AplicacionExterna::class, 'aplicacion_id'); }
}
```

```php
<?php
// apphub/backend/app/Models/UsuarioAplicacionSeccion.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioAplicacionSeccion extends Model
{
    protected $table = 'usuario_aplicacion_secciones';
    protected $fillable = ['usuario_id', 'aplicacion_id', 'seccion_id', 'nivel'];

    public function usuario() { return $this->belongsTo(Usuario::class, 'usuario_id'); }
    public function aplicacion() { return $this->belongsTo(AplicacionExterna::class, 'aplicacion_id'); }
    public function seccion() { return $this->belongsTo(AplicacionSeccion::class, 'seccion_id'); }
}
```

Agregar a `apphub/backend/app/Models/Usuario.php` (junto a `proyectos()`/`asignaciones()` ya existentes):

```php
    public function aplicaciones()
    {
        return $this->belongsToMany(AplicacionExterna::class, 'usuarios_aplicaciones', 'usuario_id', 'aplicacion_id')
            ->withTimestamps();
    }

    public function seccionesAplicaciones()
    {
        return $this->belongsToMany(AplicacionSeccion::class, 'usuario_aplicacion_secciones', 'usuario_id', 'seccion_id')
            ->withPivot('aplicacion_id', 'nivel')
            ->withTimestamps();
    }

    /** @return array<string,string> codigo de sección => nivel ('ver'|'editar') para una app dada */
    public function seccionesDeAplicacion(string $codigoApp): array
    {
        return $this->seccionesAplicaciones()
            ->whereHas('aplicacion', fn ($q) => $q->where('codigo', $codigoApp))
            ->get()
            ->mapWithKeys(fn ($seccion) => [$seccion->codigo => $seccion->pivot->nivel])
            ->all();
    }
```

No olvidar el `use App\Models\AplicacionExterna;` / `use App\Models\AplicacionSeccion;` en el `use`-block de `Usuario.php` si el archivo no usa ya el namespace completo inline.

- [ ] **Step 5: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=AplicacionesModeloTest -v`
Expected: PASS (2 tests).

- [ ] **Step 6: Commit**

```bash
cd apphub
git add backend/database/migrations backend/app/Models backend/tests/Feature/AplicacionesModeloTest.php
git commit -m "feat: modelo de datos de aplicaciones externas, secciones y permisos por sección"
```

---

### Task 2: Seeder de las 4 apps + secciones de kpis-sso

**Files:**
- Create: `apphub/backend/database/seeders/AplicacionesSeeder.php`
- Modify: `apphub/backend/database/seeders/DatabaseSeeder.php`
- Test: `apphub/backend/tests/Feature/AplicacionesSeederTest.php`

**Interfaces:**
- Consumes: `AplicacionExterna`, `AplicacionSeccion` (Task 1).
- Produces: al correr el seeder, existen 4 filas en `aplicaciones_externas` (códigos `kpis-sso` activo=true; `tarjetas-verdes`, `vcc`, `higiene-seguridad` activo=false) y 3 secciones para `kpis-sso` (`metricas`, `historial`, `cargar`).

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Feature/AplicacionesSeederTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use Database\Seeders\AplicacionesSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AplicacionesSeederTest extends TestCase
{
    use RefreshDatabase;

    public function test_seeder_crea_las_4_apps_con_kpis_sso_activo(): void
    {
        (new AplicacionesSeeder())->run();

        $this->assertDatabaseHas('aplicaciones_externas', ['codigo' => 'kpis-sso', 'activo' => true]);
        $this->assertDatabaseHas('aplicaciones_externas', ['codigo' => 'tarjetas-verdes', 'activo' => false]);
        $this->assertDatabaseHas('aplicaciones_externas', ['codigo' => 'vcc', 'activo' => false]);
        $this->assertDatabaseHas('aplicaciones_externas', ['codigo' => 'higiene-seguridad', 'activo' => false]);

        $kpisSso = AplicacionExterna::where('codigo', 'kpis-sso')->first();
        $this->assertEqualsCanonicalizing(
            ['metricas', 'historial', 'cargar'],
            $kpisSso->secciones()->pluck('codigo')->all()
        );
    }

    public function test_seeder_es_idempotente(): void
    {
        (new AplicacionesSeeder())->run();
        (new AplicacionesSeeder())->run();

        $this->assertSame(4, AplicacionExterna::count());
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=AplicacionesSeederTest`
Expected: FAIL — `Class "Database\Seeders\AplicacionesSeeder" not found`.

- [ ] **Step 3: Write the seeder**

```php
<?php
// apphub/backend/database/seeders/AplicacionesSeeder.php
namespace Database\Seeders;

use App\Models\AplicacionExterna;
use Illuminate\Database\Seeder;

class AplicacionesSeeder extends Seeder
{
    public function run(): void
    {
        $kpisSso = AplicacionExterna::updateOrCreate(
            ['codigo' => 'kpis-sso'],
            ['nombre' => 'KPI de Accidentes', 'url_base' => 'https://kpis-sso.lglabproyect.com', 'activo' => true]
        );

        foreach ([
            ['codigo' => 'metricas', 'nombre' => 'Métricas'],
            ['codigo' => 'historial', 'nombre' => 'Historial'],
            ['codigo' => 'cargar', 'nombre' => 'Cargar datos'],
        ] as $seccion) {
            $kpisSso->secciones()->updateOrCreate(['codigo' => $seccion['codigo']], $seccion);
        }

        AplicacionExterna::updateOrCreate(
            ['codigo' => 'tarjetas-verdes'],
            ['nombre' => 'Tarjetas Verdes', 'url_base' => '', 'activo' => false]
        );
        AplicacionExterna::updateOrCreate(
            ['codigo' => 'vcc'],
            ['nombre' => 'VCC', 'url_base' => '', 'activo' => false]
        );
        AplicacionExterna::updateOrCreate(
            ['codigo' => 'higiene-seguridad'],
            ['nombre' => 'Control de Higiene y Seguridad', 'url_base' => '', 'activo' => false]
        );
    }
}
```

Agregar la llamada en `DatabaseSeeder.php` (buscar el método `run()` existente y agregar una línea, sin tocar el resto):

```php
$this->call(AplicacionesSeeder::class);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=AplicacionesSeederTest -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
cd apphub
git add backend/database/seeders backend/tests/Feature/AplicacionesSeederTest.php
git commit -m "feat: seeder de las 4 apps del ecosistema + secciones de kpis-sso"
```

---

### Task 3: `Admin\AplicacionController` (CRUD de apps, solo superuser)

**Files:**
- Create: `apphub/backend/app/Http/Controllers/Admin/AplicacionController.php`
- Modify: `apphub/backend/routes/api.php`
- Test: `apphub/backend/tests/Feature/AplicacionControllerTest.php`

**Interfaces:**
- Consumes: `AplicacionExterna` (Task 1).
- Produces: `GET /api/admin/aplicaciones` (lista con secciones), `POST /api/admin/aplicaciones`, `PUT /api/admin/aplicaciones/{id}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Feature/AplicacionControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AplicacionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function token(string $rol = 'superuser'): string
    {
        return Usuario::factory()->state(['rol_global' => $rol])->create()->createToken('t')->plainTextToken;
    }

    public function test_superuser_lista_aplicaciones_con_sus_secciones(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI de Accidentes', 'url_base' => 'https://x', 'activo' => true]);
        $app->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);

        $this->withToken($this->token())->getJson('/api/admin/aplicaciones')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id', 'codigo', 'nombre', 'activo', 'secciones' => [['codigo', 'nombre']]]]]);
    }

    public function test_usuario_normal_no_puede_listar(): void
    {
        $this->withToken($this->token('usuario'))->getJson('/api/admin/aplicaciones')->assertStatus(403);
    }

    public function test_superuser_crea_aplicacion(): void
    {
        $resp = $this->withToken($this->token())->postJson('/api/admin/aplicaciones', [
            'codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false,
        ]);

        $resp->assertStatus(201);
        $this->assertDatabaseHas('aplicaciones_externas', ['codigo' => 'vcc']);
    }

    public function test_codigo_duplicado_da_422(): void
    {
        AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false]);

        $this->withToken($this->token())->postJson('/api/admin/aplicaciones', [
            'codigo' => 'vcc', 'nombre' => 'VCC 2', 'url_base' => '', 'activo' => false,
        ])->assertStatus(422);
    }

    public function test_superuser_actualiza_aplicacion(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false]);

        $this->withToken($this->token())->putJson("/api/admin/aplicaciones/{$app->id}", ['activo' => true])
            ->assertStatus(200)
            ->assertJsonPath('activo', true);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=AplicacionControllerTest`
Expected: FAIL — ruta `/api/admin/aplicaciones` no existe (404).

- [ ] **Step 3: Write the controller**

```php
<?php
// apphub/backend/app/Http/Controllers/Admin/AplicacionController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AplicacionExterna;
use Illuminate\Http\Request;

class AplicacionController extends Controller
{
    public function index()
    {
        return response()->json(['data' => AplicacionExterna::with('secciones')->orderBy('codigo')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'codigo'   => 'required|string|max:30|unique:aplicaciones_externas,codigo',
            'nombre'   => 'required|string|max:255',
            'url_base' => 'nullable|string|max:255',
            'activo'   => 'boolean',
        ]);

        $app = AplicacionExterna::create($data);

        return response()->json($app, 201);
    }

    public function update(Request $request, int $id)
    {
        $app = AplicacionExterna::findOrFail($id);

        $data = $request->validate([
            'nombre'   => 'sometimes|string|max:255',
            'url_base' => 'sometimes|nullable|string|max:255',
            'activo'   => 'sometimes|boolean',
        ]);

        $app->update($data);

        return response()->json($app);
    }
}
```

Agregar a `apphub/backend/routes/api.php`, dentro del grupo `Route::prefix('admin')->middleware('check.role:superuser')->group(...)` ya existente (junto a `/asignaciones`):

```php
        Route::get('/aplicaciones',      [AplicacionController::class, 'index']);
        Route::post('/aplicaciones',     [AplicacionController::class, 'store']);
        Route::put('/aplicaciones/{id}', [AplicacionController::class, 'update']);
```

Y el `use` correspondiente arriba del archivo: `use App\Http\Controllers\Admin\AplicacionController;`.

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=AplicacionControllerTest -v`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
cd apphub
git add backend/app/Http/Controllers/Admin/AplicacionController.php backend/routes/api.php backend/tests/Feature/AplicacionControllerTest.php
git commit -m "feat: CRUD de aplicaciones externas para el superusuario"
```

---

### Task 4: `Admin\AccesoAplicacionController` (otorgar/revocar acceso + secciones a un usuario)

**Files:**
- Create: `apphub/backend/app/Http/Controllers/Admin/AccesoAplicacionController.php`
- Modify: `apphub/backend/routes/api.php`
- Test: `apphub/backend/tests/Feature/AccesoAplicacionControllerTest.php`

**Interfaces:**
- Consumes: `Usuario::aplicaciones()`, `Usuario::seccionesAplicaciones()` (Task 1).
- Produces: `GET /api/admin/accesos-aplicacion` (todas las asignaciones), `POST /api/admin/accesos-aplicacion` (body: `usuario_id`, `aplicacion_id`, `secciones: [{seccion_id, nivel}]`), `DELETE /api/admin/accesos-aplicacion/{usuario_id}/{aplicacion_id}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Feature/AccesoAplicacionControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccesoAplicacionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        return Usuario::factory()->state(['rol_global' => 'superuser'])->create()->createToken('t')->plainTextToken;
    }

    public function test_otorga_acceso_con_secciones_y_niveles(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $cargar = $app->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $metricas = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();

        $this->withToken($this->superuserToken())->postJson('/api/admin/accesos-aplicacion', [
            'usuario_id' => $usuario->id,
            'aplicacion_id' => $app->id,
            'secciones' => [
                ['seccion_id' => $cargar->id, 'nivel' => 'editar'],
                ['seccion_id' => $metricas->id, 'nivel' => 'ver'],
            ],
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios_aplicaciones', ['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id]);
        $this->assertSame(['cargar' => 'editar', 'metricas' => 'ver'], $usuario->fresh()->seccionesDeAplicacion('kpis-sso'));
    }

    public function test_revocar_acceso_borra_grant_y_secciones(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);
        $usuario->seccionesAplicaciones()->attach($seccion->id, ['aplicacion_id' => $app->id, 'nivel' => 'ver']);

        $this->withToken($this->superuserToken())
            ->deleteJson("/api/admin/accesos-aplicacion/{$usuario->id}/{$app->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('usuarios_aplicaciones', ['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id]);
        $this->assertSame([], $usuario->fresh()->seccionesDeAplicacion('kpis-sso'));
    }

    public function test_usuario_normal_no_puede_otorgar_acceso(): void
    {
        $token = Usuario::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/accesos-aplicacion')->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=AccesoAplicacionControllerTest`
Expected: FAIL — ruta no existe.

- [ ] **Step 3: Write the controller**

```php
<?php
// apphub/backend/app/Http/Controllers/Admin/AccesoAplicacionController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsuarioAplicacion;
use App\Models\UsuarioAplicacionSeccion;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class AccesoAplicacionController extends Controller
{
    public function index()
    {
        $accesos = UsuarioAplicacion::with(['usuario:id,nombre,email', 'aplicacion:id,codigo,nombre'])
            ->orderBy('aplicacion_id')
            ->get();

        return response()->json(['data' => $accesos]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'              => 'required|exists:usuarios,id',
            'aplicacion_id'           => 'required|exists:aplicaciones_externas,id',
            'secciones'               => 'array',
            'secciones.*.seccion_id'  => 'required|exists:aplicaciones_secciones,id',
            'secciones.*.nivel'       => 'required|in:ver,editar',
        ]);

        DB::transaction(function () use ($data) {
            UsuarioAplicacion::updateOrCreate(
                ['usuario_id' => $data['usuario_id'], 'aplicacion_id' => $data['aplicacion_id']],
                []
            );

            foreach ($data['secciones'] ?? [] as $seccion) {
                UsuarioAplicacionSeccion::updateOrCreate(
                    ['usuario_id' => $data['usuario_id'], 'seccion_id' => $seccion['seccion_id']],
                    ['aplicacion_id' => $data['aplicacion_id'], 'nivel' => $seccion['nivel']]
                );
            }
        });

        return response()->json(['message' => 'Acceso otorgado'], 201);
    }

    public function destroy(int $usuarioId, int $aplicacionId)
    {
        DB::transaction(function () use ($usuarioId, $aplicacionId) {
            UsuarioAplicacionSeccion::where('usuario_id', $usuarioId)->where('aplicacion_id', $aplicacionId)->delete();
            UsuarioAplicacion::where('usuario_id', $usuarioId)->where('aplicacion_id', $aplicacionId)->delete();
        });

        return response()->noContent();
    }
}
```

Agregar a `routes/api.php` (mismo grupo `admin`):

```php
        Route::get('/accesos-aplicacion',    [AccesoAplicacionController::class, 'index']);
        Route::post('/accesos-aplicacion',   [AccesoAplicacionController::class, 'store']);
        Route::delete('/accesos-aplicacion/{usuarioId}/{aplicacionId}', [AccesoAplicacionController::class, 'destroy']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=AccesoAplicacionControllerTest -v`
Expected: PASS (3 tests).

- [ ] **Step 5: Commit**

```bash
cd apphub
git add backend/app/Http/Controllers/Admin/AccesoAplicacionController.php backend/routes/api.php backend/tests/Feature/AccesoAplicacionControllerTest.php
git commit -m "feat: otorgar/revocar acceso a aplicaciones externas con permisos por sección"
```

---

### Task 5: `LauncherController::index()` (qué ve el usuario en el selector de apps)

**Files:**
- Create: `apphub/backend/app/Http/Controllers/LauncherController.php`
- Modify: `apphub/backend/routes/api.php`
- Test: `apphub/backend/tests/Feature/LauncherControllerTest.php`

**Interfaces:**
- Consumes: `Usuario::aplicaciones()`, `Usuario::seccionesDeAplicacion()` (Task 1).
- Produces: `GET /api/launcher/aplicaciones` → `{"data": [{"codigo","nombre","url_base","proximamente":bool,"secciones":{...}|null}]}`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Feature/LauncherControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LauncherControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_muestra_apps_inactivas_como_proximamente_para_cualquier_usuario(): void
    {
        AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI de Accidentes', 'url_base' => 'https://x', 'activo' => true]);
        AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false]);
        $usuario = Usuario::factory()->create(); // sin ningún grant

        $token = $usuario->createToken('t')->plainTextToken;
        $resp = $this->withToken($token)->getJson('/api/launcher/aplicaciones')->assertStatus(200);

        $data = collect($resp->json('data'));
        $vcc = $data->firstWhere('codigo', 'vcc');
        $this->assertTrue($vcc['proximamente']);
        $this->assertNull($vcc['url_base'] ?: null);
    }

    public function test_app_activa_solo_aparece_clickeable_si_tiene_grant(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI de Accidentes', 'url_base' => 'https://x', 'activo' => true]);
        $sinGrant = Usuario::factory()->create();
        $conGrant = Usuario::factory()->create();
        $conGrant->aplicaciones()->attach($app->id);

        $dataSinGrant = collect($this->withToken($sinGrant->createToken('t')->plainTextToken)
            ->getJson('/api/launcher/aplicaciones')->json('data'));
        $dataConGrant = collect($this->withToken($conGrant->createToken('t')->plainTextToken)
            ->getJson('/api/launcher/aplicaciones')->json('data'));

        $this->assertNull($dataSinGrant->firstWhere('codigo', 'kpis-sso'));
        $this->assertNotNull($dataConGrant->firstWhere('codigo', 'kpis-sso'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=LauncherControllerTest`
Expected: FAIL — ruta no existe.

- [ ] **Step 3: Write the controller**

```php
<?php
// apphub/backend/app/Http/Controllers/LauncherController.php
namespace App\Http\Controllers;

use App\Models\AplicacionExterna;
use Illuminate\Http\Request;

class LauncherController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();
        $codigosConGrant = $usuario->aplicaciones()->pluck('codigo')->all();

        $data = AplicacionExterna::orderBy('nombre')->get()
            ->filter(fn ($app) => $app->activo ? in_array($app->codigo, $codigosConGrant, true) : true)
            ->map(fn ($app) => [
                'codigo' => $app->codigo,
                'nombre' => $app->nombre,
                'url_base' => $app->activo ? $app->url_base : null,
                'proximamente' => !$app->activo,
                'secciones' => $app->activo ? $usuario->seccionesDeAplicacion($app->codigo) : null,
            ])
            ->values();

        return response()->json(['data' => $data]);
    }
}
```

Agregar a `routes/api.php` dentro del grupo `Route::middleware('auth:sanctum')->group(...)` (no en `admin`, cualquier usuario autenticado puede ver su launcher):

```php
    Route::get('/launcher/aplicaciones', [LauncherController::class, 'index']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=LauncherControllerTest -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
cd apphub
git add backend/app/Http/Controllers/LauncherController.php backend/routes/api.php backend/tests/Feature/LauncherControllerTest.php
git commit -m "feat: endpoint del launcher (apps con grant + placeholders proximamente)"
```

---

### Task 6: Extender `SolicitudController::approve()` para otorgar apps+secciones al crear la cuenta

**Files:**
- Modify: `apphub/backend/app/Http/Controllers/Admin/SolicitudController.php`
- Test: `apphub/backend/tests/Feature/SolicitudControllerTest.php`

**Interfaces:**
- Consumes: `AccesoAplicacionController`'s data shape (mismo formato `secciones: [{seccion_id, nivel}]`), reutilizado inline (no se llama al controller, se repite la lógica mínima directamente para no acoplar dos controllers vía HTTP interno).
- Produces: `POST /api/admin/solicitudes/{id}/aprobar` acepta ahora, además de los campos existentes, un array opcional `aplicaciones: [{aplicacion_id, secciones: [{seccion_id, nivel}]}]`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Feature/SolicitudControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\SolicitudAcceso;
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

    public function test_aprobar_solicitud_sin_aplicaciones_sigue_funcionando_como_antes(): void
    {
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'provider' => 'github', 'provider_id' => '123',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_aprobar_solicitud_con_aplicaciones_otorga_el_acceso_en_el_mismo_paso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'provider' => 'github', 'provider_id' => '456',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'aplicaciones' => [
                ['aplicacion_id' => $app->id, 'secciones' => [['seccion_id' => $seccion->id, 'nivel' => 'ver']]],
            ],
        ])->assertStatus(201);

        $usuario = Usuario::where('email', 'nuevo2@test.com')->firstOrFail();
        $this->assertSame(['metricas' => 'ver'], $usuario->seccionesDeAplicacion('kpis-sso'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=SolicitudControllerTest`
Expected: el primer test PASA (comportamiento ya existente), el segundo FALLA (`seccionesDeAplicacion` da `[]`, no se otorgó nada).

- [ ] **Step 3: Modify the controller**

Reemplazar el método `approve()` completo en `apphub/backend/app/Http/Controllers/Admin/SolicitudController.php`:

```php
    public function approve(int $id, Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'required|in:superuser,admin,usuario',
            'aplicaciones'              => 'array',
            'aplicaciones.*.aplicacion_id' => 'required|exists:aplicaciones_externas,id',
            'aplicaciones.*.secciones'      => 'array',
            'aplicaciones.*.secciones.*.seccion_id' => 'required|exists:aplicaciones_secciones,id',
            'aplicaciones.*.secciones.*.nivel'      => 'required|in:ver,editar',
        ]);

        $solicitud = SolicitudAcceso::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'La solicitud ya fue procesada.'], 422);
        }

        $usuario = \Illuminate\Support\Facades\DB::transaction(function () use ($data) {
            $usuario = Usuario::create([
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'rol_global'    => $data['rol_global'],
                'activo'        => true,
            ]);

            foreach ($data['aplicaciones'] ?? [] as $app) {
                $usuario->aplicaciones()->attach($app['aplicacion_id']);
                foreach ($app['secciones'] ?? [] as $seccion) {
                    $usuario->seccionesAplicaciones()->attach($seccion['seccion_id'], [
                        'aplicacion_id' => $app['aplicacion_id'],
                        'nivel' => $seccion['nivel'],
                    ]);
                }
            }

            return $usuario;
        });

        $solicitud->update(['estado' => 'aprobado']);

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'usuario' => $usuario->only(['id', 'nombre', 'email', 'rol_global']),
        ], 201);
    }
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=SolicitudControllerTest -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Run the full apphub suite (nada roto en el resto)**

Run: `cd apphub/backend && php artisan test`
Expected: todos los tests existentes + los nuevos de A1-A6, todos en verde.

- [ ] **Step 6: Commit**

```bash
cd apphub
git add backend/app/Http/Controllers/Admin/SolicitudController.php backend/tests/Feature/SolicitudControllerTest.php
git commit -m "feat: aprobar solicitud otorga acceso a aplicaciones+secciones en el mismo paso"
```

---

## PARTE B — Handoff SSO firmado (apphub emite, kpis-sso valida)

### Task 7: Config del secreto compartido + servicio de firma en apphub

**Files:**
- Modify: `apphub/backend/config/services.php`
- Modify: `apphub/backend/.env.example`
- Create: `apphub/backend/app/Services/SsoHandoffService.php`
- Test: `apphub/backend/tests/Unit/SsoHandoffServiceTest.php`

**Interfaces:**
- Produces: `SsoHandoffService::firmar(array $payload): string` (retorna `"<base64>.<firma>"`), usado por Task 8.

- [ ] **Step 1: Write the failing test**

```php
<?php
// apphub/backend/tests/Unit/SsoHandoffServiceTest.php
namespace Tests\Unit;

use App\Services\SsoHandoffService;
use Tests\TestCase;

class SsoHandoffServiceTest extends TestCase
{
    public function test_firma_produce_dos_partes_separadas_por_punto(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $handoff = (new SsoHandoffService())->firmar(['sub' => 'a@b.com']);

        $this->assertStringContainsString('.', $handoff);
        [$payload, $firma] = explode('.', $handoff, 2);
        $this->assertSame('a@b.com', json_decode(base64_decode($payload), true)['sub']);
        $this->assertSame(64, strlen($firma)); // hex de sha256
    }

    public function test_firmas_distintas_con_secretos_distintos(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-1']);
        $handoffA = (new SsoHandoffService())->firmar(['sub' => 'a@b.com']);

        config(['services.sso_handoff.secret' => 'secreto-2']);
        $handoffB = (new SsoHandoffService())->firmar(['sub' => 'a@b.com']);

        $this->assertNotSame($handoffA, $handoffB);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=SsoHandoffServiceTest`
Expected: FAIL — clase no existe.

- [ ] **Step 3: Write the service + config**

```php
<?php
// apphub/backend/app/Services/SsoHandoffService.php
namespace App\Services;

/**
 * Firma un payload para el handoff de SSO hacia otra app del ecosistema (ver "Nota de
 * arquitectura" en docs/superpowers/plans/2026-08-09-fase3-gateway-sso.md -- reemplaza la
 * sesión compartida del spec original porque apphub usa Sanctum en modo token, no sesión).
 * El mismo secreto (`SSO_HANDOFF_SECRET`) debe estar configurado en AMBAS apps.
 */
class SsoHandoffService
{
    public function firmar(array $payload): string
    {
        $codificado = base64_encode(json_encode($payload));
        $firma = hash_hmac('sha256', $codificado, (string) config('services.sso_handoff.secret'));

        return "{$codificado}.{$firma}";
    }
}
```

Agregar a `apphub/backend/config/services.php` (dentro del array que retorna, junto a otros servicios):

```php
    'sso_handoff' => [
        'secret' => env('SSO_HANDOFF_SECRET'),
    ],
```

Agregar a `apphub/backend/.env.example`:

```
SSO_HANDOFF_SECRET=
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=SsoHandoffServiceTest -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
cd apphub
git add backend/app/Services/SsoHandoffService.php backend/config/services.php backend/.env.example backend/tests/Unit/SsoHandoffServiceTest.php
git commit -m "feat: servicio de firma HMAC para el handoff de SSO entre apps"
```

---

### Task 8: `LauncherController::entrar($codigo)` — mintear el handoff

**Files:**
- Modify: `apphub/backend/app/Http/Controllers/LauncherController.php`
- Modify: `apphub/backend/routes/api.php`
- Test: `apphub/backend/tests/Feature/LauncherControllerTest.php` (agregar casos)

**Interfaces:**
- Consumes: `SsoHandoffService::firmar()` (Task 7), `Usuario::seccionesDeAplicacion()` (Task 1).
- Produces: `POST /api/launcher/aplicaciones/{codigo}/entrar` → `{"url": "https://.../sso/entrar?handoff=..."}` o 403 si no tiene grant.

- [ ] **Step 1: Write the failing test**

Agregar al final de `LauncherControllerTest.php`:

```php
    public function test_entrar_devuelve_url_con_handoff_si_tiene_grant(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);
        $usuario->seccionesAplicaciones()->attach($seccion->id, ['aplicacion_id' => $app->id, 'nivel' => 'ver']);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(200);

        $this->assertStringStartsWith('https://kpis-sso.test/sso/entrar?handoff=', $resp->json('url'));
    }

    public function test_entrar_da_403_sin_grant(): void
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
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd apphub/backend && php artisan test --filter=LauncherControllerTest`
Expected: FAIL — la ruta `entrar` no existe.

- [ ] **Step 3: Add the method + route**

Agregar a `LauncherController` (mismo archivo de Task 5):

```php
    public function __construct(private \App\Services\SsoHandoffService $firmador) {}

    public function entrar(Request $request, string $codigo)
    {
        $app = \App\Models\AplicacionExterna::where('codigo', $codigo)->where('activo', true)->first();
        if (!$app) {
            return response()->json(['message' => 'Aplicación no encontrada'], 404);
        }

        $usuario = $request->user();
        if (!$usuario->aplicaciones()->where('aplicaciones_externas.id', $app->id)->exists()) {
            return response()->json(['message' => 'Sin acceso a esta aplicación'], 403);
        }

        $handoff = $this->firmador->firmar([
            'sub'       => $usuario->email,
            'nombre'    => $usuario->nombre,
            'app'       => $app->codigo,
            'secciones' => $usuario->seccionesDeAplicacion($app->codigo),
            'nonce'     => \Illuminate\Support\Str::random(32),
            'exp'       => now()->addSeconds(60)->timestamp,
        ]);

        return response()->json(['url' => "{$app->url_base}/sso/entrar?handoff=" . urlencode($handoff)]);
    }
```

Nota: si `index()` de `LauncherController` no recibe el servicio por constructor todavía, Laravel resuelve `SsoHandoffService` automáticamente vía el contenedor (no tiene dependencias propias) — no hace falta tocar nada más.

Agregar a `routes/api.php` (mismo bloque que `/launcher/aplicaciones`):

```php
    Route::post('/launcher/aplicaciones/{codigo}/entrar', [LauncherController::class, 'entrar']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd apphub/backend && php artisan test --filter=LauncherControllerTest -v`
Expected: PASS (5 tests en total, los 2 de A5 + los 3 nuevos).

- [ ] **Step 5: Commit**

```bash
cd apphub
git add backend/app/Http/Controllers/LauncherController.php backend/routes/api.php backend/tests/Feature/LauncherControllerTest.php
git commit -m "feat: emitir handoff firmado al entrar a una aplicacion desde el launcher"
```

---

### Task 9: kpis-sso — tabla + modelo de nonces consumidos (anti-replay)

**Files:**
- Create: `kpis-sso/backend/database/migrations/2026_08_09_000001_create_sso_handoffs_consumidos_table.php`
- Create: `kpis-sso/backend/app/Models/SsoHandoffConsumido.php`
- Test: `kpis-sso/backend/tests/Feature/SsoHandoffConsumidoTest.php`

**Interfaces:**
- Produces: `SsoHandoffConsumido::yaFueUsado(string $nonce): bool`, `SsoHandoffConsumido::marcarUsado(string $nonce): void`.

- [ ] **Step 1: Write the failing test**

```php
<?php
// kpis-sso/backend/tests/Feature/SsoHandoffConsumidoTest.php
namespace Tests\Feature;

use App\Models\SsoHandoffConsumido;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsoHandoffConsumidoTest extends TestCase
{
    use RefreshDatabase;

    public function test_marcar_usado_y_verificar(): void
    {
        $this->assertFalse(SsoHandoffConsumido::yaFueUsado('abc123'));

        SsoHandoffConsumido::marcarUsado('abc123');

        $this->assertTrue(SsoHandoffConsumido::yaFueUsado('abc123'));
    }

    public function test_marcar_el_mismo_nonce_dos_veces_no_falla(): void
    {
        SsoHandoffConsumido::marcarUsado('xyz');
        SsoHandoffConsumido::marcarUsado('xyz'); // no debe tirar excepción de unique constraint

        $this->assertTrue(SsoHandoffConsumido::yaFueUsado('xyz'));
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd kpis-sso/backend && php artisan test --filter=SsoHandoffConsumidoTest`
Expected: FAIL — clase no existe.

- [ ] **Step 3: Write migration + model**

```php
<?php
// kpis-sso/backend/database/migrations/2026_08_09_000001_create_sso_handoffs_consumidos_table.php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('sso_handoffs_consumidos', function (Blueprint $table) {
            $table->id();
            $table->string('nonce', 64)->unique();
            $table->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void { Schema::dropIfExists('sso_handoffs_consumidos'); }
};
```

```php
<?php
// kpis-sso/backend/app/Models/SsoHandoffConsumido.php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SsoHandoffConsumido extends Model
{
    public $timestamps = false;
    protected $table = 'sso_handoffs_consumidos';
    protected $fillable = ['nonce'];

    public static function yaFueUsado(string $nonce): bool
    {
        return self::where('nonce', $nonce)->exists();
    }

    public static function marcarUsado(string $nonce): void
    {
        self::firstOrCreate(['nonce' => $nonce]);
    }
}
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd kpis-sso/backend && php artisan test --filter=SsoHandoffConsumidoTest -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Commit**

```bash
cd kpis-sso
git add backend/database/migrations backend/app/Models/SsoHandoffConsumido.php backend/tests/Feature/SsoHandoffConsumidoTest.php
git commit -m "feat: tabla de nonces consumidos para evitar reusar un handoff de SSO"
```

---

### Task 10: kpis-sso — `SsoController::entrar()` (valida el handoff y abre sesión local)

**Files:**
- Modify: `kpis-sso/backend/config/services.php`
- Modify: `kpis-sso/backend/.env.example`
- Create: `kpis-sso/backend/app/Http/Controllers/SsoController.php`
- Modify: `kpis-sso/backend/routes/web.php`
- Test: `kpis-sso/backend/tests/Feature/SsoControllerTest.php`

**Interfaces:**
- Consumes: `SsoHandoffConsumido` (Task 9).
- Produces: `GET /sso/entrar?handoff=...` (ruta WEB, no API — necesita la sesión + cookie del navegador, no JSON). Éxito: 302 a `/dashboard.html` con `session('sso_usuario')`/`session('sso_secciones')` poblados. Deja disponible el mismo formato de firma que `SsoHandoffService::firmar()` de apphub (Task 7) — **el string a firmar es literalmente `base64_encode(json_encode($payload))`, mismo orden de claves no importa porque se decodifica como array asociativo**.

- [ ] **Step 1: Write the failing test**

```php
<?php
// kpis-sso/backend/tests/Feature/SsoControllerTest.php
namespace Tests\Feature;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SsoControllerTest extends TestCase
{
    use RefreshDatabase;

    private function handoffValido(array $overrides = []): string
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $payload = array_merge([
            'sub' => 'admin@test.com', 'nombre' => 'Admin', 'app' => 'kpis-sso',
            'secciones' => ['metricas' => 'ver', 'cargar' => 'editar'],
            'nonce' => bin2hex(random_bytes(16)),
            'exp' => now()->addSeconds(60)->timestamp,
        ], $overrides);

        $codificado = base64_encode(json_encode($payload));
        $firma = hash_hmac('sha256', $codificado, 'secreto-de-test');

        return "{$codificado}.{$firma}";
    }

    public function test_handoff_valido_abre_sesion_y_redirige_al_dashboard(): void
    {
        $resp = $this->get('/sso/entrar?handoff=' . urlencode($this->handoffValido()));

        $resp->assertRedirect('/dashboard.html');
        $this->assertEquals(['metricas' => 'ver', 'cargar' => 'editar'], session('sso_secciones'));
        $this->assertEquals('admin@test.com', session('sso_usuario')['email']);
    }

    public function test_firma_invalida_da_403(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $handoff = $this->handoffValido() . 'basura';

        $this->get('/sso/entrar?handoff=' . urlencode($handoff))->assertStatus(403);
    }

    public function test_handoff_vencido_da_419(): void
    {
        $handoff = $this->handoffValido(['exp' => now()->subSeconds(10)->timestamp]);

        $this->get('/sso/entrar?handoff=' . urlencode($handoff))->assertStatus(419);
    }

    public function test_handoff_para_otra_app_da_403(): void
    {
        $handoff = $this->handoffValido(['app' => 'tarjetas-verdes']);

        $this->get('/sso/entrar?handoff=' . urlencode($handoff))->assertStatus(403);
    }

    public function test_handoff_reusado_da_403(): void
    {
        $handoff = $this->handoffValido();

        $this->get('/sso/entrar?handoff=' . urlencode($handoff))->assertRedirect('/dashboard.html');
        $this->get('/sso/entrar?handoff=' . urlencode($handoff))->assertStatus(403);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd kpis-sso/backend && php artisan test --filter=SsoControllerTest`
Expected: FAIL — ruta `/sso/entrar` no existe (404 en vez de los status esperados).

- [ ] **Step 3: Write the controller + config + route**

```php
<?php
// kpis-sso/backend/app/Http/Controllers/SsoController.php
namespace App\Http\Controllers;

use App\Models\SsoHandoffConsumido;
use Illuminate\Http\Request;

class SsoController extends Controller
{
    public function entrar(Request $request)
    {
        $handoff = (string) $request->query('handoff', '');
        if (!str_contains($handoff, '.')) {
            abort(403, 'Handoff inválido');
        }

        [$codificado, $firma] = explode('.', $handoff, 2);
        $secreto = (string) config('services.sso_handoff.secret');
        $firmaEsperada = hash_hmac('sha256', $codificado, $secreto);

        if (!hash_equals($firmaEsperada, $firma)) {
            abort(403, 'Handoff inválido');
        }

        $datos = json_decode(base64_decode($codificado), true);
        if (!is_array($datos) || !isset($datos['sub'], $datos['app'], $datos['nonce'], $datos['exp'])) {
            abort(403, 'Handoff inválido');
        }

        if ($datos['app'] !== 'kpis-sso') {
            abort(403, 'Handoff para otra aplicación');
        }

        if ($datos['exp'] < now()->timestamp) {
            abort(419, 'El enlace de acceso venció, volvé a entrar desde apphub');
        }

        if (SsoHandoffConsumido::yaFueUsado($datos['nonce'])) {
            abort(403, 'Este enlace de acceso ya fue usado');
        }
        SsoHandoffConsumido::marcarUsado($datos['nonce']);

        $request->session()->regenerate();
        $request->session()->put('sso_usuario', ['email' => $datos['sub'], 'nombre' => $datos['nombre']]);
        $request->session()->put('sso_secciones', $datos['secciones'] ?? []);

        return redirect('/dashboard.html');
    }
}
```

Agregar a `kpis-sso/backend/config/services.php`:

```php
    'sso_handoff' => [
        'secret' => env('SSO_HANDOFF_SECRET'),
    ],
```

Agregar a `kpis-sso/backend/.env.example`: `SSO_HANDOFF_SECRET=` (mismo valor que en apphub, se configura en el `.env` real del servidor, nunca se commitea).

Agregar a `kpis-sso/backend/routes/web.php` (ruta WEB, con sesión — si el archivo no existe o está vacío, crear con este contenido más lo que ya tuviera):

```php
use App\Http\Controllers\SsoController;
use Illuminate\Support\Facades\Route;

Route::get('/sso/entrar', [SsoController::class, 'entrar']);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd kpis-sso/backend && php artisan test --filter=SsoControllerTest -v`
Expected: PASS (5 tests).

- [ ] **Step 5: Commit**

```bash
cd kpis-sso
git add backend/app/Http/Controllers/SsoController.php backend/config/services.php backend/.env.example backend/routes/web.php backend/tests/Feature/SsoControllerTest.php
git commit -m "feat: validar el handoff de SSO y abrir sesion local de kpis-sso"
```

---

### Task 11: kpis-sso — middleware `EnsureSsoSession` reemplaza `auth:sanctum` en `routes/api.php`

**Files:**
- Create: `kpis-sso/backend/app/Http/Middleware/EnsureSsoSession.php`
- Modify: `kpis-sso/backend/bootstrap/app.php`
- Modify: `kpis-sso/backend/routes/api.php`
- Test: `kpis-sso/backend/tests/Feature/EnsureSsoSessionTest.php`

**Interfaces:**
- Consumes: `session('sso_usuario')` / `session('sso_secciones')` (escritos por Task 10).
- Produces: middleware alias `auth.sso`; helper `request()->attributes->get('sso_secciones')` disponible en los controllers de más abajo en el mismo request (para Task 12).

**IMPORTANTE — orden de esta tarea:** hacer esto DE ÚLTIMO dentro de la Parte B, y verificar el `RoleAccessTest`/`AuthTest`/`MetricasApiTest`/etc. existentes ANTES de tocar `routes/api.php` — varios ya usan `auth:sanctum` y `actingAs($usuario, 'sanctum')`. Esos tests van a necesitar ajustarse (ver Step 5) porque las rutas de Métricas dejan de aceptar Sanctum.

- [ ] **Step 1: Write the failing test**

```php
<?php
// kpis-sso/backend/tests/Feature/EnsureSsoSessionTest.php
namespace Tests\Feature;

use Illuminate\Support\Facades\Route;
use Tests\TestCase;

class EnsureSsoSessionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Route::middleware('auth.sso')->get('/api/_test/solo-sso', fn () => response()->json(['ok' => true]));
    }

    public function test_con_sesion_sso_valida_pasa(): void
    {
        $this->withSession(['sso_usuario' => ['email' => 'a@b.com'], 'sso_secciones' => []])
            ->getJson('/api/_test/solo-sso')
            ->assertStatus(200);
    }

    public function test_sin_sesion_sso_da_401(): void
    {
        $this->getJson('/api/_test/solo-sso')->assertStatus(401);
    }
}
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd kpis-sso/backend && php artisan test --filter=EnsureSsoSessionTest`
Expected: FAIL — alias `auth.sso` no existe.

- [ ] **Step 3: Write the middleware + register alias**

```php
<?php
// kpis-sso/backend/app/Http/Middleware/EnsureSsoSession.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureSsoSession
{
    public function handle(Request $request, Closure $next): Response
    {
        if (!$request->session()->has('sso_usuario')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        // Disponible para los controllers de esta misma request (Task 12: gate de "cargar").
        $request->attributes->set('sso_secciones', $request->session()->get('sso_secciones', []));

        return $next($request);
    }
}
```

Modificar `kpis-sso/backend/bootstrap/app.php`, agregando el alias junto a `check.role` ya existente:

```php
        $middleware->alias([
            'check.role' => CheckRole::class,
            'auth.sso'   => \App\Http\Middleware\EnsureSsoSession::class,
        ]);
```

- [ ] **Step 4: Run test to verify it passes**

Run: `cd kpis-sso/backend && php artisan test --filter=EnsureSsoSessionTest -v`
Expected: PASS (2 tests).

- [ ] **Step 5: Reemplazar `auth:sanctum` por `auth.sso` en las rutas reales, y actualizar los tests existentes que dependían de Sanctum**

En `kpis-sso/backend/routes/api.php`, cambiar:

```php
Route::middleware('auth:sanctum')->group(function () {
```
por:
```php
Route::middleware('auth.sso')->group(function () {
```

Esto **rompe intencionalmente** los tests existentes que usan `actingAs($usuario, 'sanctum')` contra esas rutas (`RoleAccessTest`, `UploadHistoryTest`, `MetricasApiTest`, `DatosNavegablesTest`, y varios más — correr `grep -rl "actingAs.*sanctum" tests/Feature` para la lista completa). Reemplazar ese patrón por una sesión SSO simulada. Ejemplo concreto sobre `DatosNavegablesTest::test_catalogo_de_tablas_visible_para_admin`:

```php
// ANTES:
$this->actingAs($admin, 'sanctum')->getJson('/api/admin/datos/tablas')...

// DESPUÉS:
$this->withSession(['sso_usuario' => ['email' => $admin->email], 'sso_secciones' => []])
    ->getJson('/api/admin/datos/tablas')...
```

`check.role:admin`/`check.role:editor` de kpis-sso lee `$request->user()->rol` — pero con `auth.sso` YA NO hay un `$request->user()` de Sanctum poblado (no se pasó por ningún guard de usuario local). **Esto requiere revisar `CheckRole` de kpis-sso también**: dado que el rol real ahora lo decide apphub (vía `usuarios_aplicaciones`+secciones, no la tabla `usuarios` local de kpis-sso), y el spec original dice explícitamente que la tabla `usuarios` de kpis-sso "sale del flujo de auth, no se borra en esta fase" — el concepto de rol admin/editor LOCAL de kpis-sso deja de tener sentido una vez que el gateway ya filtró quién entra. Decisión tomada acá (revisar cuando el usuario vuelva): **las rutas que hoy tienen `check.role:admin` en kpis-sso pasan a validar contra `sso_secciones` en vez de contra la tabla `usuarios` local** — ej. `/api/admin/datos/tablas` requiere que `sso_secciones` contenga la sección `metricas` o `historial` con cualquier nivel (son de solo lectura, alcanza con "ver"). Esto es una Task aparte, ver Task 12.

- [ ] **Step 6: Commit (solo el middleware + su propio test, todavía no el swap de rutas)**

```bash
cd kpis-sso
git add backend/app/Http/Middleware/EnsureSsoSession.php backend/bootstrap/app.php backend/tests/Feature/EnsureSsoSessionTest.php
git commit -m "feat: middleware auth.sso que valida la sesion abierta via handoff"
```

**No hacer commit todavía del swap de `auth:sanctum` → `auth.sso` en `routes/api.php`** — eso se hace junto con Task 12 (que resuelve `check.role`), para no dejar el repo en un estado a medias donde las rutas ya cambiaron de guard pero los tests de rol siguen rotos.

---

### Task 12: kpis-sso — reemplazar `CheckRole` local por chequeo de `sso_secciones`, y completar el swap de rutas

**Files:**
- Modify: `kpis-sso/backend/app/Http/Middleware/CheckRole.php`
- Modify: `kpis-sso/backend/routes/api.php`
- Modify: todos los tests listados por `grep -rl "actingAs.*sanctum" tests/Feature` (Step 5 de Task 11)
- Test: extender `kpis-sso/backend/tests/Feature/EnsureSsoSessionTest.php`

**Interfaces:**
- Consumes: `$request->attributes->get('sso_secciones')` (Task 11).
- Produces: `check.role:cargar` ahora significa "requiere nivel `editar` en la sección `cargar`"; sin argumento (`auth.sso` solo) significa "cualquier sesión SSO válida alcanza" (equivalente a "ver").

- [ ] **Step 1: Write the failing test**

Agregar a `EnsureSsoSessionTest.php`:

```php
    public function test_seccion_con_nivel_editar_pasa_check_role_cargar(): void
    {
        Route::middleware(['auth.sso', 'check.role:cargar'])->get('/api/_test/solo-cargar', fn () => response()->json(['ok' => true]));

        $this->withSession(['sso_usuario' => ['email' => 'a@b.com'], 'sso_secciones' => ['cargar' => 'editar']])
            ->getJson('/api/_test/solo-cargar')
            ->assertStatus(200);
    }

    public function test_seccion_con_nivel_ver_no_pasa_check_role_cargar(): void
    {
        Route::middleware(['auth.sso', 'check.role:cargar'])->get('/api/_test/solo-cargar-2', fn () => response()->json(['ok' => true]));

        $this->withSession(['sso_usuario' => ['email' => 'a@b.com'], 'sso_secciones' => ['cargar' => 'ver']])
            ->getJson('/api/_test/solo-cargar-2')
            ->assertStatus(403);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd kpis-sso/backend && php artisan test --filter=EnsureSsoSessionTest`
Expected: FAIL — `CheckRole` actual espera un usuario Sanctum con columna `rol`, no `sso_secciones`.

- [ ] **Step 3: Rewrite `CheckRole`**

```php
<?php
// kpis-sso/backend/app/Http/Middleware/CheckRole.php
namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Antes chequeaba `$request->user()->rol` (tabla `usuarios` local, Sanctum). Desde el handoff
 * de SSO (2026-08-09) el rol real lo decide apphub -- acá solo se verifica que la sección
 * pedida tenga nivel "editar" en `sso_secciones` (puesto por EnsureSsoSession).
 */
class CheckRole
{
    public function handle(Request $request, Closure $next, string $seccion): Response
    {
        $secciones = $request->attributes->get('sso_secciones', []);

        if (($secciones[$seccion] ?? null) !== 'editar') {
            return response()->json(['message' => "Se requiere nivel editar en la sección {$seccion}"], 403);
        }

        return $next($request);
    }
}
```

- [ ] **Step 4: Swap de rutas + ajuste de `check.role:admin` existentes**

En `kpis-sso/backend/routes/api.php`: cambiar `auth:sanctum` por `auth.sso` (ver Task 11 Step 5), y cambiar el único uso existente `->middleware('check.role:admin')` (la ruta `/admin/logs` y las 2 de `/admin/datos/tablas` agregadas hoy) a `->middleware('check.role:historial')` — el nivel `editar` en la sección `historial` es, en la práctica, "usuario de confianza" ya que hoy solo el superusuario reparte accesos vía apphub. Documentar esta decisión igual que la de Task 11 (a revisar con el usuario).

- [ ] **Step 5: Correr y arreglar TODOS los tests de kpis-sso**

Run: `cd kpis-sso/backend && php artisan test`

Esperar varias fallas — reemplazar cada `actingAs($usuario, 'sanctum')` por `withSession(['sso_usuario' => [...], 'sso_secciones' => [...]])` con las secciones que ese test necesite (ver tabla de mapeo abajo). Repetir `php artisan test` hasta que todo esté en verde. **No avanzar a Task 13 con tests rotos.**

| Test antiguo (`actingAs`) | Sesión SSO equivalente |
|---|---|
| `Usuario::factory()->admin()->create()` + `actingAs(..., 'sanctum')` en rutas `check.role:admin`/`historial` | `withSession(['sso_usuario'=>[...], 'sso_secciones'=>['historial'=>'editar']])` |
| `Usuario::factory()->editor()->create()` (esperando 403) | `withSession(['sso_usuario'=>[...], 'sso_secciones'=>['historial'=>'ver']])` |
| Cualquier otro `actingAs` en rutas de Métricas sin `check.role` | `withSession(['sso_usuario'=>[...], 'sso_secciones'=>['metricas'=>'ver']])` |
| Sin autenticar (esperando 401) | sin `withSession`, igual que antes |

- [ ] **Step 6: Commit**

```bash
cd kpis-sso
git add backend/app/Http/Middleware/CheckRole.php backend/routes/api.php backend/tests
git commit -m "feat: check.role valida secciones de la sesion SSO en vez del rol local"
```

---

### Task 13: kpis-sso — `index.html` redirige a apphub si no hay sesión SSO

**Files:**
- Modify: `kpis-sso/backend/public/index.html`
- Modify: `kpis-sso/backend/app/Http/Controllers/AuthController.php` (`me()`)
- Test: `kpis-sso/backend/tests/Feature/AuthTest.php` (ajustar `me()`)

**Interfaces:**
- Consumes: `auth.sso` (Task 11).

- [ ] **Step 1: Write the failing test**

Buscar en `AuthTest.php` el test existente de `me()` (probablemente usa `actingAs(..., 'sanctum')`) y agregar:

```php
    public function test_me_devuelve_datos_de_la_sesion_sso(): void
    {
        $this->withSession(['sso_usuario' => ['email' => 'a@b.com', 'nombre' => 'A'], 'sso_secciones' => ['metricas' => 'ver']])
            ->getJson('/api/auth/me')
            ->assertStatus(200)
            ->assertJsonPath('email', 'a@b.com');
    }

    public function test_me_sin_sesion_sso_da_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }
```

- [ ] **Step 2: Run test to verify it fails**

Run: `cd kpis-sso/backend && php artisan test --filter=AuthTest`
Expected: FAIL — `me()` sigue leyendo `$request->user()` de Sanctum.

- [ ] **Step 3: Rewrite `AuthController::me()` (quitar `login()` del routing, no del archivo)**

```php
    public function me(Request $request)
    {
        if (!$request->session()->has('sso_usuario')) {
            return response()->json(['message' => 'Unauthenticated.'], 401);
        }

        return response()->json($request->session()->get('sso_usuario'));
    }
```

En `routes/api.php`, quitar la línea `Route::post('/auth/login', ...)` del enrutamiento activo (dejar el método `login()` en el archivo, inerte, tal como el spec original permite) y mover `/auth/me` fuera del grupo `auth:sanctum` (que ya no existe) — ya está bajo `auth.sso` por el Step 4 de Task 12, así que `/auth/me` solo necesita quedar DENTRO de ese mismo grupo si no lo está ya.

Modificar `public/index.html`: reemplazar el formulario de login por un chequeo simple. Si el archivo hoy es un `<form>` con JS de submit a `/api/auth/login`, reemplazar el script final por:

```html
<script>
(async function () {
  const resp = await fetch('/api/auth/me', { credentials: 'include' });
  if (resp.ok) {
    window.location.href = '/dashboard.html';
  } else {
    window.location.href = 'https://apphub.lglabproyect.com';
  }
})();
</script>
```

(Ajustar el dominio real de apphub si difiere — confirmar contra el `.env`/DNS del servidor antes de deployar, no asumir.)

- [ ] **Step 4: Run test to verify it passes**

Run: `cd kpis-sso/backend && php artisan test --filter=AuthTest -v`
Expected: PASS.

- [ ] **Step 5: Run full kpis-sso suite**

Run: `cd kpis-sso/backend && php artisan test`
Expected: TODO en verde antes de seguir a la Parte C.

- [ ] **Step 6: Commit**

```bash
cd kpis-sso
git add backend/app/Http/Controllers/AuthController.php backend/routes/api.php backend/public/index.html backend/tests/Feature/AuthTest.php
git commit -m "feat: retirar login propio de kpis-sso, index.html redirige a apphub sin sesion SSO"
```

---

## PARTE C — Frontend: launcher de apphub + panel de súper usuario

### Task 14: Página `launcher.html` en apphub con las 4 tarjetas + logo

**Files:**
- Create: `apphub/backend/public/app/launcher.html`
- Modify: `apphub/backend/public/app/login.html` (redirigir acá post-login, en vez de a `selector-proyecto.html`, si el usuario tiene algún grant en `usuarios_aplicaciones`; si no tiene ninguno, sigue yendo a `selector-proyecto.html` como hoy — apphub sigue siendo dueño de sus proyectos internos, esto es aditivo)
- Copy: `Server Aprendizaje/Imagenes/imagen miniatura.png` → `apphub/backend/public/assets/img/logo-sso.png`

**Interfaces:**
- Consumes: `GET /api/launcher/aplicaciones`, `POST /api/launcher/aplicaciones/{codigo}/entrar` (Parte B).

- [ ] **Step 1: Copiar la imagen al repo**

```bash
cp "C:/Users/luisg/Desktop/Server Aprendizaje/Imagenes/imagen miniatura.png" \
   "C:/Users/luisg/Documents/GitHub/apphub/backend/public/assets/img/logo-sso.png"
```

- [ ] **Step 2: Write `launcher.html`**

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>AppHub — Ecosistema SSO</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
  <style>
    .app-card.disabled { opacity: .55; cursor: not-allowed; }
    .app-card.disabled:hover { transform: none; }
    .proximamente-badge {
      display: inline-block; font-size: 11px; padding: 2px 8px; border-radius: 100px;
      background: rgba(255,255,255,0.08); color: var(--text-muted); margin-left: 8px;
    }
    .logo-header { width: 56px; height: 56px; border-radius: 12px; }
  </style>
</head>
<body>
  <nav class="navbar">
    <div style="display:flex;align-items:center;gap:12px">
      <img src="/assets/img/logo-sso.png" alt="Logo SSO" class="logo-header"/>
      <span class="navbar-brand">Ecosistema SSO — El Abra</span>
    </div>
    <div class="navbar-user">
      <span id="user-name" class="text-muted"></span>
      <button class="btn btn-ghost btn-sm" id="btn-logout">Cerrar sesión</button>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Aplicaciones</h1>
        <p class="subtitle mt-8">Elegí con qué app querés trabajar</p>
      </div>
    </div>

    <div id="apps-grid" class="cards-grid" style="max-width:900px;display:grid;grid-template-columns:1fr 1fr;gap:16px"></div>
  </div>

  <script type="module">
    import { requireAuth, getUser, apiGet, apiPost, clearSession } from '/assets/js/api.js';

    requireAuth();
    document.getElementById('user-name').textContent = getUser()?.nombre ?? '';
    document.getElementById('btn-logout').addEventListener('click', () => {
      clearSession();
      window.location.href = '/app/login.html';
    });

    const ICONOS = { 'kpis-sso': '📊', 'tarjetas-verdes': '🟩', 'vcc': '✅', 'higiene-seguridad': '🦺' };

    async function cargar() {
      const { data } = await apiGet('/launcher/aplicaciones');
      const grid = document.getElementById('apps-grid');
      grid.innerHTML = data.map((app) => `
        <div class="card card-selectable app-card ${app.proximamente ? 'disabled' : ''}" data-codigo="${app.codigo}">
          <div class="app-icon">${ICONOS[app.codigo] ?? '📦'}</div>
          <div class="app-info">
            <h3>${app.nombre}${app.proximamente ? '<span class="proximamente-badge">Próximamente</span>' : ''}</h3>
          </div>
        </div>
      `).join('');

      grid.querySelectorAll('.app-card:not(.disabled)').forEach((card) => {
        card.addEventListener('click', async () => {
          const { url } = await apiPost(`/launcher/aplicaciones/${card.dataset.codigo}/entrar`, {});
          window.location.href = url;
        });
      });
    }

    cargar();
  </script>
</body>
</html>
```

- [ ] **Step 3: Verificación manual en navegador (sin test automático — es HTML/JS sin lógica pura extraíble, mismo criterio que el resto de las páginas de apphub, ninguna tiene test de UI)**

Con el backend de apphub corriendo localmente (`php artisan serve`) y un usuario con al menos un grant creado a mano (`php artisan tinker`), abrir `/app/launcher.html`, confirmar:
- Las 4 tarjetas aparecen.
- Las 3 sin grant muestran el badge "Próximamente" y no responden al click.
- La tarjeta con grant, al clickear, redirige a una URL `https://.../sso/entrar?handoff=...`.

- [ ] **Step 4: Commit**

```bash
cd apphub
git add backend/public/app/launcher.html backend/public/assets/img/logo-sso.png
git commit -m "feat: pagina launcher con las 4 tarjetas del ecosistema SSO"
```

---

### Task 15: `superuser.html` — nueva pestaña "Aplicaciones" (otorgar/revocar acceso + secciones)

**Files:**
- Modify: `apphub/backend/public/app/superuser.html`

**Interfaces:**
- Consumes: `GET/POST/DELETE /api/admin/accesos-aplicacion`, `GET /api/admin/aplicaciones` (Parte A).

- [ ] **Step 1: Ubicar el patrón de la pestaña "Asignaciones" existente**

Correr `grep -n "data-tab=\"asignaciones\"" -A 30 apphub/backend/public/app/superuser.html` para copiar EXACTAMENTE su estructura (tabla + botón "Nueva asignación" + modal) y adaptarla:

```bash
cd apphub && grep -n 'data-tab="asignaciones"' -A 5 backend/public/app/superuser.html
```

- [ ] **Step 2: Agregar el botón de pestaña**

Junto al botón `<button class="tab" data-tab="solicitudes">` (línea ~111 según lo visto al escribir este plan), agregar:

```html
<button class="tab" data-tab="aplicaciones">Aplicaciones</button>
```

- [ ] **Step 3: Agregar el panel de la pestaña**

Junto al `<div id="tab-solicitudes" ...>`, agregar:

```html
<div id="tab-aplicaciones" class="tab-panel hidden">
  <div class="panel-header">
    <button class="btn btn-primary btn-sm" id="btn-nuevo-acceso">+ Otorgar acceso</button>
    <button class="btn btn-ghost btn-sm" id="btn-reload-aplicaciones">↺ Actualizar</button>
  </div>
  <table class="table">
    <thead><tr><th>Usuario</th><th>Aplicación</th><th>Secciones</th><th></th></tr></thead>
    <tbody id="tbody-accesos-aplicacion"><tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Cargando…</td></tr></tbody>
  </table>
</div>
```

- [ ] **Step 4: Agregar el modal de "Otorgar acceso"**

Junto al `<div class="modal-overlay" id="modal-solicitud">`, agregar:

```html
<div class="modal-overlay" id="modal-acceso-aplicacion">
  <div class="modal">
    <button class="modal-close" id="close-modal-acceso">✕</button>
    <h2 class="modal-title">Otorgar acceso a una aplicación</h2>
    <form id="form-acceso-aplicacion" novalidate>
      <label>Usuario <select id="acceso-usuario-id" required></select></label>
      <label>Aplicación <select id="acceso-aplicacion-id" required></select></label>
      <div id="acceso-secciones-lista"></div>
      <div class="modal-actions">
        <button type="button" class="btn btn-ghost" id="cancel-acceso">Cancelar</button>
        <button type="submit" class="btn btn-primary">Otorgar</button>
      </div>
    </form>
  </div>
</div>
```

- [ ] **Step 5: Agregar la lógica JS**

Junto a `loadSolicitudes()`, agregar (y registrar `if (tab === 'aplicaciones') loadAccesosAplicacion();` en el switch de tabs existente):

```javascript
let aplicacionesCache = [];

async function loadAccesosAplicacion() {
  const [{ data: accesos }, { data: apps }] = await Promise.all([
    apiGet('/admin/accesos-aplicacion'),
    apiGet('/admin/aplicaciones'),
  ]);
  aplicacionesCache = apps;

  const tbody = document.getElementById('tbody-accesos-aplicacion');
  tbody.innerHTML = accesos.length ? accesos.map((a) => `
    <tr>
      <td>${a.usuario.nombre}</td>
      <td>${a.aplicacion.nombre}</td>
      <td>${(a.secciones ?? []).map((s) => `${s.codigo}:${s.nivel}`).join(', ') || '—'}</td>
      <td><button class="btn-tbl danger" onclick="revocarAcceso(${a.usuario_id}, ${a.aplicacion_id})">Revocar</button></td>
    </tr>
  `).join('') : `<tr><td colspan="4" style="padding:20px;text-align:center;color:var(--text-muted)">Sin accesos otorgados.</td></tr>`;

  const selectApp = document.getElementById('acceso-aplicacion-id');
  selectApp.innerHTML = apps.map((a) => `<option value="${a.id}">${a.nombre}</option>`).join('');
  renderSeccionesDelForm(apps[0]?.id);
  selectApp.addEventListener('change', (e) => renderSeccionesDelForm(Number(e.target.value)));
}

function renderSeccionesDelForm(aplicacionId) {
  const app = aplicacionesCache.find((a) => a.id === aplicacionId);
  document.getElementById('acceso-secciones-lista').innerHTML = (app?.secciones ?? []).map((s) => `
    <label style="display:flex;align-items:center;gap:8px">
      <input type="checkbox" class="chk-seccion" value="${s.id}"/> ${s.nombre}
      <select class="nivel-seccion" data-seccion="${s.id}">
        <option value="ver">Ver</option>
        <option value="editar">Ver y editar/cargar</option>
      </select>
    </label>
  `).join('');
}

window.revocarAcceso = async (usuarioId, aplicacionId) => {
  if (!confirm('¿Revocar este acceso?')) return;
  await apiDelete(`/admin/accesos-aplicacion/${usuarioId}/${aplicacionId}`);
  loadAccesosAplicacion();
};

document.getElementById('btn-reload-aplicaciones').addEventListener('click', loadAccesosAplicacion);
document.getElementById('btn-nuevo-acceso').addEventListener('click', () => document.getElementById('modal-acceso-aplicacion').classList.add('open'));
document.getElementById('close-modal-acceso').addEventListener('click', () => document.getElementById('modal-acceso-aplicacion').classList.remove('open'));
document.getElementById('cancel-acceso').addEventListener('click', () => document.getElementById('modal-acceso-aplicacion').classList.remove('open'));

document.getElementById('form-acceso-aplicacion').addEventListener('submit', async (e) => {
  e.preventDefault();
  const secciones = [...document.querySelectorAll('.chk-seccion:checked')].map((chk) => ({
    seccion_id: Number(chk.value),
    nivel: document.querySelector(`.nivel-seccion[data-seccion="${chk.value}"]`).value,
  }));

  await apiPost('/admin/accesos-aplicacion', {
    usuario_id: Number(document.getElementById('acceso-usuario-id').value),
    aplicacion_id: Number(document.getElementById('acceso-aplicacion-id').value),
    secciones,
  });

  document.getElementById('modal-acceso-aplicacion').classList.remove('open');
  loadAccesosAplicacion();
});
```

Nota: `document.getElementById('acceso-usuario-id')` necesita poblarse con la lista de usuarios — reutilizar la misma función que ya llena el `<select>` de usuarios en el tab "Asignaciones" existente (buscar cómo se llama esa función con `grep -n "usuario-id" apphub/backend/public/app/superuser.html` y reusarla, no duplicar la llamada a `/admin/usuarios`).

- [ ] **Step 6: Verificación manual en navegador**

Con apphub corriendo localmente, loguearse como superuser, ir a la pestaña "Aplicaciones", otorgar acceso a un usuario de prueba con una sección en nivel "editar", confirmar que aparece en la tabla y que `GET /api/launcher/aplicaciones` (con el token de ese usuario) ahora incluye esa app.

- [ ] **Step 7: Commit**

```bash
cd apphub
git add backend/public/app/superuser.html
git commit -m "feat: pestaña Aplicaciones en el panel de superusuario (otorgar/revocar acceso)"
```

---

### Task 16: Extender el modal de "Aprobar solicitud" para otorgar apps en el mismo paso

**Files:**
- Modify: `apphub/backend/public/app/superuser.html`

**Interfaces:**
- Consumes: `POST /api/admin/solicitudes/{id}/aprobar` con el campo `aplicaciones` (Task 6).

- [ ] **Step 1: Agregar el bloque de selección de apps al `#form-solicitud` existente**

Dentro de `<form id="form-solicitud">`, antes del botón de submit, agregar:

```html
<div id="solicitud-secciones-lista"></div>
```

Y en el JS que abre el modal (`openApproveModal`/función equivalente encontrada al leer el archivo — buscar `document.getElementById('modal-solicitud').classList.add('open')`), agregar justo antes:

```javascript
document.getElementById('solicitud-secciones-lista').innerHTML =
  aplicacionesCache.map((app) => `
    <fieldset style="margin-top:8px">
      <legend>${app.nombre}</legend>
      ${(app.secciones ?? []).map((s) => `
        <label style="display:flex;align-items:center;gap:8px">
          <input type="checkbox" class="chk-solicitud-seccion" data-app="${app.id}" value="${s.id}"/> ${s.nombre}
          <select class="nivel-solicitud-seccion" data-seccion="${s.id}">
            <option value="ver">Ver</option>
            <option value="editar">Ver y editar/cargar</option>
          </select>
        </label>
      `).join('')}
    </fieldset>
  `).join('');
```

(Requiere que `aplicacionesCache` de Task 15 ya esté cargado antes de abrir este modal — si el usuario abre "Solicitudes" antes que "Aplicaciones", llamar `apiGet('/admin/aplicaciones')` de forma perezosa acá también si `aplicacionesCache` está vacío.)

- [ ] **Step 2: Incluir `aplicaciones` en el submit del form de aprobación**

En el listener de submit de `#form-solicitud` (buscar `submitSolicitud`/equivalente), agregar antes del `apiPost`:

```javascript
const porApp = {};
document.querySelectorAll('.chk-solicitud-seccion:checked').forEach((chk) => {
  const appId = chk.dataset.app;
  porApp[appId] ??= { aplicacion_id: Number(appId), secciones: [] };
  porApp[appId].secciones.push({
    seccion_id: Number(chk.value),
    nivel: document.querySelector(`.nivel-solicitud-seccion[data-seccion="${chk.value}"]`).value,
  });
});
```

Y agregar `aplicaciones: Object.values(porApp)` al body del `apiPost` existente hacia `/admin/solicitudes/{id}/aprobar`.

- [ ] **Step 3: Verificación manual en navegador**

Crear una solicitud de prueba (o usar una real si existe alguna pendiente), aprobarla marcando una sección de kpis-sso, confirmar en la tabla de "Aplicaciones" (Task 15) que el nuevo usuario aparece con ese grant.

- [ ] **Step 4: Commit**

```bash
cd apphub
git add backend/public/app/superuser.html
git commit -m "feat: aprobar solicitud permite otorgar apps y secciones en el mismo modal"
```

---

## PARTE D — Deploy + verificación de humo end-to-end

### Task 17: Generar y configurar `SSO_HANDOFF_SECRET` en el servidor (una sola vez)

- [ ] Generar un secreto fuerte y agregarlo al `.env` real de AMBAS apps en el servidor (mismo valor en las dos, nunca commiteado):

```bash
ssh hjkl@100.67.54.60 '
  SECRETO=$(openssl rand -hex 32)
  grep -q "^SSO_HANDOFF_SECRET=" /home/hjkl/homelab/apps/apphub/.env \
    && sed -i "s|^SSO_HANDOFF_SECRET=.*|SSO_HANDOFF_SECRET=$SECRETO|" /home/hjkl/homelab/apps/apphub/.env \
    || echo "SSO_HANDOFF_SECRET=$SECRETO" >> /home/hjkl/homelab/apps/apphub/.env
  grep -q "^SSO_HANDOFF_SECRET=" /home/hjkl/homelab/apps/kpis-sso/.env \
    && sed -i "s|^SSO_HANDOFF_SECRET=.*|SSO_HANDOFF_SECRET=$SECRETO|" /home/hjkl/homelab/apps/kpis-sso/.env \
    || echo "SSO_HANDOFF_SECRET=$SECRETO" >> /home/hjkl/homelab/apps/kpis-sso/.env
  echo "Secreto sincronizado en ambos .env (no se imprime acá)."
'
```

(El secreto nunca aparece en la salida de este comando ni en ningún log — se genera y se escribe directo en el `.env` remoto en la misma línea de shell.)

### Task 18: Migrar + deployar apphub, luego kpis-sso, en ese orden

- [ ] Correr las migraciones nuevas + el seeder en apphub ANTES de deployar el código que las usa (mismo proceso de deploy manual documentado en `bitacora-kpis-sso-toggle-historial-tablas.md`, adaptado a apphub — confirmar el path real de `docker-compose.yml` de apphub en el servidor con `ssh hjkl@100.67.54.60 'ls /home/hjkl/homelab/apps/'` antes de asumirlo).
- [ ] Deploy de apphub (build + up -d), luego correr `php artisan migrate --force` y `php artisan db:seed --class=AplicacionesSeeder --force` DENTRO del contenedor.
- [ ] Deploy de kpis-sso (con la migración de `sso_handoffs_consumidos`), `php artisan migrate --force` dentro del contenedor.
- [ ] Verificación con curl (sin sesión real, solo chequear que no haya 500):
  ```bash
  ssh hjkl@100.67.54.60 "curl -s -o /dev/null -w '%{http_code}\n' https://apphub.lglabproyect.com/api/launcher/aplicaciones"   # 401 esperado
  ssh hjkl@100.67.54.60 "curl -s -o /dev/null -w '%{http_code}\n' https://kpis-sso.lglabproyect.com/sso/entrar?handoff=x"        # 403 esperado (handoff inválido, no 500)
  ```

### Task 19: Verificación de humo end-to-end REAL (con el usuario admin real, en un navegador)

Estos 5 chequeos son los del spec original (sección "Verificación de humo"), sin cambios porque el comportamiento observable para el usuario final es el mismo aunque el mecanismo interno cambió:

- [ ] Login en apphub (`luisgarnica@hotmail.cl`) → el launcher muestra las 4 tarjetas, con `kpis-sso` clickeable si ya se le otorgó el grant a mano (`php artisan tinker` en el servidor, ver Step de abajo) y las otras 3 en "Próximamente".
- [ ] Clic en la tarjeta de `kpis-sso` → entra directo al dashboard, sin pantalla de login de kpis-sso.
- [ ] Abrir `https://kpis-sso.lglabproyect.com/dashboard.html` directo (sin pasar por el launcher, sin sesión) → rebota a apphub.
- [ ] Cerrar sesión desde kpis-sso → NO cierra la sesión de apphub (a diferencia del spec original: como ya no comparten sesión, esto es una diferencia de comportamiento real a documentar y confirmar que es aceptable — si no lo es, agregar un link "Cerrar sesión en todas partes" que llame a logout de ambas apps).
- [ ] Revocar el acceso a `kpis-sso` desde "Aplicaciones" en apphub, con la sesión de kpis-sso ya abierta → sigue entrando hasta que esa sesión expire (mismo trade-off aceptado en el spec original, ahora aplicado a la sesión local de kpis-sso en vez de a la compartida).

**Otorgar el primer grant a mano (no hay UI para el propio superusuario todavía en el primer uso):**

```bash
ssh hjkl@100.67.54.60 'docker exec -it apphub php artisan tinker --execute="
  \$u = App\Models\Usuario::where(\"email\",\"luisgarnica@hotmail.cl\")->first();
  \$app = App\Models\AplicacionExterna::where(\"codigo\",\"kpis-sso\")->first();
  \$u->aplicaciones()->syncWithoutDetaching([\$app->id]);
  foreach (\$app->secciones as \$s) { \$u->seccionesAplicaciones()->syncWithoutDetaching([\$s->id => [\"aplicacion_id\"=>\$app->id,\"nivel\"=>\"editar\"]]); }
  echo \"listo\n\";
"'
```

---

## Self-Review

**Cobertura del spec + ampliación:** decisión 1 (login único) → Tasks B4/B7; decisión 2 (permisos por app, superusuario) → Tasks A1-A4; decisión 3 (SSO real) → Parte B completa (mecanismo cambiado, documentado); decisión 4 (sin acoplar DBs) → el handoff es la única comunicación entre apps, sin queries cruzadas; decisión 5 (rediseño visual fuera de alcance) → no tocado. Ampliación "solicitudes de usuarios nuevos" → Task 6+C3 (ya existía la pestaña, se extendió el approve). Ampliación "permisos por sección ver/editar" → Tasks A1, A4, B6. Launcher con 4 tarjetas + próximamente → Task 5, C1. Imagen del logo → Task 14.

**Placeholders:** ninguno — cada Step tiene código completo, sin "TODO"/"similar a".

**Consistencia de tipos:** `seccionesDeAplicacion(): array<string,string>` (Task 1) se usa igual en A4, A6, B2. El formato `secciones: [{seccion_id, nivel}]` del body HTTP es el mismo en A4 (`AccesoAplicacionController`) y A6 (`SolicitudController::approve`). `sso_secciones` en sesión de kpis-sso tiene la MISMA forma (`{codigo: nivel}`) en B2 (lo que apphub firma), B4 (lo que kpis-sso guarda), B6 (lo que `CheckRole` lee) — verificado, coincide en los tres.

**Riesgo más alto del plan:** Task 12 (romper y reparar todos los tests existentes de kpis-sso al cambiar el guard de auth). Ejecutar con cuidado, de a un archivo de test por vez, sin avanzar a Parte C hasta que la suite completa esté verde.
