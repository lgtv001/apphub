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
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['accion' => 'CREATE', 'entidad_id' => $usuario->id]);
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
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['accion' => 'DELETE', 'entidad_id' => $usuario->id]);
    }

    public function test_usuario_normal_no_puede_otorgar_acceso(): void
    {
        $token = Usuario::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/accesos-aplicacion')->assertStatus(403);
    }
}
