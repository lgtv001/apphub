<?php
namespace App\Http\Controllers;
use App\Models\Area;
use App\Services\LogService;
use Illuminate\Http\Request;
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
        $area = Area::create(array_merge($data, ['proyecto_id' => $proyecto_id]));
        LogService::log('areas', $proyecto_id, $request->user()->id, 'CREATE', $area->id, null, $area->toArray(), null, $request->ip());
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
        $area->update($data);
        LogService::log('areas', $proyecto_id, $request->user()->id, 'UPDATE', $area->id, $antes, $area->fresh()->toArray(), null, $request->ip());
        return response()->json($area->fresh());
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $area = Area::where('proyecto_id', $proyecto_id)->findOrFail($id);
        LogService::log('areas', $proyecto_id, $request->user()->id, 'DELETE', $area->id, $area->toArray(), null, null, $request->ip());
        $area->delete();
        return response()->noContent();
    }
}
