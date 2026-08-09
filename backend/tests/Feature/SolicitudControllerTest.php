<?php
// apphub/backend/tests/Feature/SolicitudControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\SolicitudAcceso;
use App\Models\Usuario;
use App\Models\UsuarioAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SolicitudControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        return Usuario::factory()->state(['rol_global' => 'superuser'])->create()->createToken('t')->plainTextToken;
    }

    public function test_aprobar_solicitud_sin_aplicaciones_sigue_funcionando_como_antes(): void
    {
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'provider' => 'github', 'provider_id' => '123',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_aprobar_solicitud_con_aplicaciones_otorga_el_acceso_en_el_mismo_paso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'provider' => 'github', 'provider_id' => '456',
        ]);

        $this->withToken($this->superuserToken())->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo2@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'aplicaciones' => [
                ['aplicacion_id' => $app->id, 'secciones' => [['seccion_id' => $seccion->id, 'nivel' => 'ver']]],
            ],
        ])->assertStatus(201);

        $usuario = Usuario::where('email', 'nuevo2@test.com')->firstOrFail();
        $this->assertSame(['metricas' => 'ver'], $usuario->seccionesDeAplicacion('kpis-sso'));
    }

    public function test_aprobar_solicitud_con_aplicaciones_registra_en_log_con_el_id_del_grant(): void
    {
        // Usuario "de relleno" (superuser aprobador) ya ocupa el id 1 en `usuarios` antes de
        // que se cree el usuario nuevo vía approve(), y no tiene ningun grant en
        // `usuarios_aplicaciones`. Esto garantiza que el id del usuario nuevo (>= 2) y el id
        // del grant (primera fila de usuarios_aplicaciones, == 1) queden desalineados por
        // construcción, así el assert de más abajo prueba algo real y no coincide por azar.
        $token = $this->superuserToken();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $solicitud = SolicitudAcceso::create([
            'nombre' => 'Nuevo', 'email' => 'nuevo3@test.com', 'provider' => 'github', 'provider_id' => '789',
        ]);

        $this->withToken($token)->postJson("/api/admin/solicitudes/{$solicitud->id}/aprobar", [
            'nombre' => 'Nuevo', 'email' => 'nuevo3@test.com', 'password' => 'secret1234', 'rol_global' => 'usuario',
            'aplicaciones' => [
                ['aplicacion_id' => $app->id, 'secciones' => [['seccion_id' => $seccion->id, 'nivel' => 'ver']]],
            ],
        ])->assertStatus(201);

        $usuarioNuevo = Usuario::where('email', 'nuevo3@test.com')->firstOrFail();
        $grant = UsuarioAplicacion::where('usuario_id', $usuarioNuevo->id)->where('aplicacion_id', $app->id)->firstOrFail();

        $this->assertNotEquals($usuarioNuevo->id, $grant->id, 'el id del grant y el id del usuario nuevo deben poder distinguirse en la prueba');
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $grant->id, 'accion' => 'CREATE']);
        $this->assertDatabaseMissing('usuarios_aplicaciones_log', ['entidad_id' => $usuarioNuevo->id, 'accion' => 'CREATE']);
    }
}
