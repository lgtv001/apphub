# Subsistemas: Avance Constructivo + Dashboard Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add constructive progress tracking (planned/real dates + completion percentage) to subsistemas, then build a dashboard page with Gantt chart and progress bars.

**Architecture:** Six-task sequence: DB migration → model/controller update → new dashboard API endpoint → quiebre.html UI changes → new dashboard.html page → final integration test. All backend lives in `.worktrees/quiebre-contrato/backend/`. Frontend is vanilla JS ES modules in `public/app/`.

**Tech Stack:** Laravel 11, PHP 8.2, MySQL, PHPUnit feature tests, vanilla JS (ES modules), no external JS libraries.

---

## File Map

| File | Action | Responsibility |
|---|---|---|
| `database/migrations/2026_04_27_000001_add_avance_fields_to_subsistemas.php` | Create | Adds 5 nullable columns to `subsistemas` |
| `app/Models/Subsistema.php` | Modify | Add fields to `$fillable` + date `$casts` |
| `app/Http/Controllers/SubsistemaController.php` | Modify | Validate new fields in `store()` and `update()` |
| `app/Http/Controllers/DashboardController.php` | Create | `GET /proyectos/{id}/dashboard` endpoint |
| `routes/api.php` | Modify | Register dashboard route inside `check.project` group |
| `tests/Feature/SubsistemaTest.php` | Modify | Add tests for new fields |
| `tests/Feature/DashboardTest.php` | Create | Tests for dashboard endpoint |
| `public/app/quiebre.html` | Modify | Avance column in tree, date+avance modal fields, Dashboard nav link |
| `public/app/dashboard.html` | Create | Resumen cards, Gantt table, progress bar list |

---

## Task 1: Migration — add 5 columns to subsistemas

**Files:**
- Create: `database/migrations/2026_04_27_000001_add_avance_fields_to_subsistemas.php`

- [ ] **Step 1: Create the migration file**

Create `database/migrations/2026_04_27_000001_add_avance_fields_to_subsistemas.php`:

```php
<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subsistemas', function (Blueprint $table) {
            $table->date('fecha_inicio_plan')->nullable()->after('orden');
            $table->date('fecha_termino_plan')->nullable()->after('fecha_inicio_plan');
            $table->date('fecha_inicio_real')->nullable()->after('fecha_termino_plan');
            $table->date('fecha_termino_real')->nullable()->after('fecha_inicio_real');
            $table->tinyInteger('avance_constructivo')->unsigned()->nullable()->after('fecha_termino_real');
        });
    }

    public function down(): void
    {
        Schema::table('subsistemas', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_inicio_plan',
                'fecha_termino_plan',
                'fecha_inicio_real',
                'fecha_termino_real',
                'avance_constructivo',
            ]);
        });
    }
};
```

- [ ] **Step 2: Run the migration**

```bash
cd .worktrees/quiebre-contrato/backend
php artisan migrate
```

Expected output contains: `Migrating: 2026_04_27_000001_add_avance_fields_to_subsistemas` → `Migrated`

- [ ] **Step 3: Commit**

```bash
git add database/migrations/2026_04_27_000001_add_avance_fields_to_subsistemas.php
git commit -m "feat: migration add avance fields to subsistemas"
```

---

## Task 2: Model + Controller — accept and validate new fields

**Files:**
- Modify: `app/Models/Subsistema.php`
- Modify: `app/Http/Controllers/SubsistemaController.php`
- Modify: `tests/Feature/SubsistemaTest.php`

- [ ] **Step 1: Write failing tests**

Add these three tests to `tests/Feature/SubsistemaTest.php` before the final `}`:

```php
    public function test_admin_puede_crear_subsistema_con_avance(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id'          => $sistema->id,
                'codigo'              => 'AV-001',
                'nombre'              => 'Con avance',
                'fecha_inicio_plan'   => '2026-05-01',
                'fecha_termino_plan'  => '2026-06-30',
                'fecha_inicio_real'   => '2026-05-03',
                'avance_constructivo' => 40,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('avance_constructivo', 40)
            ->assertJsonPath('fecha_inicio_plan', '2026-05-01');

        $this->assertDatabaseHas('subsistemas', [
            'proyecto_id'         => $proyecto->id,
            'codigo'              => 'AV-001',
            'avance_constructivo' => 40,
        ]);
    }

    public function test_avance_constructivo_debe_estar_entre_0_y_100(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id'          => $sistema->id,
                'codigo'              => 'AV-001',
                'nombre'              => 'Con avance',
                'avance_constructivo' => 101,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avance_constructivo']);
    }

    public function test_admin_puede_actualizar_avance_constructivo(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id'         => $proyecto->id,
            'sistema_id'          => $sistema->id,
            'avance_constructivo' => null,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}", [
                'avance_constructivo' => 75,
                'fecha_inicio_real'   => '2026-05-10',
            ])
            ->assertStatus(200)
            ->assertJsonPath('avance_constructivo', 75)
            ->assertJsonPath('fecha_inicio_real', '2026-05-10');
    }
```

- [ ] **Step 2: Run tests to confirm they fail**

```bash
php artisan test tests/Feature/SubsistemaTest.php --filter="test_admin_puede_crear_subsistema_con_avance|test_avance_constructivo_debe_estar_entre_0_y_100|test_admin_puede_actualizar_avance_constructivo"
```

Expected: FAIL — new fields are not in `$fillable` yet.

- [ ] **Step 3: Update `app/Models/Subsistema.php`**

Replace entire file contents:

```php
<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subsistema extends Model
{
    use HasFactory;

    protected $table = 'subsistemas';

    protected $fillable = [
        'proyecto_id', 'sistema_id', 'codigo', 'nombre', 'orden',
        'fecha_inicio_plan', 'fecha_termino_plan',
        'fecha_inicio_real', 'fecha_termino_real',
        'avance_constructivo',
    ];

    protected $casts = [
        'fecha_inicio_plan'  => 'date:Y-m-d',
        'fecha_termino_plan' => 'date:Y-m-d',
        'fecha_inicio_real'  => 'date:Y-m-d',
        'fecha_termino_real' => 'date:Y-m-d',
    ];

    public function sistema()
    {
        return $this->belongsTo(Sistema::class, 'sistema_id');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }
}
```

- [ ] **Step 4: Update `store()` in `SubsistemaController`**

Replace the `$request->validate([...])` call inside `store()` with:

```php
        $data = $request->validate([
            'sistema_id'          => ['required', 'integer', Rule::exists('sistemas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'              => ['required', 'string', 'max:50', Rule::unique('subsistemas')->where('proyecto_id', $proyecto_id)],
            'nombre'              => 'required|string|max:255',
            'orden'               => 'integer|min:0',
            'fecha_inicio_plan'   => 'nullable|date',
            'fecha_termino_plan'  => 'nullable|date',
            'fecha_inicio_real'   => 'nullable|date',
            'fecha_termino_real'  => 'nullable|date',
            'avance_constructivo' => 'nullable|integer|min:0|max:100',
        ]);
```

- [ ] **Step 5: Update `update()` in `SubsistemaController`**

Replace the `$request->validate([...])` call inside `update()` with:

```php
        $data = $request->validate([
            'sistema_id'          => ['integer', Rule::exists('sistemas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'              => ['string', 'max:50', Rule::unique('subsistemas')->where('proyecto_id', $proyecto_id)->ignore($id)],
            'nombre'              => 'string|max:255',
            'orden'               => 'integer|min:0',
            'fecha_inicio_plan'   => 'nullable|date',
            'fecha_termino_plan'  => 'nullable|date',
            'fecha_inicio_real'   => 'nullable|date',
            'fecha_termino_real'  => 'nullable|date',
            'avance_constructivo' => 'nullable|integer|min:0|max:100',
        ]);
```

- [ ] **Step 6: Run tests to confirm they pass**

```bash
php artisan test tests/Feature/SubsistemaTest.php
```

Expected: All tests PASS (including pre-existing ones).

- [ ] **Step 7: Commit**

```bash
git add app/Models/Subsistema.php app/Http/Controllers/SubsistemaController.php tests/Feature/SubsistemaTest.php
git commit -m "feat: accept avance constructivo and dates in subsistemas"
```

---

## Task 3: DashboardController + route

**Files:**
- Create: `app/Http/Controllers/DashboardController.php`
- Modify: `routes/api.php`
- Create: `tests/Feature/DashboardTest.php`

- [ ] **Step 1: Write the failing test**

Create `tests/Feature/DashboardTest.php`:

```php
<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Sistema;
use App\Models\Subarea;
use App\Models\Subsistema;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $proyecto = Proyecto::factory()->create();
        $area     = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea  = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);
        $sistema  = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);
        $admin = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $sistema, $token];
    }

    public function test_dashboard_retorna_estructura_correcta(): void
    {
        [$proyecto, $sistema, $token] = $this->makeFixture();
        Subsistema::factory()->create([
            'proyecto_id'         => $proyecto->id,
            'sistema_id'          => $sistema->id,
            'avance_constructivo' => 60,
        ]);
        Subsistema::factory()->create([
            'proyecto_id'         => $proyecto->id,
            'sistema_id'          => $sistema->id,
            'avance_constructivo' => null,
        ]);

        $response = $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/dashboard")
            ->assertStatus(200);

        $response->assertJsonStructure([
            'proyecto'    => ['id', 'codigo', 'nombre'],
            'resumen'     => ['total_subsistemas', 'con_avance', 'avance_promedio', 'con_retraso'],
            'subsistemas' => [
                '*' => [
                    'id', 'codigo', 'nombre', 'sistema_nombre',
                    'fecha_inicio_plan', 'fecha_termino_plan',
                    'fecha_inicio_real', 'fecha_termino_real',
                    'avance_constructivo',
                ],
            ],
        ]);

        $response->assertJsonPath('resumen.total_subsistemas', 2)
                 ->assertJsonPath('resumen.con_avance', 1)
                 ->assertJsonPath('resumen.avance_promedio', 30); // avg(60, 0) = 30
    }

    public function test_dashboard_detecta_subsistemas_con_retraso(): void
    {
        [$proyecto, $sistema, $token] = $this->makeFixture();

        Subsistema::factory()->create([
            'proyecto_id'        => $proyecto->id,
            'sistema_id'         => $sistema->id,
            'fecha_termino_plan' => '2026-05-01',
            'fecha_termino_real' => '2026-05-10', // real > plan → retraso
        ]);
        Subsistema::factory()->create([
            'proyecto_id'        => $proyecto->id,
            'sistema_id'         => $sistema->id,
            'fecha_termino_plan' => '2026-06-01',
            'fecha_termino_real' => '2026-05-25', // real <= plan → ok
        ]);
        Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
            // sin fechas → no cuenta
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/dashboard")
            ->assertStatus(200)
            ->assertJsonPath('resumen.con_retraso', 1);
    }

    public function test_dashboard_solo_muestra_subsistemas_del_proyecto(): void
    {
        [$proyecto, $sistema, $token] = $this->makeFixture();
        Subsistema::factory()->count(2)->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);
        Subsistema::factory()->count(5)->create(); // otro proyecto

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/dashboard")
            ->assertStatus(200)
            ->assertJsonCount(2, 'subsistemas');
    }
}
```

- [ ] **Step 2: Run test to confirm it fails**

```bash
php artisan test tests/Feature/DashboardTest.php
```

Expected: FAIL with `Expected status code 200 but received 404` (route not registered yet).

- [ ] **Step 3: Create `app/Http/Controllers/DashboardController.php`**

```php
<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Models\Subsistema;

class DashboardController extends Controller
{
    public function show(int $proyecto_id)
    {
        $proyecto = Proyecto::findOrFail($proyecto_id);

        $subsistemas = Subsistema::where('proyecto_id', $proyecto_id)
            ->with('sistema:id,nombre')
            ->orderBy('codigo')
            ->get();

        $total      = $subsistemas->count();
        $conAvance  = $subsistemas->filter(fn($s) => $s->avance_constructivo !== null)->count();
        $promedio   = $total > 0
            ? (int) round($subsistemas->avg(fn($s) => $s->avance_constructivo ?? 0))
            : 0;
        $conRetraso = $subsistemas->filter(
            fn($s) => $s->fecha_termino_plan !== null
                   && $s->fecha_termino_real !== null
                   && $s->fecha_termino_real->gt($s->fecha_termino_plan)
        )->count();

        $rows = $subsistemas->map(fn($s) => [
            'id'                  => $s->id,
            'codigo'              => $s->codigo,
            'nombre'              => $s->nombre,
            'sistema_nombre'      => $s->sistema?->nombre ?? '',
            'fecha_inicio_plan'   => $s->fecha_inicio_plan?->format('Y-m-d'),
            'fecha_termino_plan'  => $s->fecha_termino_plan?->format('Y-m-d'),
            'fecha_inicio_real'   => $s->fecha_inicio_real?->format('Y-m-d'),
            'fecha_termino_real'  => $s->fecha_termino_real?->format('Y-m-d'),
            'avance_constructivo' => $s->avance_constructivo,
        ]);

        return response()->json([
            'proyecto' => [
                'id'     => $proyecto->id,
                'codigo' => $proyecto->codigo,
                'nombre' => $proyecto->nombre,
            ],
            'resumen' => [
                'total_subsistemas' => $total,
                'con_avance'        => $conAvance,
                'avance_promedio'   => $promedio,
                'con_retraso'       => $conRetraso,
            ],
            'subsistemas' => $rows,
        ]);
    }
}
```

- [ ] **Step 4: Register the route in `routes/api.php`**

Add the import at the top of the file (with the other `use` statements):

```php
use App\Http\Controllers\DashboardController;
```

Inside the `prefix('proyectos/{proyecto_id}')->middleware('check.project')` group, after the import line route, add:

```php
        Route::get('/dashboard', [DashboardController::class, 'show']);
```

- [ ] **Step 5: Run the dashboard tests**

```bash
php artisan test tests/Feature/DashboardTest.php
```

Expected: All 3 tests PASS.

- [ ] **Step 6: Run the full test suite to check for regressions**

```bash
php artisan test
```

Expected: All tests PASS.

- [ ] **Step 7: Commit**

```bash
git add app/Http/Controllers/DashboardController.php routes/api.php tests/Feature/DashboardTest.php
git commit -m "feat: add GET /proyectos/{id}/dashboard endpoint"
```

---

## Task 4: Update `quiebre.html` — Avance column, modal fields, Dashboard link

**Files:**
- Modify: `public/app/quiebre.html`

- [ ] **Step 1: Add CSS for mini bar and date inputs (inside the `<style>` block)**

After `.drop-zone input[type="file"] { display: none; }` and before `</style>`, add:

```css
    .mini-bar-wrap { width: 80px; background: rgba(255,255,255,0.06); border-radius: 3px; height: 6px; display: inline-block; vertical-align: middle; }
    .mini-bar-fill { height: 6px; border-radius: 3px; background: var(--blue); }
    .avance-cell   { white-space: nowrap; font-size: 12px; color: var(--text-muted); }

    .form-group input[type="date"],
    .form-group input[type="number"] {
      width: 100%; background: var(--bg);
      border: 1px solid var(--border); border-radius: 6px;
      color: var(--text); padding: 8px 12px; font-size: 13px;
    }
    .form-group input[type="date"]:focus,
    .form-group input[type="number"]:focus { outline: none; border-color: var(--blue); }
    .date-grid { display: grid; grid-template-columns: 1fr 1fr; gap: 10px; }
    .field-readonly { font-size: 13px; color: var(--text); padding: 8px 0; }
```

- [ ] **Step 2: Add Dashboard button to navbar**

In `<div class="nav-right">`, add as the first element (before the `span#user-name`):

```html
      <a id="btn-dashboard" href="#" class="btn btn-secondary btn-sm">Dashboard</a>
```

- [ ] **Step 3: Add Avance column to `<thead>`**

Change:

```html
          <tr>
            <th>Código</th>
            <th>Nombre</th>
            <th id="th-acciones" style="display:none;width:100px">Acciones</th>
          </tr>
```

To:

```html
          <tr>
            <th>Código</th>
            <th>Nombre</th>
            <th style="width:120px">Avance</th>
            <th id="th-acciones" style="display:none;width:100px">Acciones</th>
          </tr>
```

- [ ] **Step 4: Fix `colspan` in loading and empty-state rows**

Change in `<tbody id="tree-body">`:

```html
          <tr><td colspan="3" style="padding:24px;text-align:center;color:var(--text-muted)">Cargando…</td></tr>
```

To:

```html
          <tr><td colspan="4" style="padding:24px;text-align:center;color:var(--text-muted)">Cargando…</td></tr>
```

Change in `renderTree()` (the "Sin resultados" line in JS):

```js
        tbody.innerHTML = `<tr><td colspan="3" style="padding:24px;text-align:center;color:var(--text-muted)">Sin resultados.</td></tr>`;
```

To:

```js
        tbody.innerHTML = `<tr><td colspan="4" style="padding:24px;text-align:center;color:var(--text-muted)">Sin resultados.</td></tr>`;
```

- [ ] **Step 5: Add avance `td` in `makeRow()`**

After `tdNombre.appendChild(nameSpan);` and before `tr.appendChild(tdCodigo);`, insert:

```js
      const tdAvance = document.createElement('td');
      tdAvance.className = 'avance-cell';
      if (tipo === 'subsistema') {
        const pct = item.avance_constructivo ?? null;
        if (pct !== null) {
          tdAvance.innerHTML = `<span class="mini-bar-wrap"><span class="mini-bar-fill" style="width:${pct}%"></span></span> <span>${pct}%</span>`;
        } else {
          tdAvance.textContent = '—';
        }
      }
```

Change the three `tr.appendChild` lines that follow to:

```js
      tr.appendChild(tdCodigo);
      tr.appendChild(tdNombre);
      tr.appendChild(tdAvance);
```

(The `if (canEdit)` block for `tdActions` appends after these, so no change needed there.)

- [ ] **Step 6: Add avance/date block to the form modal**

Inside `<form id="item-form" novalidate>`, after the Nombre `form-group` div and before the submit buttons `div`, add:

```html
        <div id="group-avance" style="display:none">
          <div class="form-group">
            <label>Fechas planificadas</label>
            <div class="date-grid">
              <div>
                <label style="font-size:11px;color:var(--text-dim)">Inicio plan</label>
                <input type="date" id="f-inicio-plan"/>
                <div class="field-readonly" id="f-inicio-plan-text" style="display:none"></div>
              </div>
              <div>
                <label style="font-size:11px;color:var(--text-dim)">Término plan</label>
                <input type="date" id="f-termino-plan"/>
                <div class="field-readonly" id="f-termino-plan-text" style="display:none"></div>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Fechas reales</label>
            <div class="date-grid">
              <div>
                <label style="font-size:11px;color:var(--text-dim)">Inicio real</label>
                <input type="date" id="f-inicio-real"/>
              </div>
              <div>
                <label style="font-size:11px;color:var(--text-dim)">Término real</label>
                <input type="date" id="f-termino-real"/>
              </div>
            </div>
          </div>
          <div class="form-group">
            <label>Avance constructivo (%)</label>
            <input type="number" id="f-avance" min="0" max="100" placeholder="0–100"/>
          </div>
        </div>
```

- [ ] **Step 7: Update `handleNivelChange()` to show/hide `group-avance`**

Change:

```js
    function handleNivelChange(nivel) {
      resetCascades();
      document.getElementById('group-area').style.display    = ['subarea','sistema','subsistema'].includes(nivel) ? '' : 'none';
      document.getElementById('group-subarea').style.display = ['sistema','subsistema'].includes(nivel) ? '' : 'none';
      document.getElementById('group-sistema').style.display = nivel === 'subsistema' ? '' : 'none';

      if (['subarea','sistema','subsistema'].includes(nivel)) {
        populateSelect('f-area', areasData, 'id', 'nombre', true);
      }
    }
```

To:

```js
    function handleNivelChange(nivel) {
      resetCascades();
      document.getElementById('group-area').style.display    = ['subarea','sistema','subsistema'].includes(nivel) ? '' : 'none';
      document.getElementById('group-subarea').style.display = ['sistema','subsistema'].includes(nivel) ? '' : 'none';
      document.getElementById('group-sistema').style.display = nivel === 'subsistema' ? '' : 'none';
      document.getElementById('group-avance').style.display  = nivel === 'subsistema' ? '' : 'none';

      if (['subarea','sistema','subsistema'].includes(nivel)) {
        populateSelect('f-area', areasData, 'id', 'nombre', true);
      }
    }
```

- [ ] **Step 8: Update `resetCascades()` to also hide `group-avance`**

Change:

```js
    function resetCascades() {
      ['group-area','group-subarea','group-sistema'].forEach(id => {
```

To:

```js
    function resetCascades() {
      ['group-area','group-subarea','group-sistema','group-avance'].forEach(id => {
```

- [ ] **Step 9: Update `openEditModal()` and add `setDateField()` helper**

Replace the existing `openEditModal` function entirely:

```js
    function openEditModal(tipo, item) {
      editMode = true; editTipo = tipo; editId = item.id;
      document.getElementById('form-title').textContent = 'Editar ítem';
      clearFormErrors();

      document.getElementById('f-nivel').value = tipo;
      handleNivelChange(tipo);

      document.getElementById('f-codigo').value = item.codigo;
      document.getElementById('f-nombre').value = item.nombre;

      if (tipo === 'subarea') {
        document.getElementById('f-area').value = item.area_id;
      } else if (tipo === 'sistema') {
        document.getElementById('f-area').value = item.area_id;
        loadSubareasByArea(item.area_id).then(() => {
          document.getElementById('f-subarea').value = item.subarea_id;
        });
      } else if (tipo === 'subsistema') {
        document.getElementById('f-area').value = item.area_id;
        loadSubareasByArea(item.area_id).then(() => {
          document.getElementById('f-subarea').value = item.subarea_id;
          loadSistemasBySubarea(item.subarea_id).then(() => {
            document.getElementById('f-sistema').value = item.sistema_id;
          });
        });

        setDateField('f-inicio-plan',  'f-inicio-plan-text',  item.fecha_inicio_plan  ?? null);
        setDateField('f-termino-plan', 'f-termino-plan-text', item.fecha_termino_plan ?? null);
        document.getElementById('f-inicio-real').value  = item.fecha_inicio_real  ?? '';
        document.getElementById('f-termino-real').value = item.fecha_termino_real ?? '';
        document.getElementById('f-avance').value       = item.avance_constructivo ?? '';
      }

      modalForm.classList.add('open');
    }

    function setDateField(inputId, textId, value) {
      const input = document.getElementById(inputId);
      const text  = document.getElementById(textId);
      if (value) {
        input.style.display = 'none';
        text.style.display  = '';
        text.textContent    = value;
      } else {
        input.style.display = '';
        text.style.display  = 'none';
        input.value         = '';
      }
    }
```

- [ ] **Step 10: Update `buildBody()` to include avance/date fields for subsistemas**

Replace the existing `buildBody` function:

```js
    function buildBody(nivel, codigo, nombre, areaId, subareaId, sistemaId) {
      const b = { codigo, nombre };
      if (nivel === 'subarea')    { b.area_id = areaId; }
      if (nivel === 'sistema')    { b.area_id = areaId; b.subarea_id = subareaId; }
      if (nivel === 'subsistema') {
        b.area_id    = areaId;
        b.subarea_id = subareaId;
        b.sistema_id = sistemaId;

        const iniPlanEl  = document.getElementById('f-inicio-plan');
        const termPlanEl = document.getElementById('f-termino-plan');

        if (iniPlanEl.style.display !== 'none'  && iniPlanEl.value)  b.fecha_inicio_plan  = iniPlanEl.value;
        if (termPlanEl.style.display !== 'none' && termPlanEl.value) b.fecha_termino_plan = termPlanEl.value;

        const iniReal  = document.getElementById('f-inicio-real').value;
        const termReal = document.getElementById('f-termino-real').value;
        b.fecha_inicio_real  = iniReal  || null;
        b.fecha_termino_real = termReal || null;

        const av = document.getElementById('f-avance').value;
        if (av !== '') b.avance_constructivo = parseInt(av, 10);
      }
      return b;
    }
```

- [ ] **Step 11: Wire Dashboard button in the JS section**

After `document.getElementById('search-input').addEventListener('input', renderTree);`, add:

```js
    document.getElementById('btn-dashboard').addEventListener('click', (e) => {
      e.preventDefault();
      window.location.href = `/app/dashboard.html?proyecto_id=${proyectoId}`;
    });
```

- [ ] **Step 12: Manual test in browser**

```
php artisan serve --port=8000
```

Then open `http://localhost:8000/app/quiebre.html` (log in first, select a project):

1. Verify the Avance column header appears in the tree.
2. Click `+ Agregar` → select Subsistema → confirm date/avance fields appear below the Sistema selector.
3. Save a subsistema with `avance_constructivo = 45` → the tree row shows a mini bar + `45%`.
4. Edit that subsistema → set `fecha_inicio_plan` → save → re-open edit → confirm `fecha_inicio_plan` shows as read-only text.
5. Click the Dashboard button → confirms navigation to `/app/dashboard.html?proyecto_id=X`.

- [ ] **Step 13: Commit**

```bash
git add public/app/quiebre.html
git commit -m "feat: avance column, date/avance modal fields, dashboard link in quiebre.html"
```

---

## Task 5: Create `dashboard.html`

**Files:**
- Create: `public/app/dashboard.html`

- [ ] **Step 1: Create `public/app/dashboard.html`**

```html
<!DOCTYPE html>
<html lang="es">
<head>
  <meta charset="UTF-8"/>
  <meta name="viewport" content="width=device-width, initial-scale=1.0"/>
  <title>Dashboard — AppHub</title>
  <link rel="stylesheet" href="/assets/css/app.css"/>
  <style>
    .stat-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 16px; margin-bottom: 24px; }
    .stat-card { background: var(--bg-card); border: 1px solid var(--border); border-radius: 10px; padding: 20px; text-align: center; }
    .stat-value { font-size: 28px; font-weight: 700; color: var(--text); }
    .stat-label { font-size: 11px; color: var(--text-muted); text-transform: uppercase; letter-spacing: 0.5px; margin-top: 4px; }
    .stat-card.danger .stat-value { color: var(--red); }
    .progress-bar-bg { background: rgba(255,255,255,0.06); border-radius: 6px; height: 10px; margin-top: 12px; }
    .progress-bar-fill { height: 10px; border-radius: 6px; background: var(--blue); transition: width 0.4s; }

    .section-title { font-size: 13px; font-weight: 600; color: var(--text-dim); text-transform: uppercase; letter-spacing: 0.5px; margin: 24px 0 12px; }

    .gantt-table { width: 100%; border-collapse: collapse; }
    .gantt-table th { background: var(--bg-row); color: var(--text-dim); font-size: 11px; font-weight: 600; text-transform: uppercase; letter-spacing: 0.5px; padding: 8px 12px; text-align: left; border-bottom: 1px solid var(--border); }
    .gantt-table td { padding: 8px 12px; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 12px; }
    .gantt-track { position: relative; height: 22px; }
    .gantt-bar { position: absolute; height: 8px; border-radius: 3px; min-width: 3px; }
    .gantt-bar.plan  { background: rgba(255,255,255,0.18); top: 4px; }
    .gantt-bar.real  { background: var(--blue); top: 13px; }
    .gantt-bar.retraso { background: var(--red); }

    .avance-list { list-style: none; padding: 0; }
    .avance-list li { display: flex; align-items: center; gap: 12px; padding: 8px 0; border-bottom: 1px solid rgba(255,255,255,0.04); font-size: 12px; }
    .a-code { font-family: monospace; background: rgba(79,126,255,0.08); color: var(--blue-soft); padding: 2px 7px; border-radius: 4px; font-size: 11px; white-space: nowrap; }
    .a-name { flex: 1; color: var(--text-muted); }
    .a-bar-wrap { width: 120px; background: rgba(255,255,255,0.06); border-radius: 4px; height: 8px; flex-shrink: 0; }
    .a-bar-fill { height: 8px; border-radius: 4px; background: var(--blue); }
    .a-pct { width: 36px; text-align: right; color: var(--text-muted); }
  </style>
</head>
<body>

  <nav class="navbar">
    <div class="nav-left">
      <a class="nav-brand" href="/app/selector-proyecto.html">AppHub</a>
      <span class="nav-sep">/</span>
      <span id="proyecto-codigo" class="nav-project-code"></span>
      <span id="proyecto-nombre" class="nav-project-name"></span>
      <span class="nav-sep">/</span>
      <span class="nav-current">Dashboard</span>
    </div>
    <div class="nav-right">
      <span id="user-name" class="nav-user"></span>
      <a id="btn-quiebre" href="/app/quiebre.html" class="btn btn-ghost btn-sm">← Quiebre del Contrato</a>
      <a href="/app/selector-proyecto.html" class="btn btn-ghost btn-sm">Cambiar proyecto</a>
    </div>
  </nav>

  <div class="container">
    <div class="page-header">
      <div>
        <h1>Dashboard del Proyecto</h1>
        <p id="subtitle" class="subtitle mt-8"></p>
      </div>
    </div>

    <div class="stat-grid">
      <div class="stat-card">
        <div class="stat-value" id="stat-total">—</div>
        <div class="stat-label">Total subsistemas</div>
      </div>
      <div class="stat-card">
        <div class="stat-value" id="stat-avance">—%</div>
        <div class="stat-label">Avance promedio</div>
        <div class="progress-bar-bg">
          <div class="progress-bar-fill" id="bar-avance" style="width:0%"></div>
        </div>
      </div>
      <div class="stat-card" id="card-retraso">
        <div class="stat-value" id="stat-retraso">—</div>
        <div class="stat-label">Con retraso</div>
      </div>
    </div>

    <div class="section-title">Gantt por subsistema</div>
    <div class="card" style="padding:0;overflow:hidden">
      <table class="gantt-table">
        <thead>
          <tr>
            <th style="width:110px">Código</th>
            <th>Nombre</th>
            <th style="width:140px">Sistema</th>
            <th style="width:280px">Cronograma</th>
          </tr>
        </thead>
        <tbody id="gantt-body">
          <tr><td colspan="4" style="padding:24px;text-align:center;color:var(--text-muted)">Cargando…</td></tr>
        </tbody>
      </table>
    </div>

    <div class="section-title">Avance constructivo por subsistema</div>
    <div class="card">
      <ul class="avance-list" id="avance-list">
        <li style="color:var(--text-muted);font-size:13px">Cargando…</li>
      </ul>
    </div>
  </div>

  <script type="module">
    import { requireAuth, getUser, apiGet } from '/assets/js/api.js';

    requireAuth();

    const params     = new URLSearchParams(window.location.search);
    const proyectoId = params.get('proyecto_id') || sessionStorage.getItem('proyecto_id');
    if (!proyectoId) { window.location.href = '/app/selector-proyecto.html'; }

    document.getElementById('user-name').textContent = getUser()?.nombre ?? '';

    async function loadDashboard() {
      const data = await apiGet(`/proyectos/${proyectoId}/dashboard`);

      document.getElementById('proyecto-codigo').textContent = data.proyecto.codigo;
      document.getElementById('proyecto-nombre').textContent = data.proyecto.nombre;
      document.getElementById('subtitle').textContent = `${data.resumen.total_subsistemas} subsistemas`;

      const pct = data.resumen.avance_promedio;
      document.getElementById('stat-total').textContent   = data.resumen.total_subsistemas;
      document.getElementById('stat-avance').textContent  = `${pct}%`;
      document.getElementById('bar-avance').style.width   = `${pct}%`;
      document.getElementById('stat-retraso').textContent = data.resumen.con_retraso;
      if (data.resumen.con_retraso > 0) {
        document.getElementById('card-retraso').classList.add('danger');
      }

      renderGantt(data.subsistemas);
      renderAvance(data.subsistemas);
    }

    function renderGantt(subsistemas) {
      const withDates = subsistemas.filter(s => s.fecha_inicio_plan || s.fecha_inicio_real);
      const tbody = document.getElementById('gantt-body');

      if (withDates.length === 0) {
        tbody.innerHTML = `<tr><td colspan="4" style="padding:24px;text-align:center;color:var(--text-muted)">Sin fechas registradas.</td></tr>`;
        return;
      }

      const allDates = withDates.flatMap(s => [
        s.fecha_inicio_plan, s.fecha_termino_plan,
        s.fecha_inicio_real, s.fecha_termino_real,
      ]).filter(Boolean).map(d => new Date(d));
      const minDate = new Date(Math.min(...allDates));
      const maxDate = new Date(Math.max(...allDates));
      const span    = Math.max(1, maxDate - minDate);

      tbody.innerHTML = '';
      for (const s of withDates) {
        const tr = document.createElement('tr');
        const retraso = s.fecha_termino_real && s.fecha_termino_plan
          && new Date(s.fecha_termino_real) > new Date(s.fecha_termino_plan);

        let barHtml = '<div class="gantt-track">';
        if (s.fecha_inicio_plan && s.fecha_termino_plan) {
          const l = pctOf(new Date(s.fecha_inicio_plan)  - minDate, span);
          const w = pctOf(new Date(s.fecha_termino_plan) - new Date(s.fecha_inicio_plan), span);
          barHtml += `<div class="gantt-bar plan" style="left:${l}%;width:${Math.max(1,w)}%"></div>`;
        }
        if (s.fecha_inicio_real && s.fecha_termino_real) {
          const l = pctOf(new Date(s.fecha_inicio_real)  - minDate, span);
          const w = pctOf(new Date(s.fecha_termino_real) - new Date(s.fecha_inicio_real), span);
          barHtml += `<div class="gantt-bar real ${retraso ? 'retraso' : ''}" style="left:${l}%;width:${Math.max(1,w)}%"></div>`;
        }
        barHtml += '</div>';

        tr.innerHTML = `
          <td><span style="font-family:monospace;font-size:11px;background:rgba(79,126,255,0.08);color:var(--blue-soft);padding:2px 7px;border-radius:4px">${escHtml(s.codigo)}</span></td>
          <td style="color:var(--text-muted)">${escHtml(s.nombre)}</td>
          <td style="font-size:11px;color:var(--text-dim)">${escHtml(s.sistema_nombre)}</td>
          <td>${barHtml}</td>`;
        tbody.appendChild(tr);
      }
    }

    function pctOf(num, denom) {
      return Math.max(0, Math.min(100, (num / denom) * 100));
    }

    function renderAvance(subsistemas) {
      const sorted = [...subsistemas].sort((a, b) => (a.avance_constructivo ?? 0) - (b.avance_constructivo ?? 0));
      const ul = document.getElementById('avance-list');
      ul.innerHTML = '';
      if (sorted.length === 0) {
        ul.innerHTML = `<li style="color:var(--text-muted)">Sin subsistemas.</li>`;
        return;
      }
      for (const s of sorted) {
        const av = s.avance_constructivo ?? 0;
        const li = document.createElement('li');
        li.innerHTML = `
          <span class="a-code">${escHtml(s.codigo)}</span>
          <span class="a-name">${escHtml(s.nombre)}</span>
          <div class="a-bar-wrap"><div class="a-bar-fill" style="width:${av}%"></div></div>
          <span class="a-pct">${av}%</span>`;
        ul.appendChild(li);
      }
    }

    function escHtml(s) {
      return String(s ?? '').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
    }

    loadDashboard();
  </script>
</body>
</html>
```

- [ ] **Step 2: Manual test in browser**

With `php artisan serve --port=8000` running:

1. Open `http://localhost:8000/app/dashboard.html?proyecto_id=1` (adjust ID to an existing project).
2. Confirm the 3 stat cards render (Total, Avance promedio with bar, Con retraso).
3. Add subsistemas with dates/avance via `quiebre.html`, then reload the dashboard.
4. Gantt: rows appear only for subsistemas with at least one date; bars show plan (gray) above real (blue/red).
5. Avance list: sorted ascending; subsistemas without `avance_constructivo` show `0%`.
6. If any subsistema has `fecha_termino_real > fecha_termino_plan`, the "Con retraso" card turns red.

- [ ] **Step 3: Commit**

```bash
git add public/app/dashboard.html
git commit -m "feat: add dashboard.html with Gantt and progress bars"
```

---

## Task 6: Final integration test + deploy

**Files:** none (verification only)

- [ ] **Step 1: Run the full test suite**

```bash
cd .worktrees/quiebre-contrato/backend
php artisan test
```

Expected: All tests PASS with no failures.

- [ ] **Step 2: Push branch to trigger Railway deploy**

```bash
git push origin feature/quiebre-contrato
```

Railway's `start.sh` runs `php artisan migrate --force` on startup, so the new columns are applied automatically in production.

- [ ] **Step 3: Smoke-test on Railway URL**

1. Log in at the Railway URL.
2. Navigate to Quiebre del Contrato → confirm the Avance column is visible.
3. Edit a subsistema, add dates and avance → save → confirm bar appears.
4. Click Dashboard → confirm `dashboard.html` loads with data from the API.
