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
        if (!$usuario->activo) {
            abort(403, 'Usuario inactivo');
        }
        if (!$usuario->aplicaciones()->where('aplicaciones_externas.id', $app->id)->exists()) {
            return response()->json(['message' => 'Sin acceso a esta aplicación'], 403);
        }

        // Pedido 2026-08-14, corregido el mismo día: propagar el tema claro/oscuro/automático
        // elegido en apphub a la app de destino. La primera versión solo mandaba 'light'/
        // 'dark' y usaba null para "sin override" -- pero null significaba "no digas nada", así
        // que si apphub estaba en automático, kpis-sso se quedaba con lo que tuviera guardado
        // de una sesión anterior en vez de también volver a automático (reporte real del
        // usuario). "auto" es ahora un valor explícito más, nunca se omite: el estado de
        // apphub siempre gana al entrar, sea cual sea. El backend nunca ve el localStorage del
        // cliente por su cuenta -- el frontend (launcher.html) lo manda en el body de este
        // POST. Se valida contra una lista blanca en vez de confiar en el string tal cual: es
        // el único campo de este payload que viene directo de un input del cliente sin pasar
        // antes por el modelo de permisos (secciones/nombre/email salen todos de $usuario).
        $tema = $request->input('tema');
        $tema = in_array($tema, ['light', 'dark', 'auto'], true) ? $tema : 'auto';

        $handoff = $this->firmador->firmar([
            'sub'       => $usuario->email,
            'nombre'    => $usuario->nombre,
            'app'       => $app->codigo,
            'secciones' => $usuario->seccionesDeAplicacion($app->codigo),
            'tema'      => $tema,
            'nonce'     => Str::random(32),
            'exp'       => now()->addSeconds(60)->timestamp,
        ]);

        return response()->json(['url' => "{$app->url_base}/sso/entrar?handoff=" . urlencode($handoff)]);
    }
}
