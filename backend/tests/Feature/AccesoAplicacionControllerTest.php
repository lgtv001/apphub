<?php
// apphub/backend/tests/Feature/AccesoAplicacionControllerTest.php
namespace Tests\Feature;

use App\Models\AplicacionExterna;
use App\Models\Usuario;
use App\Models\UsuarioAplicacion;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AccesoAplicacionControllerTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): string
    {
        return Usuario::factory()->state(['rol_global' => 'superuser'])->create()->createToken('t')->plainTextToken;
    }

    public function test_otorga_acceso_con_secciones_y_niveles(): void
    {
        // Usuario "de relleno" SIN grant antes del que se prueba: desplaza el contador de
        // ids de `usuarios` en +1 sin tocar el de `usuarios_aplicaciones`, así el id del
        // grant real (primera fila de usuarios_aplicaciones) queda garantizado distinto del
        // id del usuario real (segundo usuario creado). Un relleno simétrico (usuario +
        // grant a la vez, como se probó primero) NO sirve: desplaza ambos contadores por
        // igual y siguen coincidiendo -- confirmado empíricamente (2 == 2) en re-review.
        $otroUsuario = Usuario::factory()->create();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $cargar = $app->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $metricas = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();

        $this->withToken($this->superuserToken())->postJson('/api/admin/accesos-aplicacion', [
            'usuario_id' => $usuario->id,
            'aplicacion_id' => $app->id,
            'secciones' => [
                ['seccion_id' => $cargar->id, 'nivel' => 'editar'],
                ['seccion_id' => $metricas->id, 'nivel' => 'ver'],
            ],
        ])->assertStatus(201);

        $grant = UsuarioAplicacion::where('usuario_id', $usuario->id)->where('aplicacion_id', $app->id)->firstOrFail();
        $this->assertNotEquals($usuario->id, $grant->id, 'el id del grant y el id del usuario deben poder distinguirse en la prueba');
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $grant->id, 'accion' => 'CREATE']);
        $this->assertDatabaseMissing('usuarios_aplicaciones_log', ['entidad_id' => $usuario->id, 'accion' => 'CREATE']);

        $this->assertDatabaseHas('usuarios_aplicaciones', ['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id]);
        $this->assertSame(['cargar' => 'editar', 'metricas' => 'ver'], $usuario->fresh()->seccionesDeAplicacion('kpis-sso'));
    }

    public function test_revocar_acceso_borra_grant_y_secciones(): void
    {
        // Usuario "de relleno" SIN grant, mismo motivo que en el test de arriba: garantiza
        // que el id del grant real y el id del usuario real difieran de forma determinista.
        $otroUsuario = Usuario::factory()->create();

        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);
        $usuario->seccionesAplicaciones()->attach($seccion->id, ['aplicacion_id' => $app->id, 'nivel' => 'ver']);

        $grantId = UsuarioAplicacion::where('usuario_id', $usuario->id)->where('aplicacion_id', $app->id)->firstOrFail()->id;
        $this->assertNotEquals($usuario->id, $grantId, 'el id del grant y el id del usuario deben poder distinguirse en la prueba');

        $this->withToken($this->superuserToken())
            ->deleteJson("/api/admin/accesos-aplicacion/{$usuario->id}/{$app->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('usuarios_aplicaciones', ['usuario_id' => $usuario->id, 'aplicacion_id' => $app->id]);
        $this->assertSame([], $usuario->fresh()->seccionesDeAplicacion('kpis-sso'));
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $grantId, 'accion' => 'DELETE']);
        $this->assertDatabaseMissing('usuarios_aplicaciones_log', ['entidad_id' => $usuario->id, 'accion' => 'DELETE']);
    }

    public function test_seccion_de_otra_aplicacion_es_rechazada(): void
    {
        $appA = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $appB = AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => 'https://y', 'activo' => true]);
        $seccionDeB = $appB->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();

        $this->withToken($this->superuserToken())->postJson('/api/admin/accesos-aplicacion', [
            'usuario_id' => $usuario->id,
            'aplicacion_id' => $appA->id,
            'secciones' => [
                ['seccion_id' => $seccionDeB->id, 'nivel' => 'ver'],
            ],
        ])->assertStatus(422);
    }

    public function test_usuario_normal_no_puede_otorgar_acceso(): void
    {
        $token = Usuario::factory()->create()->createToken('t')->plainTextToken;

        $this->withToken($token)->getJson('/api/admin/accesos-aplicacion')->assertStatus(403);
    }

    public function test_index_incluye_las_secciones_de_cada_acceso(): void
    {
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);
        $usuario->seccionesAplicaciones()->attach($seccion->id, ['aplicacion_id' => $app->id, 'nivel' => 'editar']);

        $this->withToken($this->superuserToken())
            ->getJson('/api/admin/accesos-aplicacion')
            ->assertStatus(200)
            ->assertJsonPath('data.0.secciones.0.codigo', 'cargar')
            ->assertJsonPath('data.0.secciones.0.nivel', 'editar');
    }

    public function test_index_no_mezcla_secciones_entre_apps_del_mismo_usuario(): void
    {
        // Regresión dirigida: si el agrupamiento por clave compuesta "usuario_id:aplicacion_id"
        // colapsara accidentalmente a solo usuario_id, este test lo detectaría -- con un único
        // usuario+app (como en el test de arriba) el bug pasaría desapercibido.
        $appA = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://a', 'activo' => true]);
        $seccionA = $appA->secciones()->create(['codigo' => 'cargar', 'nombre' => 'Cargar datos']);
        $appB = AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => 'https://b', 'activo' => true]);
        $seccionB = $appB->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);

        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach([$appA->id, $appB->id]);
        $usuario->seccionesAplicaciones()->attach($seccionA->id, ['aplicacion_id' => $appA->id, 'nivel' => 'editar']);
        $usuario->seccionesAplicaciones()->attach($seccionB->id, ['aplicacion_id' => $appB->id, 'nivel' => 'ver']);

        $resp = $this->withToken($this->superuserToken())
            ->getJson('/api/admin/accesos-aplicacion')
            ->assertStatus(200);

        $porApp = collect($resp->json('data'))->keyBy('aplicacion_id');

        $this->assertSame(['cargar'], array_column($porApp[$appA->id]['secciones'], 'codigo'));
        $this->assertSame('editar', $porApp[$appA->id]['secciones'][0]['nivel']);
        $this->assertSame(['metricas'], array_column($porApp[$appB->id]['secciones'], 'codigo'));
        $this->assertSame('ver', $porApp[$appB->id]['secciones'][0]['nivel']);
    }
}
