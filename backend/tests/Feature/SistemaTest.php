<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Sistema;
use App\Models\Subarea;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SistemaTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $proyecto = Proyecto::factory()->create();
        $area     = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea  = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);
        $admin = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $subarea, $admin, $token];
    }

    public function test_puede_listar_sistemas_de_proyecto(): void
    {
        [$proyecto, $subarea, , $token] = $this->makeFixture();
        Sistema::factory()->count(2)->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/sistemas")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_puede_filtrar_sistemas_por_subarea(): void
    {
        [$proyecto, $subarea, , $token] = $this->makeFixture();
        $area2    = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea2 = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area2->id,
        ]);

        Sistema::factory()->count(3)->create(['proyecto_id' => $proyecto->id, 'subarea_id' => $subarea->id]);
        Sistema::factory()->create(['proyecto_id' => $proyecto->id, 'subarea_id' => $subarea2->id]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/sistemas?subarea_id={$subarea->id}")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_admin_puede_crear_sistema(): void
    {
        [$proyecto, $subarea, , $token] = $this->makeFixture();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea->id,
                'codigo'     => '3610B',
                'nombre'     => 'Pilotes',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3610B')
            ->assertJsonPath('subarea_id', $subarea->id);

        $this->assertDatabaseHas('sistemas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3610B',
        ]);
    }

    public function test_subarea_padre_debe_pertenecer_al_proyecto(): void
    {
        [$proyecto, , , $token] = $this->makeFixture();
        $subarea_ajena = Subarea::factory()->create(); // subarea de otro proyecto

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea_ajena->id,
                'codigo'     => '3610B',
                'nombre'     => 'Pilotes',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['subarea_id']);
    }

    public function test_usuario_no_puede_crear_sistema(): void
    {
        [$proyecto, $subarea] = $this->makeFixture();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea->id,
                'codigo'     => '3610B',
                'nombre'     => 'Pilotes',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, $subarea, , $token] = $this->makeFixture();
        Sistema::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3610B']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/sistemas", [
                'subarea_id' => $subarea->id,
                'codigo'     => '3610B',
                'nombre'     => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_admin_puede_editar_sistema(): void
    {
        [$proyecto, $subarea, , $token] = $this->makeFixture();
        $sistema = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/sistemas/{$sistema->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_sistema(): void
    {
        [$proyecto, $subarea, , $token] = $this->makeFixture();
        $sistema = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/sistemas/{$sistema->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('sistemas', ['id' => $sistema->id]);
    }
}
