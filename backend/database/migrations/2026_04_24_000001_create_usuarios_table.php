<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->unique();
            $table->string('password_hash');
            $table->enum('rol_global', ['superuser', 'admin', 'usuario'])->default('usuario');
            $table->boolean('activo')->default(true);
            $table->timestamps();
        });
    }
    public function down(): void { Schema::dropIfExists('usuarios'); }
};
