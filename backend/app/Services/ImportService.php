<?php

namespace App\Services;

use App\Models\Area;
use App\Models\Subarea;
use App\Models\Sistema;
use App\Models\Subsistema;
use Illuminate\Support\Facades\DB;

class ImportService
{
    private const NIVELES = ['area', 'subarea', 'sistema', 'subsistema'];

    public function preview(array $rows, int $proyectoId): array
    {
        $result = [];

        foreach ($rows as $index => $fila) {
            $fila_num = $index + 2;

            if (empty($fila['codigo']) || empty($fila['nombre']) || empty($fila['nivel'])) {
                $result[] = $this->filaError($fila, $fila_num, 'codigo o nombre o nivel está vacío');
                continue;
            }

            if (!in_array($fila['nivel'], self::NIVELES)) {
                $result[] = $this->filaError($fila, $fila_num,
                    "nivel '{$fila['nivel']}' no válido. Valores: area, subarea, sistema, subsistema");
                continue;
            }

            if ($fila['nivel'] !== 'area') {
                $padre = $fila['codigo_padre_de_quiebre'] ?? null;
                if (empty($padre)) {
                    $result[] = $this->filaError($fila, $fila_num,
                        "codigo_padre_de_quiebre es obligatorio para nivel '{$fila['nivel']}'");
                    continue;
                }

                if (!$this->padreExiste($padre, $fila['nivel'], $proyectoId)) {
                    $result[] = $this->filaError($fila, $fila_num,
                        "El padre '{$padre}' no existe en el proyecto para el nivel anterior");
                    continue;
                }
            }

            if ($this->codigoExisteEnDB($fila['codigo'], $fila['nivel'], $proyectoId)) {
                $result[] = array_merge($fila, [
                    'fila'   => $fila_num,
                    'status' => 'duplicate',
                    'motivo' => "El código '{$fila['codigo']}' ya existe en el proyecto",
                ]);
                continue;
            }

            $result[] = array_merge($fila, ['fila' => $fila_num, 'status' => 'valid']);
        }

        return $result;
    }

    public function confirm(array $filas, int $proyectoId, int $usuarioId, string $ip): array
    {
        $importadas = 0;
        $omitidas   = 0;
        $errores    = 0;

        DB::transaction(function () use ($filas, $proyectoId, $usuarioId, $ip, &$importadas, &$omitidas, &$errores) {
            foreach ($filas as $fila) {
                if (($fila['decision'] ?? 'skip') === 'skip') {
                    $omitidas++;

                    if (in_array($fila['status'] ?? '', ['duplicate', 'error'])) {
                        LogService::log(
                            tabla:        $this->tablaDeNivel($fila['nivel']),
                            proyectoId:   $proyectoId,
                            usuarioId:    $usuarioId,
                            accion:       'IMPORT_ERROR_DISMISSED',
                            entidadId:    null,
                            errorDetalle: [
                                'campo'            => 'codigo',
                                'motivo'           => $fila['motivo'] ?? 'omitido por usuario',
                                'valor_ingresado'  => $fila['codigo'],
                                'fila_excel'       => $fila['fila'],
                                'decision_usuario' => 'omitir',
                            ],
                            ip: $ip
                        );
                    }
                    continue;
                }

                try {
                    $registro = $this->insertarFila($fila, $proyectoId);

                    LogService::log(
                        tabla:        $this->tablaDeNivel($fila['nivel']),
                        proyectoId:   $proyectoId,
                        usuarioId:    $usuarioId,
                        accion:       'IMPORT',
                        entidadId:    $registro->id,
                        datosDespues: $registro->toArray(),
                        ip:           $ip
                    );

                    $importadas++;
                } catch (\Exception $e) {
                    $errores++;
                }
            }
        });

        return compact('importadas', 'omitidas', 'errores');
    }

    private function insertarFila(array $fila, int $proyectoId): object
    {
        return match ($fila['nivel']) {
            'area' => Area::create([
                'proyecto_id' => $proyectoId,
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
            'subarea' => Subarea::create([
                'proyecto_id' => $proyectoId,
                'area_id'     => Area::where('proyecto_id', $proyectoId)
                                     ->where('codigo', $fila['codigo_padre_de_quiebre'])->value('id'),
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
            'sistema' => Sistema::create([
                'proyecto_id' => $proyectoId,
                'subarea_id'  => Subarea::where('proyecto_id', $proyectoId)
                                        ->where('codigo', $fila['codigo_padre_de_quiebre'])->value('id'),
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
            'subsistema' => Subsistema::create([
                'proyecto_id' => $proyectoId,
                'sistema_id'  => Sistema::where('proyecto_id', $proyectoId)
                                        ->where('codigo', $fila['codigo_padre_de_quiebre'])->value('id'),
                'codigo'      => $fila['codigo'],
                'nombre'      => $fila['nombre'],
            ]),
        };
    }

    private function padreExiste(string $codigoPadre, string $nivelHijo, int $proyectoId): bool
    {
        $tabla = match ($nivelHijo) {
            'subarea'     => 'areas',
            'sistema'     => 'subareas',
            'subsistema'  => 'sistemas',
            default       => null,
        };

        if (!$tabla) return false;

        return DB::table($tabla)
            ->where('proyecto_id', $proyectoId)
            ->where('codigo', $codigoPadre)
            ->exists();
    }

    private function codigoExisteEnDB(string $codigo, string $nivel, int $proyectoId): bool
    {
        return DB::table($this->tablaDeNivel($nivel))
            ->where('proyecto_id', $proyectoId)
            ->where('codigo', $codigo)
            ->exists();
    }

    private function tablaDeNivel(string $nivel): string
    {
        return match ($nivel) {
            'area'        => 'areas',
            'subarea'     => 'subareas',
            'sistema'     => 'sistemas',
            'subsistema'  => 'subsistemas',
            default       => throw new \InvalidArgumentException("Nivel inválido: {$nivel}"),
        };
    }

    private function filaError(array $fila, int $filaNum, string $motivo): array
    {
        return array_merge($fila, [
            'fila'   => $filaNum,
            'status' => 'error',
            'motivo' => $motivo,
        ]);
    }
}
