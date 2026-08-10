<?php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\SolicitudAcceso;
use App\Models\AplicacionSeccion;
use App\Models\Usuario;
use App\Models\UsuarioAplicacion;
use App\Models\UsuarioAplicacionSeccion;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;

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
        $validator = Validator::make($request->all(), [
            'nombre'     => 'required|string|max:255',
            'email'      => 'required|email|unique:usuarios,email',
            'password'   => 'required|string|min:8',
            'rol_global' => 'required|in:superuser,admin,usuario',
            'aplicaciones'              => 'array',
            'aplicaciones.*.aplicacion_id' => 'required|exists:aplicaciones_externas,id',
            'aplicaciones.*.secciones'      => 'array',
            'aplicaciones.*.secciones.*.seccion_id' => 'required|exists:aplicaciones_secciones,id',
            'aplicaciones.*.secciones.*.nivel'      => 'required|in:ver,editar',
        ]);

        $validator->after(function ($validator) use ($request) {
            foreach ($request->input('aplicaciones', []) as $i => $app) {
                $aplicacionId = $app['aplicacion_id'] ?? null;
                if (!$aplicacionId) {
                    continue;
                }

                foreach ($app['secciones'] ?? [] as $j => $seccion) {
                    $seccionId = $seccion['seccion_id'] ?? null;
                    if (!$seccionId) {
                        continue;
                    }

                    $perteneceALaApp = AplicacionSeccion::where('id', $seccionId)
                        ->where('aplicacion_id', $aplicacionId)
                        ->exists();

                    if (!$perteneceALaApp) {
                        $validator->errors()->add(
                            "aplicaciones.{$i}.secciones.{$j}.seccion_id",
                            'La sección seleccionada no pertenece a la aplicación indicada.'
                        );
                    }
                }
            }
        });

        $data = $validator->validate();

        $solicitud = SolicitudAcceso::findOrFail($id);

        if ($solicitud->estado !== 'pendiente') {
            return response()->json(['message' => 'La solicitud ya fue procesada.'], 422);
        }

        [$usuario, $grants] = DB::transaction(function () use ($data) {
            $usuario = Usuario::create([
                'nombre'        => $data['nombre'],
                'email'         => $data['email'],
                'password_hash' => Hash::make($data['password']),
                'rol_global'    => $data['rol_global'],
                'activo'        => true,
            ]);

            $grants = [];

            foreach ($data['aplicaciones'] ?? [] as $app) {
                $grant = UsuarioAplicacion::updateOrCreate(
                    ['usuario_id' => $usuario->id, 'aplicacion_id' => $app['aplicacion_id']],
                    []
                );

                foreach ($app['secciones'] ?? [] as $seccion) {
                    UsuarioAplicacionSeccion::updateOrCreate(
                        ['usuario_id' => $usuario->id, 'seccion_id' => $seccion['seccion_id']],
                        ['aplicacion_id' => $app['aplicacion_id'], 'nivel' => $seccion['nivel']]
                    );
                }

                $grants[] = ['grant' => $grant, 'payload' => $app];
            }

            return [$usuario, $grants];
        });

        foreach ($grants as $entry) {
            LogService::log(
                tabla:        'usuarios_aplicaciones',
                proyectoId:   null,
                usuarioId:    $request->user()->id,
                accion:       'CREATE',
                entidadId:    $entry['grant']->id,
                datosDespues: $entry['payload'],
                ip:           $request->ip()
            );
        }

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
