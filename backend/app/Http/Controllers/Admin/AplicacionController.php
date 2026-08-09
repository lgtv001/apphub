<?php
// apphub/backend/app/Http/Controllers/Admin/AplicacionController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AplicacionExterna;
use App\Services\LogService;
use Illuminate\Http\Request;

class AplicacionController extends Controller
{
    public function index()
    {
        return response()->json(['data' => AplicacionExterna::with('secciones')->orderBy('codigo')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'codigo'   => 'required|string|max:30|unique:aplicaciones_externas,codigo',
            'nombre'   => 'required|string|max:255',
            'url_base' => 'nullable|string|max:255',
            'activo'   => 'boolean',
        ]);

        $app = AplicacionExterna::create($data);

        LogService::log(
            tabla: 'aplicaciones_externas',
            proyectoId: null,
            usuarioId: $request->user()->id,
            accion: 'CREATE',
            entidadId: $app->id,
            datosDespues: $app->toArray(),
            ip: $request->ip()
        );

        return response()->json($app, 201);
    }

    public function update(Request $request, int $id)
    {
        $app = AplicacionExterna::findOrFail($id);

        $data = $request->validate([
            'nombre'   => 'sometimes|string|max:255',
            'url_base' => 'sometimes|nullable|string|max:255',
            'activo'   => 'sometimes|boolean',
        ]);

        $antes = $app->toArray();

        $app->update($data);

        LogService::log(
            tabla: 'aplicaciones_externas',
            proyectoId: null,
            usuarioId: $request->user()->id,
            accion: 'UPDATE',
            entidadId: $app->id,
            datosAntes: $antes,
            datosDespues: $app->fresh()->toArray(),
            ip: $request->ip()
        );

        return response()->json($app);
    }
}
