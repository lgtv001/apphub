<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    // Nombre que Postgres le da por defecto al check constraint inline de un enum sin nombre
    // explícito (convención <tabla>_<columna>_check) -- el mismo que generó la migración que creó
    // la tabla (2026_08_09_000006, columna 'accion' con ['CREATE','DELETE']).
    private const CHECK_CONSTRAINT = 'usuarios_aplicaciones_log_accion_check';

    public function up(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            // ->change() sobre un enum en Postgres NO funciona: Laravel arma
            // "alter column accion type varchar(255) check (...)", y `check` no es válido dentro
            // de un ALTER COLUMN ... TYPE ahí (solo acepta COLLATE/USING) -- error de sintaxis.
            // Se reemplaza el check constraint viejo por uno nuevo con SQL crudo en su lugar.
            DB::statement('alter table usuarios_aplicaciones_log drop constraint if exists '.self::CHECK_CONSTRAINT);
            DB::statement("alter table usuarios_aplicaciones_log add constraint ".self::CHECK_CONSTRAINT." check (accion in ('CREATE','UPDATE','DELETE'))");

            return;
        }

        // SQLite (tests): Laravel reconstruye la tabla por debajo, ->change() sí funciona.
        Schema::table('usuarios_aplicaciones_log', function (Blueprint $table) {
            $table->enum('accion', ['CREATE', 'UPDATE', 'DELETE'])->change();
        });
    }

    public function down(): void
    {
        if (DB::getDriverName() === 'pgsql') {
            DB::statement('alter table usuarios_aplicaciones_log drop constraint if exists '.self::CHECK_CONSTRAINT);
            DB::statement("alter table usuarios_aplicaciones_log add constraint ".self::CHECK_CONSTRAINT." check (accion in ('CREATE','DELETE'))");

            return;
        }

        Schema::table('usuarios_aplicaciones_log', function (Blueprint $table) {
            $table->enum('accion', ['CREATE', 'DELETE'])->change();
        });
    }
};
