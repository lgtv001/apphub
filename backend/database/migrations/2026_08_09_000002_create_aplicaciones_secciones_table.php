<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('aplicaciones_secciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('aplicacion_id')->constrained('aplicaciones_externas')->onDelete('cascade');
            $table->string('codigo', 30);
            $table->string('nombre');
            $table->timestamps();
            $table->unique(['aplicacion_id', 'codigo']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('aplicaciones_secciones');
    }
};
