<?php
namespace Tests\Feature;
use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AreaTest extends TestCase
{
    use RefreshDatabase;

    private function proyectoConAdmin(): array
    {
        $proyecto = Proyecto::factory()->create();
        $admin    = Usuario::factory()->admin()->create();
        UsuarioProyecto::create(['usuario_id' => $admin->id, 'proyecto_id' => $proyecto->id, 'rol' => 'admin']);
        return [$proyecto, $admin, $admin->createToken('test')->plainTextToken];
    }

    private function proyectoConUsuario(): array
    {
        $proyecto = Proyecto::factory()->create();
        $usuario  = Usuario::factory()->create();
        UsuarioProyecto::create(['usuario_id' => $usuario->id, 'proyecto_id' => $proyecto->id, 'rol' => 'usuario']);
        return [$proyecto, $usuario, $usuario->createToken('test')->plainTextToken];
    }

    public function test_usuario_puede_listar_areas_de_su_proyecto(): void
    {
        [$proyecto, , $token] = $this->proyectoConUsuario();
        Area::factory()->count(3)->create(['proyecto_id' => $proyecto->id]);
        $this->withToken($token)->getJson("/api/proyectos/{$proyecto->id}/areas")
            ->assertStatus(200)->assertJsonCount(3, 'data');
    }

    public function test_usuario_no_puede_listar_areas_de_proyecto_ajeno(): void
    {
        [, , $token] = $this->proyectoConUsuario();
        $otro = Proyecto::factory()->create();
        $this->withToken($token)->getJson("/api/proyectos/{$otro->id}/areas")->assertStatus(403);
    }

    public function test_admin_puede_crear_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $this->withToken($token)->postJson("/api/proyectos/{$proyecto->id}/areas", ['codigo' => '3600', 'nombre' => 'Estructura'])
            ->assertStatus(201)->assertJsonPath('codigo', '3600');
        $this->assertDatabaseHas('areas', ['proyecto_id' => $proyecto->id, 'codigo' => '3600']);
    }

    public function test_usuario_no_puede_crear_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConUsuario();
        $this->withToken($token)->postJson("/api/proyectos/{$proyecto->id}/areas", ['codigo' => '3600', 'nombre' => 'Estructura'])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        Area::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3600']);
        $this->withToken($token)->postJson("/api/proyectos/{$proyecto->id}/areas", ['codigo' => '3600', 'nombre' => 'Dup'])
            ->assertStatus(422)->assertJsonValidationErrors(['codigo']);
    }

    public function test_mismo_codigo_en_distinto_proyecto_es_valido(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $otro = Proyecto::factory()->create();
        Area::factory()->create(['proyecto_id' => $otro->id, 'codigo' => '3600']);
        $this->withToken($token)->postJson("/api/proyectos/{$proyecto->id}/areas", ['codigo' => '3600', 'nombre' => 'Estructura'])
            ->assertStatus(201);
    }

    public function test_admin_puede_editar_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $area = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $this->withToken($token)->putJson("/api/proyectos/{$proyecto->id}/areas/{$area->id}", ['nombre' => 'Editado'])
            ->assertStatus(200)->assertJsonPath('nombre', 'Editado');
    }

    public function test_admin_puede_eliminar_area(): void
    {
        [$proyecto, , $token] = $this->proyectoConAdmin();
        $area = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $this->withToken($token)->deleteJson("/api/proyectos/{$proyecto->id}/areas/{$area->id}")->assertStatus(204);
        $this->assertDatabaseMissing('areas', ['id' => $area->id]);
    }

    public function test_log_se_registra_al_crear_area(): void
    {
        [$proyecto, $admin, $token] = $this->proyectoConAdmin();
        $this->withToken($token)->postJson("/api/proyectos/{$proyecto->id}/areas", ['codigo' => '3600', 'nombre' => 'Estructura']);
        $this->assertDatabaseHas('areas_log', ['proyecto_id' => $proyecto->id, 'usuario_id' => $admin->id, 'accion' => 'CREATE']);
    }
}
