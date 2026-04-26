<?php

namespace App\Http\Controllers;

use App\Exports\QuiebreTemplateExport;
use App\Services\ImportService;
use Illuminate\Http\Request;
use Maatwebsite\Excel\Facades\Excel;

class ImportController extends Controller
{
    public function __construct(private ImportService $service) {}

    public function template(int $proyecto_id)
    {
        return Excel::download(new QuiebreTemplateExport(), 'plantilla-quiebre.xlsx');
    }

    public function preview(Request $request, int $proyecto_id)
    {
        $request->validate([
            'archivo' => 'required|file|mimes:xlsx,xls|max:10240',
        ]);

        $coleccion = Excel::toCollection(null, $request->file('archivo'))->first();

        if ($coleccion->isEmpty()) {
            return response()->json(['message' => 'El archivo está vacío'], 422);
        }

        // First row contains the headers
        $cabeceras = $coleccion->first()->values()->toArray();
        $columnas_requeridas = ['codigo', 'nombre', 'nivel', 'codigo_padre_de_quiebre'];
        $faltantes = array_diff($columnas_requeridas, $cabeceras);

        if (!empty($faltantes)) {
            return response()->json([
                'message'   => 'Columnas faltantes en el archivo',
                'faltantes' => array_values($faltantes),
            ], 422);
        }

        // Skip header row and map each row to an associative array using headers as keys
        $filas = $coleccion->slice(1)->map(
            fn($row) => array_combine($cabeceras, $row->values()->toArray())
        )->toArray();
        $resultado = $this->service->preview($filas, $proyecto_id);

        return response()->json([
            'total'      => count($resultado),
            'validas'    => array_values(array_filter($resultado, fn($r) => $r['status'] === 'valid')),
            'duplicados' => array_values(array_filter($resultado, fn($r) => $r['status'] === 'duplicate')),
            'errores'    => array_values(array_filter($resultado, fn($r) => $r['status'] === 'error')),
        ]);
    }

    public function confirm(Request $request, int $proyecto_id)
    {
        $request->validate([
            'filas'              => 'required|array|min:1',
            'filas.*.codigo'     => 'required|string',
            'filas.*.nombre'     => 'required|string',
            'filas.*.nivel'      => 'required|in:area,subarea,sistema,subsistema',
            'filas.*.decision'   => 'required|in:import,skip',
        ]);

        $resumen = $this->service->confirm(
            filas:      $request->filas,
            proyectoId: $proyecto_id,
            usuarioId:  $request->user()->id,
            ip:         $request->ip()
        );

        return response()->json($resumen);
    }
}
