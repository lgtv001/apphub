<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Sistema;
use App\Models\Subarea;
use App\Models\Subsistema;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class SubsistemaTest extends TestCase
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
        $sistema  = Sistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'subarea_id'  => $subarea->id,
        ]);
        $admin = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $sistema, $admin, $token];
    }

    public function test_puede_listar_subsistemas_de_proyecto(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        Subsistema::factory()->count(3)->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subsistemas")
            ->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_puede_filtrar_subsistemas_por_sistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        $area2    = Area::factory()->create(['proyecto_id' => $proyecto->id]);
        $subarea2 = Subarea::factory()->create(['proyecto_id' => $proyecto->id, 'area_id' => $area2->id]);
        $sistema2 = Sistema::factory()->create(['proyecto_id' => $proyecto->id, 'subarea_id' => $subarea2->id]);

        Subsistema::factory()->count(2)->create(['proyecto_id' => $proyecto->id, 'sistema_id' => $sistema->id]);
        Subsistema::factory()->count(4)->create(['proyecto_id' => $proyecto->id, 'sistema_id' => $sistema2->id]);

        $this->withToken($token)
            ->getJson("/api/proyectos/{$proyecto->id}/subsistemas?sistema_id={$sistema->id}")
            ->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_puede_crear_subsistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Pilotes hormigón',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('codigo', '3610B-1')
            ->assertJsonPath('sistema_id', $sistema->id);

        $this->assertDatabaseHas('subsistemas', [
            'proyecto_id' => $proyecto->id,
            'codigo'      => '3610B-1',
        ]);
    }

    public function test_sistema_padre_debe_pertenecer_al_proyecto(): void
    {
        [$proyecto, , , $token] = $this->makeFixture();
        $sistema_ajeno = Sistema::factory()->create(); // sistema de otro proyecto

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema_ajeno->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Pilotes hormigón',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['sistema_id']);
    }

    public function test_usuario_no_puede_crear_subsistema(): void
    {
        [$proyecto, $sistema] = $this->makeFixture();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Pilotes hormigón',
            ])
            ->assertStatus(403);
    }

    public function test_codigo_duplicado_en_mismo_proyecto_falla(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        Subsistema::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3610B-1']);

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id' => $sistema->id,
                'codigo'     => '3610B-1',
                'nombre'     => 'Duplicado',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['codigo']);
    }

    public function test_admin_puede_editar_subsistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}", [
                'nombre' => 'Nombre editado',
            ])
            ->assertStatus(200)
            ->assertJsonPath('nombre', 'Nombre editado');
    }

    public function test_admin_puede_eliminar_subsistema(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}")
            ->assertStatus(204);

        $this->assertDatabaseMissing('subsistemas', ['id' => $subsistema->id]);
    }

    public function test_log_se_registra_al_eliminar_subsistema(): void
    {
        [$proyecto, $sistema, $admin, $token] = $this->makeFixture();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id' => $proyecto->id,
            'sistema_id'  => $sistema->id,
        ]);

        $this->withToken($token)
            ->deleteJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}");

        $this->assertDatabaseHas('subsistemas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'DELETE',
            'entidad_id'  => $subsistema->id,
        ]);
    }

    public function test_admin_puede_crear_subsistema_con_avance(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id'          => $sistema->id,
                'codigo'              => 'AV-001',
                'nombre'              => 'Con avance',
                'fecha_inicio_plan'   => '2026-05-01',
                'fecha_termino_plan'  => '2026-06-30',
                'fecha_inicio_real'   => '2026-05-03',
                'avance_constructivo' => 40,
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('avance_constructivo', 40)
            ->assertJsonPath('fecha_inicio_plan', '2026-05-01');

        $this->assertDatabaseHas('subsistemas', [
            'proyecto_id'         => $proyecto->id,
            'codigo'              => 'AV-001',
            'avance_constructivo' => 40,
        ]);
    }

    public function test_avance_constructivo_debe_estar_entre_0_y_100(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id'          => $sistema->id,
                'codigo'              => 'AV-001',
                'nombre'              => 'Con avance',
                'avance_constructivo' => 101,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avance_constructivo']);
    }

    public function test_admin_puede_actualizar_avance_constructivo(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();
        $subsistema = Subsistema::factory()->create([
            'proyecto_id'         => $proyecto->id,
            'sistema_id'          => $sistema->id,
            'avance_constructivo' => null,
        ]);

        $this->withToken($token)
            ->putJson("/api/proyectos/{$proyecto->id}/subsistemas/{$subsistema->id}", [
                'avance_constructivo' => 75,
                'fecha_inicio_real'   => '2026-05-10',
            ])
            ->assertStatus(200)
            ->assertJsonPath('avance_constructivo', 75)
            ->assertJsonPath('fecha_inicio_real', '2026-05-10');
    }

    public function test_avance_constructivo_negativo_falla(): void
    {
        [$proyecto, $sistema, , $token] = $this->makeFixture();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/subsistemas", [
                'sistema_id'          => $sistema->id,
                'codigo'              => 'AV-001',
                'nombre'              => 'Con avance negativo',
                'avance_constructivo' => -1,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors(['avance_constructivo']);
    }
}
