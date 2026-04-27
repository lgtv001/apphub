<?php
namespace App\Http\Controllers;

use App\Models\Sistema;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SistemaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $query = Sistema::where('proyecto_id', $proyecto_id);
        if ($request->has('subarea_id')) {
            $query->where('subarea_id', $request->integer('subarea_id'));
        }
        $sistemas = $query->orderBy('orden')->orderBy('codigo')->get();
        return response()->json(['data' => $sistemas]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'subarea_id' => ['required', 'integer', Rule::exists('subareas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'     => ['required', 'string', 'max:50', Rule::unique('sistemas')->where('proyecto_id', $proyecto_id)],
            'nombre'     => 'required|string|max:255',
            'orden'      => 'integer|min:0',
        ]);
        $sistema = DB::transaction(function () use ($data, $proyecto_id, $request) {
            $s = Sistema::create(array_merge($data, ['proyecto_id' => $proyecto_id]));
            LogService::log('sistemas', $proyecto_id, $request->user()->id, 'CREATE', $s->id, null, $s->toArray(), null, $request->ip());
            return $s;
        });
        return response()->json($sistema, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $sistema = Sistema::where('proyecto_id', $proyecto_id)->findOrFail($id);
        $data = $request->validate([
            'subarea_id' => ['integer', Rule::exists('subareas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'     => ['string', 'max:50', Rule::unique('sistemas')->where('proyecto_id', $proyecto_id)->ignore($id)],
            'nombre'     => 'string|max:255',
            'orden'      => 'integer|min:0',
        ]);
        $antes = $sistema->toArray();
        $sistema = DB::transaction(function () use ($sistema, $data, $antes, $proyecto_id, $request) {
            $sistema->update($data);
            $fresh = $sistema->fresh();
            LogService::log('sistemas', $proyecto_id, $request->user()->id, 'UPDATE', $sistema->id, $antes, $fresh->toArray(), null, $request->ip());
            return $fresh;
        });
        return response()->json($sistema);
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $sistema = Sistema::where('proyecto_id', $proyecto_id)->findOrFail($id);
        DB::transaction(function () use ($sistema, $proyecto_id, $request) {
            LogService::log('sistemas', $proyecto_id, $request->user()->id, 'DELETE', $sistema->id, $sistema->toArray(), null, null, $request->ip());
            $sistema->delete();
        });
        return response()->noContent();
    }
}
