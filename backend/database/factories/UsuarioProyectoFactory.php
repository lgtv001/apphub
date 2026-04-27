<?php

namespace Database\Factories;

use App\Models\Proyecto;
use App\Models\Usuario;
use App\Models\UsuarioProyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class UsuarioProyectoFactory extends Factory
{
    protected $model = UsuarioProyecto::class;

    public function definition(): array
    {
        return [
            'usuario_id'  => Usuario::factory(),
            'proyecto_id' => Proyecto::factory(),
            'rol'         => 'usuario',
            'tipo_id'     => null,
        ];
    }
}
