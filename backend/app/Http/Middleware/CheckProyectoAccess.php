<?php

namespace App\Http\Middleware;

use App\Models\UsuarioProyecto;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class CheckProyectoAccess
{
    public function handle(Request $request, Closure $next): Response
    {
        $usuario = $request->user();

        if (!$usuario) {
            return response()->json(['message' => 'No autenticado'], 401);
        }

        // SUPERUSER tiene acceso a todos los proyectos
        if ($usuario->rol_global === 'superuser') {
            return $next($request);
        }

        $proyectoId = $request->route('proyecto_id') ?? $request->route('id');

        if (!$proyectoId) {
            return $next($request);
        }

        $tieneAcceso = UsuarioProyecto::where('usuario_id', $usuario->id)
            ->where('proyecto_id', $proyectoId)
            ->exists();

        if (!$tieneAcceso) {
            return response()->json(['message' => 'Sin acceso a este proyecto'], 403);
        }

        return $next($request);
    }
}
