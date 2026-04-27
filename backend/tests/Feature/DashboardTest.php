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

    public function test_dashboard_requiere_autenticacion(): void
    {
        $proyecto = Proyecto::factory()->create();
        $this->getJson("/api/proyectos/{$proyecto->id}/dashboard")
             ->assertStatus(401);
    }
}
