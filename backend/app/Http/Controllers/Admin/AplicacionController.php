<?php
// apphub/backend/app/Http/Controllers/Admin/AplicacionController.php
namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AplicacionExterna;
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

        $app->update($data);

        return response()->json($app);
    }
}
