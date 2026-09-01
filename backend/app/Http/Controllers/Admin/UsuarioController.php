<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Usuario;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UsuarioController extends Controller
{
    public function index()
    {
        return response()->json([
            'data' => Usuario::with('tiposUsuario:id,nombre')
                ->orderBy('nombre')
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
            'tipos'      => 'array',
            'tipos.*'    => 'integer|distinct|exists:tipos_usuario,id',
        ]);

        $usuario = DB::transaction(function () use ($data, $request) {
            $usuario = Usuario::create([
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'rol_global'    => $data['rol_global'] ?? 'usuario',
            ]);

            if ($request->has('tipos')) {
                $usuario->tiposUsuario()->sync($data['tipos']);
            }

            return $usuario;
        });

        LogService::log(
            tabla:        'usuarios',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $usuario->id,
            datosDespues: $usuario->only(['id','nombre','email','rol_global']),
            ip:           $request->ip()
        );

        if ($request->has('tipos')) {
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
            'tipos'      => 'array',
            'tipos.*'    => 'integer|distinct|exists:tipos_usuario,id',
        ]);

        $antes = $usuario->only(['id','nombre','email','rol_global','activo']);
        $tiposAntes = $usuario->tiposUsuario()->pluck('tipos_usuario.id')->all();

        if (isset($data['password'])) {
            $data['password_hash'] = Hash::make($data['password']);
            unset($data['password']);
        }

        // Mismo cuidado que TipoUsuarioController::update() (hallazgo architect #3): sync() SOLO
        // si la clave "tipos" vino en el payload -- si no, un PUT que solo cambia `activo`
        // borraría en silencio todos los tipos del usuario.
        $tocaTipos = $request->has('tipos');
        $tiposNuevos = $data['tipos'] ?? [];
        unset($data['tipos']);

        DB::transaction(function () use ($usuario, $data, $tocaTipos, $tiposNuevos) {
            $usuario->update($data);
            if ($tocaTipos) {
                $usuario->tiposUsuario()->sync($tiposNuevos);
            }
        });

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

        if ($tocaTipos) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'UPDATE',
                entidadId:    $usuario->id,
                datosAntes:   ['tipos' => $tiposAntes],
                datosDespues: ['tipos' => $tiposNuevos],
                ip:           $request->ip()
            );
        }

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
