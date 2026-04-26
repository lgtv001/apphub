<?php

namespace Tests\Feature;

use App\Models\Area;
use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Maatwebsite\Excel\Facades\Excel;
use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use Tests\TestCase;

class ImportTest extends TestCase
{
    use RefreshDatabase;

    private function makeSetup(): array
    {
        $proyecto = Proyecto::factory()->create();
        $admin    = Usuario::factory()->admin()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $admin->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'admin',
        ]);
        $token = $admin->createToken('test')->plainTextToken;
        return [$proyecto, $admin, $token];
    }

    private function crearExcel(array $cabeceras, array $filas): UploadedFile
    {
        $spreadsheet = new Spreadsheet();
        $sheet       = $spreadsheet->getActiveSheet();
        $sheet->fromArray(array_merge([$cabeceras], $filas));

        $tmpPath = tempnam(sys_get_temp_dir(), 'import_test_') . '.xlsx';
        (new Xlsx($spreadsheet))->save($tmpPath);

        return new UploadedFile(
            $tmpPath,
            'import.xlsx',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            null,
            true
        );
    }

    public function test_descarga_plantilla(): void
    {
        [$proyecto, , $token] = $this->makeSetup();

        $response = $this->withToken($token)
            ->get("/api/proyectos/{$proyecto->id}/import/template");

        $response->assertStatus(200)
            ->assertHeader('Content-Type',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet');
    }

    public function test_preview_clasifica_filas_validas_y_duplicadas(): void
    {
        [$proyecto, , $token] = $this->makeSetup();
        Area::factory()->create(['proyecto_id' => $proyecto->id, 'codigo' => '3600']);

        $archivo = $this->crearExcel(
            ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'],
            [
                ['3600', 'Estructura',  'area', ''],
                ['3700', 'Arquitectura','area', ''],
            ]
        );

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/preview", ['archivo' => $archivo]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'validas')
            ->assertJsonCount(1, 'duplicados')
            ->assertJsonCount(0, 'errores');
    }

    public function test_preview_detecta_padre_inexistente(): void
    {
        [$proyecto, , $token] = $this->makeSetup();

        $archivo = $this->crearExcel(
            ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'],
            [
                ['3610', 'Fundaciones', 'subarea', '9999'],
            ]
        );

        $response = $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/preview", ['archivo' => $archivo]);

        $response->assertStatus(200)
            ->assertJsonCount(1, 'errores')
            ->assertJsonCount(0, 'validas');
    }

    public function test_preview_falla_si_faltan_columnas(): void
    {
        [$proyecto, , $token] = $this->makeSetup();

        $archivo = $this->crearExcel(
            ['codigo', 'nombre'],
            [['3600', 'Estructura']]
        );

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/preview", ['archivo' => $archivo])
            ->assertStatus(422)
            ->assertJsonPath('message', 'Columnas faltantes en el archivo');
    }

    public function test_confirm_importa_filas_validas(): void
    {
        [$proyecto, , $token] = $this->makeSetup();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", [
                'filas' => [
                    ['codigo' => '3600', 'nombre' => 'Estructura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'valid', 'decision' => 'import'],
                    ['codigo' => '3700', 'nombre' => 'Arquitectura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'valid', 'decision' => 'import'],
                ],
            ])
            ->assertStatus(200)
            ->assertJson(['importadas' => 2, 'omitidas' => 0, 'errores' => 0]);

        $this->assertDatabaseHas('areas', ['proyecto_id' => $proyecto->id, 'codigo' => '3600']);
        $this->assertDatabaseHas('areas', ['proyecto_id' => $proyecto->id, 'codigo' => '3700']);
    }

    public function test_confirm_registra_log_por_fila_importada(): void
    {
        [$proyecto, $admin, $token] = $this->makeSetup();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", [
                'filas' => [
                    ['codigo' => '3600', 'nombre' => 'Estructura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'valid', 'decision' => 'import'],
                ],
            ]);

        $this->assertDatabaseHas('areas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'IMPORT',
        ]);
    }

    public function test_confirm_registra_log_dismiss_al_omitir_duplicado(): void
    {
        [$proyecto, $admin, $token] = $this->makeSetup();

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", [
                'filas' => [
                    ['codigo' => '3600', 'nombre' => 'Estructura', 'nivel' => 'area',
                     'codigo_padre_de_quiebre' => null, 'status' => 'duplicate',
                     'motivo' => 'duplicado', 'fila' => 2, 'decision' => 'skip'],
                ],
            ]);

        $this->assertDatabaseHas('areas_log', [
            'proyecto_id' => $proyecto->id,
            'usuario_id'  => $admin->id,
            'accion'      => 'IMPORT_ERROR_DISMISSED',
        ]);
    }

    public function test_usuario_sin_rol_admin_no_puede_importar(): void
    {
        [$proyecto] = $this->makeSetup();
        $usuario = Usuario::factory()->create();
        UsuarioProyecto::create([
            'usuario_id'  => $usuario->id,
            'proyecto_id' => $proyecto->id,
            'rol'         => 'usuario',
        ]);
        $token = $usuario->createToken('test')->plainTextToken;

        $this->withToken($token)
            ->postJson("/api/proyectos/{$proyecto->id}/import/confirm", ['filas' => []])
            ->assertStatus(403);
    }
}
