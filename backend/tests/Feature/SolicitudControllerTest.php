<?php
// apphub/backend/tests/Feature/SolicitudControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\SolicitudAcceso;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        return Usuario::factory()->state(['rol_global' => 'superuser'])->create()->createToken('t')->plainTextToken;
    }

    public function test_aprobar_solicitud_sin_tipos_sigue_funcionando_como_antes(): void
    {
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'provider' => 'github', 'provider_id' => '123',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_aprobar_solicitud_con_tipos_otorga_el_acceso_en_el_mismo_paso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create();
        $tipo->secciones()->attach($seccion->id, ['nivel' => 'ver']);

        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'provider' => 'github', 'provider_id' => '456',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'tipos' => [$tipo->id],
        ])->assertStatus(201);

        $usuario = Usuario::where('email', 'nuevo2@test.com')->firstOrFail();
        $this->assertSame(['metricas' => 'ver'], $usuario->seccionesDeAplicacion('kpis-sso'));
    }

    public function test_aprobar_solicitud_con_tipos_registra_en_log(): void
    {
        $tipo = TipoUsuario::factory()->create();
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo3@test.com', 'provider' => 'github', 'provider_id' => '789',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo3@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'tipos' => [$tipo->id],
        ])->assertStatus(201);

        $usuarioNuevo = Usuario::where('email', 'nuevo3@test.com')->firstOrFail();
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $usuarioNuevo->id, 'accion' => 'CREATE']);
    }

    public function test_tipo_inexistente_es_rechazado_al_aprobar(): void
    {
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo4@test.com', 'provider' => 'github', 'provider_id' => '999',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo4@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'tipos' => [9999],
        ])->assertStatus(422);

        $this->assertDatabaseMissing('usuarios', ['email' => 'nuevo4@test.com']);
    }
}
