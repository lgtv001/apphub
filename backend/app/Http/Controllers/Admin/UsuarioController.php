<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Usuario::orderBy('nombre')
                ->get(['id','nombre','email','rol_global','activo','created_at']),
        ]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'in:admin,usuario',
        ]);

        $usuario = Usuario::create([
            'nombre'        => $data['nombre'],
            'email'         => $data['email'],
            'password_hash' => Hash::make($data['password']),
            'rol_global'    => $data['rol_global'] ?? 'usuario',
        ]);

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $usuario->id,
            datosDespues: $usuario->only(['id','nombre','email','rol_global']),
            ip:           $request->ip()
        );

        return response()->json(
            $usuario->only(['id','nombre','email','rol_global','activo']), 201
        );
    }

    public function update(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        $data = $request->validate([
            'nombre'     => 'string|max:255',
            'email'      => "email|unique:usuarios,email,{$id}",
            'password'   => 'string|min:8',
            'rol_global' => 'in:admin,usuario',
            'activo'     => 'boolean',
        ]);

        $antes = $usuario->only(['id','nombre','email','rol_global','activo']);

        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        $usuario->update($data);

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'UPDATE',
            entidadId:    $usuario->id,
            datosAntes:   $antes,
            datosDespues: $usuario->fresh()->only(['id','nombre','email','rol_global','activo']),
            ip:           $request->ip()
        );

        return response()->json(
            $usuario->fresh()->only(['id','nombre','email','rol_global','activo'])
        );
    }

    public function destroy(Request $request, int $id)
    {
        $usuario = Usuario::findOrFail($id);

        LogService::log(
            tabla:      'usuarios',
            proyectoId: null,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $usuario->id,
            datosAntes: $usuario->only(['id','nombre','email','rol_global']),
            ip:         $request->ip()
        );

        $usuario->delete();

        return response()->noContent();
    }
}
