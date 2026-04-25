<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('usuarios_proyectos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->enum('rol', ['admin', 'usuario'])->default('usuario');
            $table->foreignId('tipo_id')->nullable()->constrained('tipos_usuario')->nullOnDelete();
            $table->timestamps();
            $table->unique(['usuario_id', 'proyecto_id']);
        });
    }
    public function down(): void { Schema::dropIfExists('usuarios_proyectos'); }
};
