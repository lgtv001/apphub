<?php
// apphub/backend/app/Http/Controllers/LauncherController.php
namespace App\Http\Controllers;

use App\Models\AplicacionExterna;
use Illuminate\Http\Request;

class LauncherController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();
        $codigosConGrant = $usuario->aplicaciones()->pluck('codigo')->all();

        $data = AplicacionExterna::orderBy('nombre')->get()
            ->filter(fn ($app) => $app->activo ? in_array($app->codigo, $codigosConGrant, true) : true)
            ->map(fn ($app) => [
                'codigo' => $app->codigo,
                'nombre' => $app->nombre,
                'url_base' => $app->activo ? $app->url_base : null,
                'proximamente' => !$app->activo,
                'secciones' => $app->activo ? $usuario->seccionesDeAplicacion($app->codigo) : null,
            ])
            ->values();

        return response()->json(['data' => $data]);
    }
}
