<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class LogController extends Controller
{
    private const TABLAS = [
        'areas', 'subareas', 'sistemas', 'subsistemas',
        'proyectos', 'usuarios', 'usuarios_proyectos',
    ];

    public function index(Request $request)
    {
        $proyectoId = $request->query('proyecto_id');

        $queries = array_map(function (string $tabla) use ($proyectoId) {
            $query = DB::table("{$tabla}_log")
                ->select(
                    DB::raw("'{$tabla}' as origen"),
                    'id',
                    'proyecto_id',
                    'usuario_id',
                    'accion',
                    'entidad_id',
                    'created_at'
                );

            if ($proyectoId) {
                $query->where('proyecto_id', $proyectoId);
            }

            return $query;
        }, self::TABLAS);

        $union = array_shift($queries);
        foreach ($queries as $q) {
            $union->unionAll($q);
        }

        $resultados = DB::query()
            ->fromSub($union, 'logs_union')
            ->orderByDesc('created_at')
            ->limit(200)
            ->get();

        return response()->json([
            'data'  => $resultados,
            'total' => $resultados->count(),
        ]);
    }
}
