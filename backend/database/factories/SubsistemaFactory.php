<?php

namespace Database\Factories;

use App\Models\Subsistema;
use App\Models\Sistema;
use Illuminate\Database\Eloquent\Factories\Factory;

class SubsistemaFactory extends Factory
{
    protected $model = Subsistema::class;

    public function definition(): array
    {
        $sistema = Sistema::factory()->create();
        return [
            'proyecto_id' => $sistema->proyecto_id,
            'sistema_id'  => $sistema->id,
            'codigo'      => fake()->unique()->lexify('##??-#'),
            'nombre'      => fake()->words(2, true),
            'orden'       => 0,
        ];
    }
}
