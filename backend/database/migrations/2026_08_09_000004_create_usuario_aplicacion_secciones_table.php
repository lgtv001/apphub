<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('usuario_aplicacion_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_externas')->onDelete('cascade');
            $table->foreignId('seccion_id')->constrained('aplicaciones_secciones')->onDelete('cascade');
            $table->enum('nivel', ['ver', 'editar'])->default('ver');
            $table->timestamps();
            $table->unique(['usuario_id', 'seccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('usuario_aplicacion_secciones');
    }
};
