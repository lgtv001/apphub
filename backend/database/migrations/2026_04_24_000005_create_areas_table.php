<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('areas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('proyecto_id')->constrained('proyectos')->onDelete('cascade');
            $table->string('codigo', 50);
            $table->string('nombre');
            $table->integer('orden')->default(0);
            $table->timestamps();
            $table->unique(['proyecto_id', 'codigo']);
        });
    }
    public function down(): void { Schema::dropIfExists('areas'); }
};
