<?php
namespace App\Http\Controllers;
use App\Models\Proyecto;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class ProyectoController extends Controller
{
    public function index(Request $request)
    {
        $usuario = $request->user();
        $proyectos = $usuario->rol_global === 'superuser'
            ? Proyecto::orderBy('codigo')->get()
            : $usuario->proyectos()->orderBy('codigo')->get();
        return response()->json(['data' => $proyectos]);
    }

    public function show(Request $request, int $id)
    {
        $usuario  = $request->user();
        $proyecto = Proyecto::findOrFail($id);
        if ($usuario->rol_global !== 'superuser') {
            if (!$usuario->proyectos()->where('proyectos.id', $id)->exists()) {
                return response()->json(['message' => 'Sin acceso a este proyecto'], 403);
            }
        }
        // Add user_rol to response for frontend role detection
        $data = $proyecto->toArray();
        if ($usuario->rol_global === 'superuser') {
            $data['user_rol'] = 'admin';
        } else {
            $asignacion = $usuario->asignaciones()->where('proyecto_id', $id)->first();
            $data['user_rol'] = $asignacion?->rol ?? 'usuario';
        }
        return response()->json($data);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'codigo' => 'required|string|max:20|unique:proyectos,codigo',
            'nombre' => 'required|string|max:255',
            'estado' => 'in:activo,archivado',
        ]);
        $proyecto = DB::transaction(function () use ($data, $request) {
            $p = Proyecto::create($data);
            LogService::log('proyectos', $p->id, $request->user()->id, 'CREATE', $p->id, null, $p->toArray(), null, $request->ip());
            return $p;
        });
        return response()->json($proyecto, 201);
    }

    public function update(Request $request, int $id)
    {
        $proyecto = Proyecto::findOrFail($id);
        $data = $request->validate([
            'codigo' => "string|max:20|unique:proyectos,codigo,{$id}",
            'nombre' => 'string|max:255',
            'estado' => 'in:activo,archivado',
        ]);
        $antes = $proyecto->toArray();
        $proyecto = DB::transaction(function () use ($proyecto, $data, $antes, $request, $id) {
            $proyecto->update($data);
            $fresh = $proyecto->fresh();
            LogService::log('proyectos', $proyecto->id, $request->user()->id, 'UPDATE', $proyecto->id, $antes, $fresh->toArray(), null, $request->ip());
            return $fresh;
        });
        return response()->json($proyecto);
    }
}
