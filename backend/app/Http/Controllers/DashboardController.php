<?php

namespace App\Http\Controllers;

use App\Models\Proyecto;
use App\Models\Subsistema;

class DashboardController extends Controller
{
    public function show(int $proyecto_id)
    {
        $proyecto = Proyecto::findOrFail($proyecto_id);

        $subsistemas = Subsistema::where('proyecto_id', $proyecto_id)
            ->with('sistema:id,nombre')
            ->orderBy('codigo')
            ->get();

        $total      = $subsistemas->count();
        $conAvance  = $subsistemas->filter(fn($s) => $s->avance_constructivo !== null)->count();
        // null avance counts as 0 — consistent with spec: promedio sobre todos los subsistemas
        $promedio   = $total > 0
            ? (int) round($subsistemas->avg(fn($s) => $s->avance_constructivo ?? 0))
            : 0;
        $conRetraso = $subsistemas->filter(
            fn($s) => $s->fecha_termino_plan !== null
                   && $s->fecha_termino_real !== null
                   && $s->fecha_termino_real->gt($s->fecha_termino_plan)
        )->count();

        $rows = $subsistemas->map(fn($s) => [
            'id'                  => $s->id,
            'codigo'              => $s->codigo,
            'nombre'              => $s->nombre,
            'sistema_nombre'      => $s->sistema?->nombre ?? '',
            'fecha_inicio_plan'   => $s->fecha_inicio_plan?->format('Y-m-d'),
            'fecha_termino_plan'  => $s->fecha_termino_plan?->format('Y-m-d'),
            'fecha_inicio_real'   => $s->fecha_inicio_real?->format('Y-m-d'),
            'fecha_termino_real'  => $s->fecha_termino_real?->format('Y-m-d'),
            'avance_constructivo' => $s->avance_constructivo,
        ]);

        return response()->json([
            'proyecto' => [
                'id'     => $proyecto->id,
                'codigo' => $proyecto->codigo,
                'nombre' => $proyecto->nombre,
            ],
            'resumen' => [
                'total_subsistemas' => $total,
                'con_avance'        => $conAvance,
                'avance_promedio'   => $promedio,
                'con_retraso'       => $conRetraso,
            ],
            'subsistemas' => $rows,
        ]);
    }
}
