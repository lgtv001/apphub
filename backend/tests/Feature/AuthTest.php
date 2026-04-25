<?php
namespace Tests\Feature;
use App\Models\Usuario;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AuthTest extends TestCase
{
    use RefreshDatabase;

    public function test_login_con_credenciales_validas(): void
    {
        $usuario = Usuario::factory()->create([
            'email'         => 'test@example.com',
            'password_hash' => bcrypt('secret123'),
        ]);
        $response = $this->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'secret123']);
        $response->assertStatus(200)->assertJsonStructure(['token', 'usuario' => ['id', 'nombre', 'email', 'rol_global']]);
    }

    public function test_login_con_password_incorrecto(): void
    {
        Usuario::factory()->create(['email' => 'test@example.com']);
        $this->postJson('/api/auth/login', ['email' => 'test@example.com', 'password' => 'wrong'])
            ->assertStatus(401)->assertJson(['message' => 'Credenciales inválidas']);
    }

    public function test_login_con_email_inexistente(): void
    {
        $this->postJson('/api/auth/login', ['email' => 'noexiste@example.com', 'password' => 'secret123'])
            ->assertStatus(401);
    }

    public function test_login_con_usuario_inactivo(): void
    {
        Usuario::factory()->inactivo()->create(['email' => 'inactivo@example.com', 'password_hash' => bcrypt('secret123')]);
        $this->postJson('/api/auth/login', ['email' => 'inactivo@example.com', 'password' => 'secret123'])
            ->assertStatus(403)->assertJson(['message' => 'Usuario inactivo']);
    }

    public function test_login_sin_campos_requeridos(): void
    {
        $this->postJson('/api/auth/login', [])->assertStatus(422)->assertJsonValidationErrors(['email', 'password']);
    }

    public function test_me_retorna_usuario_autenticado(): void
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;
        $this->withToken($token)->getJson('/api/auth/me')
            ->assertStatus(200)->assertJson(['id' => $usuario->id, 'email' => $usuario->email]);
    }

    public function test_me_sin_token_retorna_401(): void
    {
        $this->getJson('/api/auth/me')->assertStatus(401);
    }

    public function test_logout_revoca_token(): void
    {
        $usuario = Usuario::factory()->create();
        $token   = $usuario->createToken('test')->plainTextToken;
        $this->withToken($token)->postJson('/api/auth/logout')->assertStatus(204);
        $this->withToken($token)->getJson('/api/auth/me')->assertStatus(401);
    }
}
