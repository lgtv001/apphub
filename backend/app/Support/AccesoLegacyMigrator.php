<?php
// backend/app/Support/AccesoLegacyMigrator.php
namespace App\Support;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * Copia los datos del mecanismo de grant directo (usuarios_aplicaciones +
 * usuario_aplicacion_secciones) al mecanismo nuevo de Tipo de Usuario, sin tocar el schema.
 * Separado de la migración que la invoca (2026_09_01_000003_...) para poder probarla de forma
 * aislada -- ver AccesoLegacyMigratorTest.
 */
class AccesoLegacyMigrator
{
    public static function migrar(): void
    {
        $secciones = DB::table('usuario_aplicacion_secciones')->get();
        $seccionesPorUsuarioApp = $secciones->groupBy(fn ($s) => "{$s->usuario_id}:{$s->aplicacion_id}");

        $grants = DB::table('usuarios_aplicaciones')->get();

        // Pre-flight (hallazgo architect #8): abortar si algún grant no tiene ninguna sección --
        // usuarios_aplicaciones permite hoy dar acceso a una app sin secciones (el launcher
        // muestra la tarjeta igual), un caso que el modelo nuevo no puede representar (el acceso
        // se deriva de las secciones). Mejor abortar que migrar en silencio a MENOS acceso.
        foreach ($grants as $grant) {
            $clave = "{$grant->usuario_id}:{$grant->aplicacion_id}";
            if (!isset($seccionesPorUsuarioApp[$clave]) || $seccionesPorUsuarioApp[$clave]->isEmpty()) {
                throw new RuntimeException(
                    "Migración abortada: el usuario {$grant->usuario_id} tiene acceso a la ".
                    "aplicación {$grant->aplicacion_id} sin ninguna sección -- el modelo de Tipo ".
                    "de Usuario no puede representar ese caso. Revisar manualmente antes de reintentar."
                );
            }
        }

        foreach ($grants as $grant) {
            // Un tipo por usuario, nunca compartido (hallazgo architect #7): mezclar 2+ usuarios
            // en el mismo tipo migrado uniría sus accesos entre sí.
            $usuario = DB::table('usuarios')->where('id', $grant->usuario_id)->first();
            $nombreTipo = "Acceso SSO (migrado) — {$usuario->email}";

            $tipoId = DB::table('tipos_usuario')->where('nombre', $nombreTipo)->value('id');
            if (!$tipoId) {
                $tipoId = DB::table('tipos_usuario')->insertGetId([
                    'nombre'      => $nombreTipo,
                    'descripcion' => 'Generado automáticamente al migrar el grant directo del 2026-08-31.',
                    'activo'      => true,
                    'created_at'  => now(),
                    'updated_at'  => now(),
                ]);
            }

            $clave = "{$grant->usuario_id}:{$grant->aplicacion_id}";
            foreach ($seccionesPorUsuarioApp[$clave] as $seccion) {
                // Idempotente (hallazgo architect #13): updateOrInsert, nunca insert() puro.
                DB::table('tipo_usuario_aplicacion_secciones')->updateOrInsert(
                    ['tipo_usuario_id' => $tipoId, 'seccion_id' => $seccion->seccion_id],
                    ['nivel' => $seccion->nivel, 'created_at' => now(), 'updated_at' => now()]
                );
            }

            DB::table('usuarios_tipos_usuario')->updateOrInsert(
                ['usuario_id' => $grant->usuario_id, 'tipo_usuario_id' => $tipoId],
                ['created_at' => now(), 'updated_at' => now()]
            );
        }
    }
}
