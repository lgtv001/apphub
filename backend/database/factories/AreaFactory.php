<?php

namespace Database\Factories;

use App\Models\Area;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class AreaFactory extends Factory
{
    protected $model = Area::class;

    public function definition(): array
    {
        return [
            'proyecto_id' => Proyecto::factory(),
            'codigo'      => fake()->unique()->numerify('##00'),
            'nombre'      => fake()->words(2, true),
            'orden'       => 0,
        ];
    }
}
