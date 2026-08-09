<?php
// apphub/backend/app/Http/Controllers/LauncherController.php
namespace App\Http\Controllers;

use App\Models\AplicacionExterna;
use App\Services\SsoHandoffService;
use Illuminate\Http\Request;
use Illuminate\Support\Str;

class LauncherController extends Controller
{
    public function __construct(private SsoHandoffService $firmador) {}

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

    public function entrar(Request $request, string $codigo)
    {
        $app = AplicacionExterna::where('codigo', $codigo)->where('activo', true)->first();
        if (!$app) {
            return response()->json(['message' => 'Aplicación no encontrada'], 404);
        }

        $usuario = $request->user();
        if (!$usuario->aplicaciones()->where('aplicaciones_externas.id', $app->id)->exists()) {
            return response()->json(['message' => 'Sin acceso a esta aplicación'], 403);
        }

        $handoff = $this->firmador->firmar([
            'sub'       => $usuario->email,
            'nombre'    => $usuario->nombre,
            'app'       => $app->codigo,
            'secciones' => $usuario->seccionesDeAplicacion($app->codigo),
            'nonce'     => Str::random(32),
            'exp'       => now()->addSeconds(60)->timestamp,
        ]);

        return response()->json(['url' => "{$app->url_base}/sso/entrar?handoff=" . urlencode($handoff)]);
    }
}
