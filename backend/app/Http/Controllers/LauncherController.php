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
        $codigosConAcceso = $usuario->codigosDeAplicacionesConAcceso();

        $data = AplicacionExterna::orderBy('nombre')->get()
            ->filter(fn ($app) => $app->activo ? in_array($app->codigo, $codigosConAcceso, true) : true)
            ->map(fn ($app) => [
                'codigo' => $app->codigo,
                'nombre' => $app->nombre,
                'url_base' => $app->activo ? $app->url_base : null,
                'proximamente' => !$app->activo,
                'secciones' => $app->activo ? $usuario->seccionesDeAplicacionPorTipo($app->codigo) : null,
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
        if (!$usuario->activo) {
            abort(403, 'Usuario inactivo');
        }
        if (!$usuario->tieneAccesoA($app->codigo)) {
            return response()->json(['message' => 'Sin acceso a esta aplicación'], 403);
        }

        // Pedido 2026-08-14, corregido el mismo día: propagar el tema claro/oscuro/automático
        // elegido en apphub a la app de destino. "auto" es un valor explícito, nunca se omite --
        // el estado de apphub siempre gana al entrar. Se valida contra una lista blanca en vez de
        // confiar en el string tal cual del cliente.
        $tema = $request->input('tema');
        $tema = in_array($tema, ['light', 'dark', 'auto'], true) ? $tema : 'auto';

        $handoff = $this->firmador->firmar([
            'sub'       => $usuario->email,
            'nombre'    => $usuario->nombre,
            'app'       => $app->codigo,
            'secciones' => $usuario->seccionesDeAplicacionPorTipo($app->codigo),
            'tema'      => $tema,
            'nonce'     => Str::random(32),
            'exp'       => now()->addSeconds(60)->timestamp,
        ]);

        return response()->json(['url' => "{$app->url_base}/sso/entrar?handoff=" . urlencode($handoff)]);
    }
}
