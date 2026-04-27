<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Subarea;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubareaTest extends TestCase
{
    use RefreshDatabase;

    private function makeFixture(): array
    {
        $proyecto = Proyecto::factory()->create();
        $area     = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $admin    = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $area, $admin, $token];
    }

    public function test_puede_listar_subareas_de_proyecto(): void
    {
        [$proyecto, $area, , $token] = $this->makeFixture();
        Subarea::factory()->count(2)->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subareas")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_puede_filtrar_subareas_por_area(): void
    {
        [$proyecto, $area, , $token] = $this->makeFixture();
        $otra_area = Area::factory()->create(['proyecto_id' => $proyecto->id]);

        Subarea::factory()->count(2)->create(['proyecto_id' => $proyecto->id, 'area_id' => $area->id]);
        Subarea::factory()->create(['proyecto_id' => $proyecto->id, 'area_id' => $otra_area->id]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subareas?area_id={$area->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_puede_crear_subarea(): void
    {
        [$proyecto, $area, , $token] = $this->makeFixture();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area->id,
                'codigo'  => '3610',
                'nombre'  => 'Fundaciones',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3610')
            ->assertJsonPath('area_id', $area->id);

        $this->assertDatabaseHas('subareas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3610',
        ]);
    }

    public function test_area_padre_debe_pertenecer_al_proyecto(): void
    {
        [$proyecto, , , $token] = $this->makeFixture();
        $area_ajena = Area::factory()->create(); // area de otro proyecto

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area_ajena->id,
                'codigo'  => '3610',
                'nombre'  => 'Fundaciones',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['area_id']);
    }

    public function test_usuario_no_puede_crear_subarea(): void
    {
        [$proyecto, $area] = $this->makeFixture();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area->id,
                'codigo'  => '3610',
                'nombre'  => 'Fundaciones',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, $area, , $token] = $this->makeFixture();
        Subarea::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3610']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subareas", [
                'area_id' => $area->id,
                'codigo'  => '3610',
                'nombre'  => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_admin_puede_editar_subarea(): void
    {
        [$proyecto, $area, , $token] = $this->makeFixture();
        $subarea = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/subareas/{$subarea->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_subarea(): void
    {
        [$proyecto, $area, , $token] = $this->makeFixture();
        $subarea = Subarea::factory()->create([
            'proyecto_id' => $proyecto->id,
            'area_id'     => $area->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/subareas/{$subarea->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('subareas', ['id' => $subarea->id]);
    }
}
