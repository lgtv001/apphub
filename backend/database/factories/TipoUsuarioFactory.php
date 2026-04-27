<?php

namespace Database\Factories;

use App\Models\TipoUsuario;
use Illuminate\Database\Eloquent\Factories\Factory;

class TipoUsuarioFactory extends Factory
{
    protected $model = TipoUsuario::class;

    public function definition(): array
    {
        return [
            'nombre'      => fake()->unique()->word(),
            'descripcion' => fake()->sentence(),
            'activo'      => true,
        ];
    }
}
