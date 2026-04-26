<?php

namespace Database\Seeders;

use App\Models\Usuario;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class SuperuserSeeder extends Seeder
{
    public function run(): void
    {
        if (Usuario::where('rol_global', 'superuser')->exists()) {
            $this->command->info('SUPERUSER ya existe, omitiendo.');
            return;
        }

        $email    = env('SUPERUSER_EMAIL', 'admin@apphub.cl');
        $password = env('SUPERUSER_PASSWORD', 'changeme123');

        Usuario::create([
            'nombre'        => 'Administrador',
            'email'         => $email,
            'password_hash' => Hash::make($password),
            'rol_global'    => 'superuser',
            'activo'        => true,
        ]);

        $this->command->info("SUPERUSER creado: {$email}");
    }
}
