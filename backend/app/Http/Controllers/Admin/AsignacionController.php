<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsuarioProyecto;
use App\Services\LogService;
use Illuminate\Http\Request;

class AsignacionController extends Controller
{
    public function index()
    {
        $asignaciones = UsuarioProyecto::with(['usuario:id,nombre,email', 'proyecto:id,codigo,nombre'])
            ->orderBy('proyecto_id')
            ->get();

        return response()->json(['data' => $asignaciones]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'  => 'required|exists:usuarios,id',
            'proyecto_id' => 'required|exists:proyectos,id',
            'rol'         => 'required|in:admin,usuario',
            'tipo_id'     => 'nullable|exists:tipos_usuario,id',
        ]);

        $existe = UsuarioProyecto::where('usuario_id', $data['usuario_id'])
            ->where('proyecto_id', $data['proyecto_id'])
            ->exists();

        if ($existe) {
            return response()->json([
                'message' => 'El usuario ya tiene asignación en este proyecto',
                'errors'  => ['usuario_id' => ['Ya existe una asignación para este usuario y proyecto']],
            ], 422);
        }

        $asignacion = UsuarioProyecto::create($data);

        LogService::log(
            tabla:        'usuarios_proyectos',
            proyectoId:   $data['proyecto_id'],
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $asignacion->id,
            datosDespues: $asignacion->toArray(),
            ip:           $request->ip()
        );

        return response()->json($asignacion, 201);
    }

    public function destroy(Request $request, int $id)
    {
        $asignacion = UsuarioProyecto::findOrFail($id);

        LogService::log(
            tabla:      'usuarios_proyectos',
            proyectoId: $asignacion->proyecto_id,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $asignacion->id,
            datosAntes: $asignacion->toArray(),
            ip:         $request->ip()
        );

        $asignacion->delete();

        return response()->noContent();
    }
}
