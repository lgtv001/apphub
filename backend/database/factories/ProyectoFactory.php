<?php

namespace Database\Factories;

use App\Models\Proyecto;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProyectoFactory extends Factory
{
    protected $model = Proyecto::class;

    public function definition(): array
    {
        return [
            'codigo' => strtoupper(fake()->unique()->lexify('???-###')),
            'nombre' => fake()->company() . ' ' . fake()->randomElement(['Tramo 1', 'Etapa 1', 'Fase A']),
            'estado' => 'activo',
        ];
    }

    public function archivado(): static
    {
        return $this->state(['estado' => 'archivado']);
    }
}
