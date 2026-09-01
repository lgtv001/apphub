<?php
// backend/database/migrations/2026_09_01_000003_migrar_accesos_directos_a_tipos_usuario.php
use App\Support\AccesoLegacyMigrator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        AccesoLegacyMigrator::migrar();

        Schema::dropIfExists('usuario_aplicacion_secciones');
        Schema::dropIfExists('usuarios_aplicaciones');
    }

    public function down(): void
    {
        // Best-effort (hallazgo architect #13): revertir esta migración en producción no es un
        // escenario esperado -- no se reconstruye el estado exacto pre-migración.
        throw new \RuntimeException(
            'Esta migración no es reversible: recrear usuarios_aplicaciones/usuario_aplicacion_secciones '.
            'desde tipos_usuario perdería la distinción "grant directo" original. Restaurar desde backup si hace falta revertir.'
        );
    }
};
