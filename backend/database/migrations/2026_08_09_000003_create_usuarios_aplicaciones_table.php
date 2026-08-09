<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuarios_aplicaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_externas')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['usuario_id', 'aplicacion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuarios_aplicaciones');
    }
};
