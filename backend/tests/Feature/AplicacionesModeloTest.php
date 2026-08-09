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
