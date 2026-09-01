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

    public function test_las_tablas_legacy_ya_no_existen_despues_de_migrar(): void
    {
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('usuarios_aplicaciones'));
        $this->assertFalse(\Illuminate\Support\Facades\Schema::hasTable('usuario_aplicacion_secciones'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('usuarios_tipos_usuario'));
        $this->assertTrue(\Illuminate\Support\Facades\Schema::hasTable('tipo_usuario_aplicacion_secciones'));
    }
}
