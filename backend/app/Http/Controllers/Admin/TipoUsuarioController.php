<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoUsuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class TipoUsuarioController extends Controller
{
    public function index()
    {
        $tipos = TipoUsuario::with('secciones.aplicacion')->orderBy('nombre')->get();

        $tipos->each(function ($tipo) {
            $tipo->secciones_resumen = $tipo->secciones->map(fn ($s) => [
                'seccion_id'         => $s->id,
                'codigo'             => $s->codigo,
                'nombre'             => $s->nombre,
                'aplicacion'         => $s->aplicacion->nombre,
                'aplicacion_codigo'  => $s->aplicacion->codigo,
                'nivel'              => $s->pivot->nivel,
            ])->values();
        });

        return response()->json(['data' => $tipos]);
    }

    public function store(Request $request)
    {
        $data = $this->validarPayload($request);

        $tipo = DB::transaction(function () use ($data, $request) {
            $tipo = TipoUsuario::create([
                'nombre'      => $data['nombre'],
                'descripcion' => $data['descripcion'] ?? null,
                'activo'      => $data['activo'] ?? true,
            ]);

            if ($request->has('secciones')) {
                $tipo->secciones()->sync($this->pivotDeSecciones($data['secciones']));
            }

            return $tipo;
        });

        LogService::log(
            tabla:        'usuarios_aplicaciones',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $tipo->id,
            datosDespues: ['tipo_id' => $tipo->id, 'nombre' => $tipo->nombre, 'secciones' => $data['secciones'] ?? []],
            ip:           $request->ip()
        );

        return response()->json($tipo->fresh('secciones.aplicacion'), 201);
    }

    public function update(Request $request, int $id)
    {
        $tipo = TipoUsuario::findOrFail($id);
        $data = $this->validarPayload($request, $id);

        // Hallazgo architect #3 (el más grave): sync() SOLO si la clave "secciones" vino en el
        // payload. El modal de editar tipo a veces manda solo {activo} -- sincronizar con []
        // en ese caso borraría en silencio todo el acceso que ese tipo otorgaba.
        $antes = $tipo->secciones->map(fn ($s) => ['seccion_id' => $s->id, 'nivel' => $s->pivot->nivel])->values()->all();
        $tocaSecciones = $request->has('secciones');

        DB::transaction(function () use ($tipo, $data, $tocaSecciones) {
            $tipo->update(array_intersect_key($data, array_flip(['nombre', 'descripcion', 'activo'])));

            if ($tocaSecciones) {
                $tipo->secciones()->sync($this->pivotDeSecciones($data['secciones']));
            }
        });

        if ($tocaSecciones) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'UPDATE',
                entidadId:    $tipo->id,
                datosAntes:   ['secciones' => $antes],
                datosDespues: ['secciones' => $data['secciones']],
                ip:           $request->ip()
            );
        }

        return response()->json($tipo->fresh('secciones.aplicacion'));
    }

    public function destroy(Request $request, int $id)
    {
        $tipo = TipoUsuario::findOrFail($id);

        // Un tipo con usuarios asignados no se borra en cascada silenciosa -- sería una
        // revocación masiva sin confirmación explícita (ver spec, Modelo de datos).
        if ($tipo->usuarios()->exists()) {
            return response()->json([
                'message' => 'No se puede eliminar: hay usuarios con este tipo asignado. Quítaselo primero.',
            ], 409);
        }

        $secciones = $tipo->secciones->map(fn ($s) => ['seccion_id' => $s->id, 'nivel' => $s->pivot->nivel])->values()->all();
        $nombre = $tipo->nombre;

        $tipo->delete();

        LogService::log(
            tabla:      'usuarios_aplicaciones',
            proyectoId: null,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $id,
            datosAntes: ['tipo_id' => $id, 'nombre' => $nombre, 'secciones' => $secciones],
            ip:         $request->ip()
        );

        return response()->noContent();
    }

    private function validarPayload(Request $request, ?int $ignorarId = null): array
    {
        return $request->validate([
            'nombre'                 => [$ignorarId ? 'string' : 'required', 'string', 'max:100', Rule::unique('tipos_usuario', 'nombre')->ignore($ignorarId)],
            'descripcion'             => 'nullable|string|max:255',
            'activo'                  => 'boolean',
            'secciones'               => 'array',
            'secciones.*.seccion_id'  => 'required_with:secciones|integer|distinct|exists:aplicaciones_secciones,id',
            'secciones.*.nivel'       => 'required_with:secciones|in:ver,editar',
        ]);
    }

    private function pivotDeSecciones(array $secciones): array
    {
        return collect($secciones)->mapWithKeys(fn ($s) => [$s['seccion_id'] => ['nivel' => $s['nivel']]])->all();
    }
}
