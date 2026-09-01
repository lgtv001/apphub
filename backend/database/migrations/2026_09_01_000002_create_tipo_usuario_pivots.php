<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // AccesoLegacyMigrator (Task 3) hace firstOrCreate por nombre durante la migración de
        // datos y depende de que esta garantía sea real, no solo del controller (hallazgo
        // architect #14).
        Schema::table('tipos_usuario', function (Blueprint $table) {
            $table->unique('nombre');
        });

        Schema::create('usuarios_tipos_usuario', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('tipo_usuario_id')->constrained('tipos_usuario')->onDelete('cascade');
            $table->timestamps();
            $table->unique(['usuario_id', 'tipo_usuario_id']);
        });

        // Sin columna aplicacion_id denormalizada (a diferencia de usuario_aplicacion_secciones):
        // la app de una sección se resuelve siempre vía seccion.aplicacion_id, un solo camino,
        // sin riesgo de que dos columnas queden desincronizadas (hallazgo architect #9).
        Schema::create('tipo_usuario_aplicacion_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tipo_usuario_id')->constrained('tipos_usuario')->onDelete('cascade');
            $table->foreignId('seccion_id')->constrained('aplicaciones_secciones')->onDelete('cascade');
            $table->enum('nivel', ['ver', 'editar'])->default('ver');
            $table->timestamps();
            $table->unique(['tipo_usuario_id', 'seccion_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tipo_usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_tipos_usuario');
        Schema::table('tipos_usuario', function (Blueprint $table) {
            $table->dropUnique(['tipos_usuario_nombre_unique']);
        });
    }
};
