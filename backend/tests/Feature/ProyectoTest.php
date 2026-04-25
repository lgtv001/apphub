<?php
namespace Tests\Feature;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProyectoTest extends TestCase
{
    use RefreshDatabase;

    private function asSuperuser(): array
    {
        $su = Usuario::factory()->superuser()->create();
        return [$su, $su->createToken('test')->plainTextToken];
    }

    private function asUsuario(): array
    {
        $u = Usuario::factory()->create();
        return [$u, $u->createToken('test')->plainTextToken];
    }

    public function test_usuario_solo_ve_proyectos_asignados(): void
    {
        [$usuario, $token] = $this->asUsuario();
        $asignado    = Proyecto::factory()->create();
        $no_asignado = Proyecto::factory()->create();
        UsuarioProyecto::create(['usuario_id' => $usuario->id, 'proyecto_id' => $asignado->id, 'rol' => 'usuario']);
        $this->withToken($token)->getJson('/api/proyectos')
            ->assertStatus(200)->assertJsonCount(1, 'data')->assertJsonPath('data.0.id', $asignado->id);
    }

    public function test_superuser_ve_todos_los_proyectos(): void
    {
        [, $token] = $this->asSuperuser();
        Proyecto::factory()->count(3)->create();
        $this->withToken($token)->getJson('/api/proyectos')
            ->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_superuser_puede_crear_proyecto(): void
    {
        [, $token] = $this->asSuperuser();
        $this->withToken($token)->postJson('/api/proyectos', ['codigo' => 'AUT-001', 'nombre' => 'Autopista Norte', 'estado' => 'activo'])
            ->assertStatus(201)->assertJsonPath('codigo', 'AUT-001');
        $this->assertDatabaseHas('proyectos', ['codigo' => 'AUT-001']);
    }

    public function test_usuario_no_puede_crear_proyecto(): void
    {
        [, $token] = $this->asUsuario();
        $this->withToken($token)->postJson('/api/proyectos', ['codigo' => 'AUT-001', 'nombre' => 'Test'])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_falla_al_crear(): void
    {
        [, $token] = $this->asSuperuser();
        Proyecto::factory()->create(['codigo' => 'AUT-001']);
        $this->withToken($token)->postJson('/api/proyectos', ['codigo' => 'AUT-001', 'nombre' => 'Otro'])
            ->assertStatus(422)->assertJsonValidationErrors(['codigo']);
    }

    public function test_superuser_puede_editar_proyecto(): void
    {
        [, $token] = $this->asSuperuser();
        $proyecto  = Proyecto::factory()->create();
        $this->withToken($token)->putJson("/api/proyectos/{$proyecto->id}", ['nombre' => 'Actualizado', 'estado' => 'archivado'])
            ->assertStatus(200)->assertJsonPath('nombre', 'Actualizado')->assertJsonPath('estado', 'archivado');
    }

    public function test_usuario_no_puede_editar_proyecto(): void
    {
        [, $token] = $this->asUsuario();
        $proyecto  = Proyecto::factory()->create();
        $this->withToken($token)->putJson("/api/proyectos/{$proyecto->id}", ['nombre' => 'Hack'])
            ->assertStatus(403);
    }

    public function test_sin_token_retorna_401(): void
    {
        $this->getJson('/api/proyectos')->assertStatus(401);
    }
}
