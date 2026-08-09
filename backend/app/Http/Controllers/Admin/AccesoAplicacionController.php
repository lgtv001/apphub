<?php
// apphub/backend/app/Http/Controllers/Admin/AccesoAplicacionController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\UsuarioAplicacion;
use App\Models\UsuarioAplicacionSeccion;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AccesoAplicacionController extends Controller
{
    public function index()
    {
        $accesos = UsuarioAplicacion::with(['usuario:id,nombre,email', 'aplicacion:id,codigo,nombre'])
            ->orderBy('aplicacion_id')
            ->get();

        return response()->json(['data' => $accesos]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'usuario_id'              => 'required|exists:usuarios,id',
            'aplicacion_id'           => 'required|exists:aplicaciones_externas,id',
            'secciones'               => 'array',
            'secciones.*.seccion_id'  => [
                'required',
                Rule::exists('aplicaciones_secciones', 'id')->where(function ($query) use ($request) {
                    $query->where('aplicacion_id', $request->input('aplicacion_id'));
                }),
            ],
            'secciones.*.nivel'       => 'required|in:ver,editar',
        ]);

        $grant = DB::transaction(function () use ($data) {
            $grant = UsuarioAplicacion::updateOrCreate(
                ['usuario_id' => $data['usuario_id'], 'aplicacion_id' => $data['aplicacion_id']],
                []
            );

            foreach ($data['secciones'] ?? [] as $seccion) {
                UsuarioAplicacionSeccion::updateOrCreate(
                    ['usuario_id' => $data['usuario_id'], 'seccion_id' => $seccion['seccion_id']],
                    ['aplicacion_id' => $data['aplicacion_id'], 'nivel' => $seccion['nivel']]
                );
            }

            return $grant;
        });

        LogService::log(
            tabla:        'usuarios_aplicaciones',
            proyectoId:   null,
            usuarioId:    $request->user()->id,
            accion:       'CREATE',
            entidadId:    $grant->id,
            datosDespues: $data,
            ip:           $request->ip()
        );

        return response()->json(['message' => 'Acceso otorgado'], 201);
    }

    public function destroy(Request $request, int $usuarioId, int $aplicacionId)
    {
        $grant = UsuarioAplicacion::where('usuario_id', $usuarioId)->where('aplicacion_id', $aplicacionId)->first();
        $secciones = UsuarioAplicacionSeccion::where('usuario_id', $usuarioId)->where('aplicacion_id', $aplicacionId)->get();

        $datosAntes = [
            'usuario_id'    => $usuarioId,
            'aplicacion_id' => $aplicacionId,
            'secciones'     => $secciones->map(fn ($s) => ['seccion_id' => $s->seccion_id, 'nivel' => $s->nivel])->values()->all(),
        ];
        $entidadId = $grant?->id;

        DB::transaction(function () use ($usuarioId, $aplicacionId) {
            UsuarioAplicacionSeccion::where('usuario_id', $usuarioId)->where('aplicacion_id', $aplicacionId)->delete();
            UsuarioAplicacion::where('usuario_id', $usuarioId)->where('aplicacion_id', $aplicacionId)->delete();
        });

        LogService::log(
            tabla:      'usuarios_aplicaciones',
            proyectoId: null,
            usuarioId:  $request->user()->id,
            accion:     'DELETE',
            entidadId:  $entidadId,
            datosAntes: $datosAntes,
            ip:         $request->ip()
        );

        return response()->noContent();
    }
}
