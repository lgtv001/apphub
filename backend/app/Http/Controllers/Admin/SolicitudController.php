<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SolicitudAcceso;
use App\Models\Usuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class SolicitudController extends Controller
{
    public function index()
    {
        $rows = SolicitudAcceso::orderByRaw("FIELD(estado,'pendiente','aprobado','rechazado')")
            ->orderByDesc('created_at')
            ->get();

        return response()->json($rows);
    }

    public function approve(int $id, Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'required|in:superuser,admin,usuario',
            'tipos'      => 'array',
            'tipos.*'    => 'integer|distinct|exists:tipos_usuario,id',
        ]);

        $solicitud = SolicitudAcceso::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'La solicitud ya fue procesada.'], 422);
        }

        $usuario = DB::transaction(function () use ($data) {
            $usuario = Usuario::create([
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'rol_global'    => $data['rol_global'],
                'activo'        => true,
            ]);

            if (!empty($data['tipos'])) {
                $usuario->tiposUsuario()->sync($data['tipos']);
            }

            return $usuario;
        });

        if (!empty($data['tipos'])) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'CREATE',
                entidadId:    $usuario->id,
                datosDespues: ['tipos' => $data['tipos']],
                ip:           $request->ip()
            );
        }

        $solicitud->update(['estado' => 'aprobado']);

        return response()->json([
            'message' => 'Usuario creado correctamente.',
            'usuario' => $usuario->only(['id', 'nombre', 'email', 'rol_global']),
        ], 201);
    }

    public function reject(int $id)
    {
        $solicitud = SolicitudAcceso::findOrFail($id);
        $solicitud->update(['estado' => 'rechazado']);
        return response()->noContent();
    }
}
