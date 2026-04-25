<?php

namespace Database\Factories;

use App\Models\Sistema;
use App\Models\Subarea;
use Illuminate\Database\Eloquent\Factories\Factory;

class SistemaFactory extends Factory
{
    protected $model = Sistema::class;

    public function definition(): array
    {
        $subarea = Subarea::factory()->create();
        return [
            'proyecto_id' => $subarea->proyecto_id,
            'subarea_id'  => $subarea->id,
            'codigo'      => fake()->unique()->lexify('##??'),
            'nombre'      => fake()->words(2, true),
            'orden'       => 0,
        ];
    }
}
