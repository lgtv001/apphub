<?php

namespace App\Http\Middleware;

use App\Models\UsuarioProyecto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckRole
{
    public function handle(Request $request, Closure $next, string $role): Response
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // SUPERUSER siempre pasa
        if ($usuario->rol_global === 'superuser') {
            return $next($request);
        }

        if ($role === 'superuser') {
            return response()->json(['message' => 'Se requiere rol SUPERUSER'], 403);
        }

        if ($role === 'admin') {
            $proyectoId = $request->route('proyecto_id') ?? $request->route('id');

            $asignacion = UsuarioProyecto::where('usuario_id', $usuario->id)
                ->where('proyecto_id', $proyectoId)
                ->first();

            if (!$asignacion || $asignacion->rol !== 'admin') {
                return response()->json(['message' => 'Se requiere rol Admin en este proyecto'], 403);
            }
        }

        return $next($request);
    }
}
