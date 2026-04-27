<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use App\Services\LogService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LogTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        $su = Usuario::factory()->superuser()->create();
        return $su->createToken('test')->plainTextToken;
    }

    public function test_superuser_puede_ver_log_global(): void
    {
        $token    = $this->superuserToken();
        $proyecto = Proyecto::factory()->create();
        $su       = Usuario::where('rol_global', 'superuser')->first();

        LogService::log('areas',     $proyecto->id, $su->id, 'CREATE', 1);
        LogService::log('subareas',  $proyecto->id, $su->id, 'CREATE', 2);
        LogService::log('proyectos', $proyecto->id, $su->id, 'UPDATE', $proyecto->id);

        $response = $this->withToken($token)->getJson('/api/admin/logs');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [['origen', 'id', 'proyecto_id', 'usuario_id', 'accion', 'created_at']],
                'total',
            ]);

        $this->assertGreaterThanOrEqual(3, count($response->json('data')));
    }

    public function test_log_se_puede_filtrar_por_proyecto(): void
    {
        $token     = $this->superuserToken();
        $su        = Usuario::where('rol_global', 'superuser')->first();
        $proyecto1 = Proyecto::factory()->create();
        $proyecto2 = Proyecto::factory()->create();

        LogService::log('areas', $proyecto1->id, $su->id, 'CREATE', 1);
        LogService::log('areas', $proyecto1->id, $su->id, 'CREATE', 2);
        LogService::log('areas', $proyecto2->id, $su->id, 'CREATE', 3);

        $response = $this->withToken($token)
            ->getJson("/api/admin/logs?proyecto_id={$proyecto1->id}");

        $response->assertStatus(200);
        $data = $response->json('data');

        foreach ($data as $row) {
            $this->assertEquals($proyecto1->id, $row['proyecto_id']);
        }
    }

    public function test_log_ordena_por_fecha_descendente(): void
    {
        $token    = $this->superuserToken();
        $su       = Usuario::where('rol_global', 'superuser')->first();
        $proyecto = Proyecto::factory()->create();

        LogService::log('proyectos', $proyecto->id, $su->id, 'CREATE', $proyecto->id);
        LogService::log('areas',     $proyecto->id, $su->id, 'CREATE', 1);

        $response = $this->withToken($token)->getJson('/api/admin/logs');
        $data     = $response->json('data');

        $this->assertGreaterThanOrEqual(
            $data[1]['created_at'],
            $data[0]['created_at']
        );
    }

    public function test_usuario_no_puede_ver_log_global(): void
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/logs')->assertStatus(403);
    }

    public function test_log_respeta_limite_de_200_entradas(): void
    {
        $token    = $this->superuserToken();
        $su       = Usuario::where('rol_global', 'superuser')->first();
        $proyecto = Proyecto::factory()->create();

        for ($i = 1; $i <= 250; $i++) {
            LogService::log('areas', $proyecto->id, $su->id, 'CREATE', $i);
        }

        $response = $this->withToken($token)->getJson('/api/admin/logs');

        $this->assertLessThanOrEqual(200, count($response->json('data')));
    }
}
