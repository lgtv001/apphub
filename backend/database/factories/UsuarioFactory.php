<?php

namespace Database\Factories;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

class UsuarioFactory extends Factory
{
    protected $model = Usuario::class;

    public function definition(): array
    {
        return [
            'nombre'       => fake()->name(),
            'email'        => fake()->unique()->safeEmail(),
            'password_hash'=> bcrypt('password'),
            'rol_global'   => 'usuario',
            'activo'       => true,
        ];
    }

    public function superuser(): static
    {
        return $this->state(['rol_global' => 'superuser']);
    }

    public function admin(): static
    {
        return $this->state(['rol_global' => 'admin']);
    }

    public function inactivo(): static
    {
        return $this->state(['activo' => false]);
    }
}
