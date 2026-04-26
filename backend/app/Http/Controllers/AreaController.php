<?php
namespace App\Http\Controllers;
use App\Models\Area;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class AreaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $areas = Area::where('proyecto_id', $proyecto_id)->orderBy('orden')->orderBy('codigo')->get();
        return response()->json(['data' => $areas]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'codigo' => ['required', 'string', 'max:50', Rule::unique('areas')->where('proyecto_id', $proyecto_id)],
            'nombre' => 'required|string|max:255',
            'orden'  => 'integer|min:0',
        ]);
        $area = DB::transaction(function () use ($data, $proyecto_id, $request) {
            $a = Area::create(array_merge($data, ['proyecto_id' => $proyecto_id]));
            LogService::log('areas', $proyecto_id, $request->user()->id, 'CREATE', $a->id, null, $a->toArray(), null, $request->ip());
            return $a;
        });
        return response()->json($area, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $area = Area::where('proyecto_id', $proyecto_id)->findOrFail($id);
        $data = $request->validate([
            'codigo' => ['string', 'max:50', Rule::unique('areas')->where('proyecto_id', $proyecto_id)->ignore($id)],
            'nombre' => 'string|max:255',
            'orden'  => 'integer|min:0',
        ]);
        $antes = $area->toArray();
        $area = DB::transaction(function () use ($area, $data, $antes, $proyecto_id, $request) {
            $area->update($data);
            $fresh = $area->fresh();
            LogService::log('areas', $proyecto_id, $request->user()->id, 'UPDATE', $area->id, $antes, $fresh->toArray(), null, $request->ip());
            return $fresh;
        });
        return response()->json($area);
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $area = Area::where('proyecto_id', $proyecto_id)->findOrFail($id);
        DB::transaction(function () use ($area, $proyecto_id, $request) {
            LogService::log('areas', $proyecto_id, $request->user()->id, 'DELETE', $area->id, $area->toArray(), null, null, $request->ip());
            $area->delete();
        });
        return response()->noContent();
    }
}
