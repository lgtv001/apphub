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
        $this->assertDatabaseHas('aplicaciones_externas_log', ['accion' => 'CREATE', 'entidad_id' => $resp->json('id')]);
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

        $this->assertDatabaseHas('aplicaciones_externas_log', ['accion' => 'UPDATE', 'entidad_id' => $app->id]);
    }
}
