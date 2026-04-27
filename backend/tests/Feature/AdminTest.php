<?php

namespace Tests\Feature;

use App\Models\Proyecto;
use App\Models\TipoUsuario;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
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

    // ─── Asignaciones ────────────────────────────────────────────

    public function test_superuser_puede_asignar_usuario_a_proyecto(): void
    {
        [, $token] = $this->superuserToken();
        $usuario  = Usuario::factory()->create();
        $proyecto = Proyecto::factory()->create();

        $this->withToken($token)->postJson('/api/admin/asignaciones', [
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ])->assertStatus(201);

        $this->assertDatabaseHas('usuarios_proyectos', [
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
    }

    public function test_asignacion_duplicada_falla(): void
    {
        [, $token] = $this->superuserToken();
        $usuario  = Usuario::factory()->create();
        $proyecto = Proyecto::factory()->create();

        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);

        $this->withToken($token)->postJson('/api/admin/asignaciones', [
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ])->assertStatus(422);
    }

    public function test_superuser_puede_revocar_asignacion(): void
    {
        [, $token] = $this->superuserToken();
        $asignacion = UsuarioProyecto::factory()->create();

        $this->withToken($token)->deleteJson("/api/admin/asignaciones/{$asignacion->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('usuarios_proyectos', ['id' => $asignacion->id]);
    }

    public function test_superuser_puede_listar_asignaciones(): void
    {
        [, $token] = $this->superuserToken();
        UsuarioProyecto::factory()->count(3)->create();

        $this->withToken($token)->getJson('/api/admin/asignaciones')
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }
}
