<?php
// backend/tests/Feature/AccesoLegacyMigratorTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\Usuario;
use App\Support\AccesoLegacyMigrator;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use RuntimeException;
use Tests\TestCase;

class AccesoLegacyMigratorTest extends TestCase
{
    use RefreshDatabase;

    private function crearTablasLegacy(): void
    {
        // Drop if they exist from migrations
        Schema::dropIfExists('usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_aplicaciones');

        Schema::create('usuarios_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('aplicacion_id');
            $table->timestamps();
        });
        Schema::create('usuario_aplicacion_secciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('usuario_id');
            $table->unsignedBigInteger('aplicacion_id');
            $table->unsignedBigInteger('seccion_id');
            $table->string('nivel', 20);
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        Schema::dropIfExists('usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_aplicaciones');
        parent::tearDown();
    }

    public function test_migra_un_grant_directo_a_un_tipo_propio_del_usuario(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create(['email' => 'migrado@test.com']);

        DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('usuario_aplicacion_secciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'seccion_id' => $seccion->id, 'nivel' => 'editar', 'created_at' => now(), 'updated_at' => now()]);

        AccesoLegacyMigrator::migrar();

        $tipoId = DB::table('tipos_usuario')->where('nombre', 'Acceso SSO (migrado) — migrado@test.com')->value('id');
        $this->assertNotNull($tipoId);
        $this->assertDatabaseHas('usuarios_tipos_usuario', ['usuario_id' => $usuario->id, 'tipo_usuario_id' => $tipoId]);
        $this->assertDatabaseHas('tipo_usuario_aplicacion_secciones', ['tipo_usuario_id' => $tipoId, 'seccion_id' => $seccion->id, 'nivel' => 'editar']);
    }

    public function test_dos_usuarios_con_grants_distintos_reciben_tipos_separados(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $u1 = Usuario::factory()->create(['email' => 'uno@test.com']);
        $u2 = Usuario::factory()->create(['email' => 'dos@test.com']);

        foreach ([$u1, $u2] as $u) {
            DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $u->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
            DB::table('usuario_aplicacion_secciones')->insert(['usuario_id' => $u->id, 'aplicacion_id' => $app->id, 'seccion_id' => $seccion->id, 'nivel' => 'ver', 'created_at' => now(), 'updated_at' => now()]);
        }

        AccesoLegacyMigrator::migrar();

        $this->assertSame(2, DB::table('tipos_usuario')->where('nombre', 'like', 'Acceso SSO (migrado)%')->count());
    }

    public function test_aborta_si_un_grant_no_tiene_ninguna_seccion(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $usuario = Usuario::factory()->create();

        DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
        // sin filas en usuario_aplicacion_secciones -- caso que el modelo nuevo no representa

        $this->expectException(RuntimeException::class);

        AccesoLegacyMigrator::migrar();
    }

    public function test_es_idempotente_si_se_corre_dos_veces(): void
    {
        $this->crearTablasLegacy();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create(['email' => 'idem@test.com']);

        DB::table('usuarios_aplicaciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('usuario_aplicacion_secciones')->insert(['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id, 'seccion_id' => $seccion->id, 'nivel' => 'ver', 'created_at' => now(), 'updated_at' => now()]);

        AccesoLegacyMigrator::migrar();
        AccesoLegacyMigrator::migrar();

        $this->assertSame(1, DB::table('tipos_usuario')->where('nombre', 'Acceso SSO (migrado) — idem@test.com')->count());
        $this->assertSame(1, DB::table('usuarios_tipos_usuario')->where('usuario_id', $usuario->id)->count());
    }
}
