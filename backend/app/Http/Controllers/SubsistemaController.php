<?php
namespace App\Http\Controllers;

use App\Models\Subsistema;
use App\Services\LogService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\Rule;

class SubsistemaController extends Controller
{
    public function index(Request $request, int $proyecto_id)
    {
        $query = Subsistema::where('proyecto_id', $proyecto_id);
        if ($request->has('sistema_id')) {
            $query->where('sistema_id', $request->integer('sistema_id'));
        }
        $subsistemas = $query->orderBy('orden')->orderBy('codigo')->get();
        return response()->json(['data' => $subsistemas]);
    }

    public function store(Request $request, int $proyecto_id)
    {
        $data = $request->validate([
            'sistema_id' => ['required', 'integer', Rule::exists('sistemas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'     => ['required', 'string', 'max:50', Rule::unique('subsistemas')->where('proyecto_id', $proyecto_id)],
            'nombre'     => 'required|string|max:255',
            'orden'      => 'integer|min:0',
        ]);
        $subsistema = DB::transaction(function () use ($data, $proyecto_id, $request) {
            $s = Subsistema::create(array_merge($data, ['proyecto_id' => $proyecto_id]));
            LogService::log('subsistemas', $proyecto_id, $request->user()->id, 'CREATE', $s->id, null, $s->toArray(), null, $request->ip());
            return $s;
        });
        return response()->json($subsistema, 201);
    }

    public function update(Request $request, int $proyecto_id, int $id)
    {
        $subsistema = Subsistema::where('proyecto_id', $proyecto_id)->findOrFail($id);
        $data = $request->validate([
            'sistema_id' => ['integer', Rule::exists('sistemas', 'id')->where('proyecto_id', $proyecto_id)],
            'codigo'     => ['string', 'max:50', Rule::unique('subsistemas')->where('proyecto_id', $proyecto_id)->ignore($id)],
            'nombre'     => 'string|max:255',
            'orden'      => 'integer|min:0',
        ]);
        $antes = $subsistema->toArray();
        $subsistema = DB::transaction(function () use ($subsistema, $data, $antes, $proyecto_id, $request) {
            $subsistema->update($data);
            $fresh = $subsistema->fresh();
            LogService::log('subsistemas', $proyecto_id, $request->user()->id, 'UPDATE', $subsistema->id, $antes, $fresh->toArray(), null, $request->ip());
            return $fresh;
        });
        return response()->json($subsistema);
    }

    public function destroy(Request $request, int $proyecto_id, int $id)
    {
        $subsistema = Subsistema::where('proyecto_id', $proyecto_id)->findOrFail($id);
        DB::transaction(function () use ($subsistema, $proyecto_id, $request) {
            LogService::log('subsistemas', $proyecto_id, $request->user()->id, 'DELETE', $subsistema->id, $subsistema->toArray(), null, null, $request->ip());
            $subsistema->delete();
        });
        return response()->noContent();
    }
}
