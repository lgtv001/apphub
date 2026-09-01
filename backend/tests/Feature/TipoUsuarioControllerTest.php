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
