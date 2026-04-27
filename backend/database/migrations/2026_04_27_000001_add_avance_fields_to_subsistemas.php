<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('subsistemas', function (Blueprint $table) {
            $table->date('fecha_inicio_plan')->nullable()->after('orden');
            $table->date('fecha_termino_plan')->nullable()->after('fecha_inicio_plan');
            $table->date('fecha_inicio_real')->nullable()->after('fecha_termino_plan');
            $table->date('fecha_termino_real')->nullable()->after('fecha_inicio_real');
            $table->tinyInteger('avance_constructivo')->unsigned()->nullable()->after('fecha_termino_real');
        });
    }

    public function down(): void
    {
        Schema::table('subsistemas', function (Blueprint $table) {
            $table->dropColumn([
                'fecha_inicio_plan',
                'fecha_termino_plan',
                'fecha_inicio_real',
                'fecha_termino_real',
                'avance_constructivo',
            ]);
        });
    }
};
