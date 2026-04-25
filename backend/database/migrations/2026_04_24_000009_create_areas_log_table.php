<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('areas_log', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('proyecto_id')->nullable();
            $table->unsignedBigInteger('usuario_id');
            $table->enum('accion', ['CREATE','UPDATE','DELETE','IMPORT','IMPORT_ERROR_DISMISSED','VALIDATION_ERROR']);
            $table->unsignedBigInteger('entidad_id')->nullable();
            $table->json('datos_antes')->nullable();
            $table->json('datos_despues')->nullable();
            $table->json('error_detalle')->nullable();
            $table->string('ip', 45)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }
    public function down(): void { Schema::dropIfExists('areas_log'); }
};
