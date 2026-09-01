<?php

namespace Tests\Feature;

use App\Models\TipoUsuario;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class AdminTest extends TestCase
{
    use RefreshDatabase;

    private function superuserToken(): array
    {
        $su    = Usuario::factory()->superuser()->create();
        $token = $su->createToken('test')->plainTextToken;
        return [$su, $token];
    }

    private function usuarioToken(): string
    {
        $u = Usuario::factory()->create();
        return $u->createToken('test')->plainTextToken;
    }

    // ─── Usuarios ────────────────────────────────────────────────

    public function test_superuser_puede_listar_usuarios(): void
    {
        [, $token] = $this->superuserToken();
        Usuario::factory()->count(3)->create();

        $this->withToken($token)->getJson('/api/admin/usuarios')
            ->assertStatus(200)
            ->assertJsonStructure(['data' => [['id','nombre','email','rol_global','activo']]]);
    }

    public function test_usuario_no_puede_acceder_a_admin(): void
    {
        $token = $this->usuarioToken();

        $this->withToken($token)->getJson('/api/admin/usuarios')->assertStatus(403);
    }

    public function test_superuser_puede_crear_usuario(): void
    {
        [, $token] = $this->superuserToken();

        $response = $this->withToken($token)->postJson('/api/admin/usuarios', [
            'nombre'    => 'Nuevo Usuario',
            'email'     => 'nuevo@test.com',
            'password'  => 'secret1234',
            'rol_global'=> 'usuario',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('email', 'nuevo@test.com');

        $this->assertDatabaseHas('usuarios', ['email' => 'nuevo@test.com']);
    }

    public function test_email_duplicado_falla_al_crear_usuario(): void
    {
        [, $token] = $this->superuserToken();
        Usuario::factory()->create(['email' => 'existe@test.com']);

        $this->withToken($token)->postJson('/api/admin/usuarios', [
            'nombre'   => 'Otro',
            'email'    => 'existe@test.com',
            'password' => 'secret1234',
        ])->assertStatus(422)->assertJsonValidationErrors(['email']);
    }

    public function test_superuser_puede_editar_usuario(): void
    {
        [, $token] = $this->superuserToken();
        $usuario = Usuario::factory()->create();

        $this->withToken($token)->putJson("/api/admin/usuarios/{$usuario->id}", [
            'nombre' => 'Nombre cambiado',
            'activo' => false,
        ])->assertStatus(200)->assertJsonPath('nombre', 'Nombre cambiado');
    }

    public function test_superuser_puede_eliminar_usuario(): void
    {
        [, $token] = $this->superuserToken();
        $usuario = Usuario::factory()->create();

        $this->withToken($token)->deleteJson("/api/admin/usuarios/{$usuario->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('usuarios', ['id' => $usuario->id]);
    }

    public function test_crear_usuario_con_tipos_los_asigna(): void
    {
        [, $token] = $this->superuserToken();
        $tipo = TipoUsuario::factory()->create();

        $resp = $this->withToken($token)->postJson('/api/admin/usuarios', [
            'nombre'    => 'Con Tipo',
            'email'     => 'contipo@test.com',
            'password'  => 'secret1234',
            'rol_global'=> 'usuario',
            'tipos'     => [$tipo->id],
        ])->assertStatus(201);

        $usuario = Usuario::where('email', 'contipo@test.com')->firstOrFail();
        $this->assertTrue($usuario->tiposUsuario->contains($tipo));
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $usuario->id, 'accion' => 'CREATE']);
    }

    public function test_editar_usuario_sin_mandar_tipos_no_los_borra(): void
    {
        [, $token] = $this->superuserToken();
        $tipo = TipoUsuario::factory()->create();
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach($tipo->id);

        $this->withToken($token)->putJson("/api/admin/usuarios/{$usuario->id}", [
            'activo' => false,
        ])->assertStatus(200);

        $this->assertTrue($usuario->fresh()->tiposUsuario->contains($tipo));
    }

    public function test_editar_usuario_mandando_tipos_los_reemplaza(): void
    {
        [, $token] = $this->superuserToken();
        $tipoViejo = TipoUsuario::factory()->create();
        $tipoNuevo = TipoUsuario::factory()->create();
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach($tipoViejo->id);

        $this->withToken($token)->putJson("/api/admin/usuarios/{$usuario->id}", [
            'tipos' => [$tipoNuevo->id],
        ])->assertStatus(200);

        $usuario->refresh();
        $this->assertFalse($usuario->tiposUsuario->contains($tipoViejo));
        $this->assertTrue($usuario->tiposUsuario->contains($tipoNuevo));
        $this->assertDatabaseHas('usuarios_aplicaciones_log', ['entidad_id' => $usuario->id, 'accion' => 'UPDATE']);
    }

    // ─── Tipos de usuario ────────────────────────────────────────

    public function test_superuser_puede_crear_tipo_usuario(): void
    {
        [, $token] = $this->superuserToken();

        $this->withToken($token)->postJson('/api/admin/tipos-usuario', [
            'nombre'      => 'Calidad',
            'descripcion' => 'Inspector de calidad',
        ])->assertStatus(201)->assertJsonPath('nombre', 'Calidad');

        $this->assertDatabaseHas('tipos_usuario', ['nombre' => 'Calidad']);
    }

    public function test_superuser_puede_listar_tipos_usuario(): void
    {
        [, $token] = $this->superuserToken();
        TipoUsuario::factory()->count(2)->create();

        $this->withToken($token)->getJson('/api/admin/tipos-usuario')
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_superuser_puede_editar_tipo_usuario(): void
    {
        [, $token] = $this->superuserToken();
        $tipo = TipoUsuario::factory()->create(['nombre' => 'Original']);

        $this->withToken($token)->putJson("/api/admin/tipos-usuario/{$tipo->id}", [
            'nombre' => 'Actualizado',
        ])->assertStatus(200)->assertJsonPath('nombre', 'Actualizado');
    }

    public function test_nombre_de_tipo_usuario_es_unico_a_nivel_de_base_de_datos(): void
    {
        TipoUsuario::factory()->create(['nombre' => 'Calidad']);

        $this->expectException(\Illuminate\Database\QueryException::class);
        TipoUsuario::query()->insert(['nombre' => 'Calidad', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    public function test_tipo_usuario_tiene_usuarios_y_secciones_con_nivel(): void
    {
        $app = \App\Models\AplicacionExterna::create(['codigo' => 'kpis-sso', 'nombre' => 'KPI', 'url_base' => 'https://x', 'activo' => true]);
        $seccion = $app->secciones()->create(['codigo' => 'metricas', 'nombre' => 'Métricas']);
        $tipo = TipoUsuario::factory()->create();
        $tipo->secciones()->attach($seccion->id, ['nivel' => 'editar']);
        $usuario = Usuario::factory()->create();
        $usuario->tiposUsuario()->attach($tipo->id);

        $this->assertTrue($tipo->usuarios->contains($usuario));
        $this->assertSame('editar', $tipo->secciones->first()->pivot->nivel);
        $this->assertTrue($usuario->tiposUsuario->contains($tipo));
    }

}
