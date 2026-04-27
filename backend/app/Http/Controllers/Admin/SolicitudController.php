<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SolicitudAcceso;
use App\Models\Usuario;
use Illuminate\Http\Request;
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
        ]);

        $solicitud = SolicitudAcceso::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'La solicitud ya fue procesada.'], 422);
        }

        $usuario = Usuario::create([
            'nombre'        => $data['nombre'],
            'email'         => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'rol_global'    => $data['rol_global'],
            'activo'        => true,
        ]);

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
