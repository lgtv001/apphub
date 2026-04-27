<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\TipoUsuario;
use Illuminate\Http\Request;

class TipoUsuarioController extends Controller
{
    public function index()
    {
        return response()->json(['data' => TipoUsuario::orderBy('nombre')->get()]);
    }

    public function store(Request $request)
    {
        $data = $request->validate([
            'nombre'      => 'required|string|max:100|unique:tipos_usuario,nombre',
            'descripcion' => 'nullable|string|max:255',
        ]);

        $tipo = TipoUsuario::create($data);

        return response()->json($tipo, 201);
    }

    public function update(Request $request, int $id)
    {
        $tipo = TipoUsuario::findOrFail($id);

        $data = $request->validate([
            'nombre'      => "string|max:100|unique:tipos_usuario,nombre,{$id}",
            'descripcion' => 'nullable|string|max:255',
            'activo'      => 'boolean',
        ]);

        $tipo->update($data);

        return response()->json($tipo->fresh());
    }

    public function destroy(int $id)
    {
        TipoUsuario::findOrFail($id)->delete();

        return response()->noContent();
    }
}
