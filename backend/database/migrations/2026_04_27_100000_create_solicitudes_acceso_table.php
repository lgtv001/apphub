<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('solicitudes_acceso', function (Blueprint $table) {
            $table->id();
            $table->string('nombre');
            $table->string('email')->index();
            $table->string('avatar_url')->nullable();
            $table->string('provider');        // 'github' | 'google'
            $table->string('provider_id');
            $table->enum('estado', ['pendiente', 'aprobado', 'rechazado'])->default('pendiente');
            $table->timestamps();

            $table->unique(['provider', 'provider_id']);
        });
    }

    public function down(): void { Schema::dropIfExists('solicitudes_acceso'); }
};
