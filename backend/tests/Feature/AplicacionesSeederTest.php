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
