<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // Laravel 12 altera enums de forma nativa (sin doctrine/dbal) tanto en Postgres como en
        // SQLite -- reconstruye la tabla por debajo si hace falta.
        Schema::table('usuarios_aplicaciones_log', function (Blueprint $table) {
            $table->enum('accion', ['CREATE', 'UPDATE', 'DELETE'])->change();
        });
    }

    public function down(): void
    {
        Schema::table('usuarios_aplicaciones_log', function (Blueprint $table) {
            $table->enum('accion', ['CREATE', 'DELETE'])->change();
        });
    }
};
