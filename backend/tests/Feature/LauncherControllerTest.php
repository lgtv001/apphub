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
