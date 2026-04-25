<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;

class LogService
{
    public static function log(
        string $tabla,
        ?int $proyectoId,
        int $usuarioId,
        string $accion,
        ?int $entidadId,
        ?array $datosAntes = null,
        ?array $datosDespues = null,
        ?array $errorDetalle = null,
        ?string $ip = null
    ): void {
        DB::table("{$tabla}_log")->insert([
            'proyecto_id'   => $proyectoId,
            'usuario_id'    => $usuarioId,
            'accion'        => $accion,
            'entidad_id'    => $entidadId,
            'datos_antes'   => $datosAntes   ? json_encode($datosAntes)   : null,
            'datos_despues' => $datosDespues ? json_encode($datosDespues) : null,
            'error_detalle' => $errorDetalle ? json_encode($errorDetalle) : null,
            'ip'            => $ip,
            'created_at'    => now(),
        ]);
    }
}
