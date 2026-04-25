<?php

namespace Database\Factories;

use App\Models\Subarea;
use App\Models\Area;
use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubareaFactory extends Factory
{
    protected $model = Subarea::class;

    public function definition(): array
    {
        $area = Area::factory()->create();
        return [
            'proyecto_id' => $area->proyecto_id,
            'area_id'     => $area->id,
            'codigo'      => fake()->unique()->numerify('##10'),
            'nombre'      => fake()->words(2, true),
            'orden'       => 0,
        ];
    }
}
