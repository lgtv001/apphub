<?php
namespace App\Http\Controllers;

use App\Models\Subarea;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubareaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $query = Subarea::where('proyecto_id', $proyecto_id);
        if ($request->has('area_id')) {
            $query->where('area_id', $request->integer('area_id'));
        }
        $subareas = $query->orderBy('orden')->orderBy('codigo')->get();
        return response()->json(['data' => $subareas]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'area_id' => ['required', 'integer', Rule::exists('areas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'  => ['required', 'string', 'max:50', Rule::unique('subareas')->where('proyecto_id', $proyecto_id)],
            'nombre'  => 'required|string|max:255',
            'orden'   => 'integer|min:0',
        ]);
        $subarea = DB::transaction(function () use ($data, $proyecto_id, $request) {
            $s = Subarea::create(array_merge($data, ['proyecto_id' => $proyecto_id]));
            LogService::log('subareas', $proyecto_id, $request->user()->id, 'CREATE', $s->id, null, $s->toArray(), null, $request->ip());
            return $s;
        });
        return response()->json($subarea, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $subarea = Subarea::where('proyecto_id', $proyecto_id)->findOrFail($id);
        $data = $request->validate([
            'area_id' => ['integer', Rule::exists('areas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'  => ['string', 'max:50', Rule::unique('subareas')->where('proyecto_id', $proyecto_id)->ignore($id)],
            'nombre'  => 'string|max:255',
            'orden'   => 'integer|min:0',
        ]);
        $antes = $subarea->toArray();
        $subarea = DB::transaction(function () use ($subarea, $data, $antes, $proyecto_id, $request) {
            $subarea->update($data);
            $fresh = $subarea->fresh();
            LogService::log('subareas', $proyecto_id, $request->user()->id, 'UPDATE', $subarea->id, $antes, $fresh->toArray(), null, $request->ip());
            return $fresh;
        });
        return response()->json($subarea);
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $subarea = Subarea::where('proyecto_id', $proyecto_id)->findOrFail($id);
        DB::transaction(function () use ($subarea, $proyecto_id, $request) {
            LogService::log('subareas', $proyecto_id, $request->user()->id, 'DELETE', $subarea->id, $subarea->toArray(), null, null, $request->ip());
            $subarea->delete();
        });
        return response()->noContent();
    }
}
