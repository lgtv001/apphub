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

    public function test_entrar_devuelve_url_con_handoff_si_tiene_grant(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);
        $usuario->seccionesAplicaciones()->attach($seccion->id, ['aplicacion_id' => $app->id, 'nivel' => 'ver']);

        $antes = now()->timestamp;
        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(200);

        $url = $resp->json('url');
        $this->assertStringStartsWith('https://kpis-sso.test/sso/entrar?handoff=', $url);

        parse_str(parse_url($url, PHP_URL_QUERY), $query);
        [$codificado, $firma] = explode('.', $query['handoff'], 2);
        $this->assertSame(hash_hmac('sha256', $codificado, 'secreto-de-test'), $firma, 'la firma debe corresponder al secreto configurado');

        $payload = json_decode(base64_decode($codificado), true);
        $this->assertSame($usuario->email, $payload['sub']);
        $this->assertSame($usuario->nombre, $payload['nombre']);
        $this->assertSame('kpis-sso', $payload['app']);
        $this->assertSame(['metricas' => 'ver'], $payload['secciones']);
        $this->assertNotEmpty($payload['nonce']);
        $this->assertGreaterThanOrEqual($antes + 55, $payload['exp']);
        $this->assertLessThanOrEqual($antes + 65, $payload['exp']);
    }

    public function test_entrar_incluye_el_tema_en_el_payload_si_se_manda_uno_valido(): void
    {
        // Pedido 2026-08-14: el tema elegido en apphub (localStorage, el backend no lo ve
        // solo) se manda en el body de este POST y viaja adentro del handoff firmado, para
        // que kpis-sso lo aplique la primera vez que el usuario entra desde acá.
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar', ['tema' => 'light'])
            ->assertStatus(200);

        parse_str(parse_url($resp->json('url'), PHP_URL_QUERY), $query);
        [$codificado] = explode('.', $query['handoff'], 2);
        $payload = json_decode(base64_decode($codificado), true);

        $this->assertSame('light', $payload['tema']);
    }

    public function test_entrar_convierte_a_auto_un_valor_de_tema_que_no_sea_light_o_dark(): void
    {
        // Corregido 2026-08-14 (mismo día, reporte del usuario): la primera versión mandaba
        // null cuando el tema era inválido o cuando apphub estaba en "automático" -- y null
        // significaba "no digas nada", así que kpis-sso se quedaba con lo que tuviera guardado
        // de ANTES (ej. una prueba vieja en "claro") en vez de también volver a automático. Acá
        // "auto" es un valor explícito y real, nunca se omite -- así el estado de apphub
        // (incluido "sigo al sistema") siempre gana al entrar, sin dejar nada pegado.
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar', ['tema' => 'psicodelico'])
            ->assertStatus(200);

        parse_str(parse_url($resp->json('url'), PHP_URL_QUERY), $query);
        [$codificado] = explode('.', $query['handoff'], 2);
        $payload = json_decode(base64_decode($codificado), true);

        $this->assertSame('auto', $payload['tema']);
    }

    public function test_entrar_usa_auto_si_no_se_manda_el_campo_tema(): void
    {
        // Mismo motivo que el test de arriba -- omitir el campo entero (cliente viejo, o
        // apphub mandando `null` real desde JS cuando no hay override) tiene que caer en
        // "auto", no en silencio.
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();
        $usuario->aplicaciones()->attach($app->id);

        $resp = $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar', [])
            ->assertStatus(200);

        parse_str(parse_url($resp->json('url'), PHP_URL_QUERY), $query);
        [$codificado] = explode('.', $query['handoff'], 2);
        $payload = json_decode(base64_decode($codificado), true);

        $this->assertSame('auto', $payload['tema']);
    }

    public function test_entrar_da_403_sin_grant(): void
    {
        AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://kpis-sso.test', 'activo' => true]);
        $usuario = Usuario::factory()->create();

        $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(403);
    }

    public function test_entrar_da_404_si_la_app_no_existe_o_esta_inactiva(): void
    {
        AplicacionExterna::create(['codigo' => 'vcc', 'nombre' => 'VCC', 'url_base' => '', 'activo' => false]);
        $usuario = Usuario::factory()->create();

        $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/vcc/entrar')
            ->assertStatus(404);
    }

    public function test_entrar_da_403_si_el_usuario_esta_inactivo_aunque_tenga_grant(): void
    {
        config(['services.sso_handoff.secret' => 'secreto-de-test']);
        $app = AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $usuario = Usuario::factory()->inactivo()->create();
        $usuario->aplicaciones()->attach($app->id);

        $this->withToken($usuario->createToken('t')->plainTextToken)
            ->postJson('/api/launcher/aplicaciones/kpis-sso/entrar')
            ->assertStatus(403);
    }
}
