<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Support\Facades\DB;

class Usuario extends Authenticatable
{
    use HasApiTokens, HasFactory;

    protected $table = 'usuarios';

    protected $fillable = [
        'nombre',
        'email',
        'password_hash',
        'rol_global',
        'activo',
    ];

    protected $hidden = ['password_hash'];

    protected $casts = [
        'activo'        => 'boolean',
        'password_hash' => 'hashed',
    ];

    public function getAuthPassword(): string
    {
        return $this->password_hash;
    }

    public function proyectos()
    {
        return $this->belongsToMany(Proyecto::class, 'usuarios_proyectos', 'usuario_id', 'proyecto_id')
            ->withPivot('rol', 'tipo_id')
            ->withTimestamps();
    }

    public function asignaciones()
    {
        return $this->hasMany(UsuarioProyecto::class, 'usuario_id');
    }

    public function tiposUsuario()
    {
        return $this->belongsToMany(TipoUsuario::class, 'usuarios_tipos_usuario', 'usuario_id', 'tipo_usuario_id')
            ->withTimestamps();
    }

    public function tieneAccesoA(string $codigoApp): bool
    {
        return $this->accesoEfectivoQuery()->where('a.codigo', $codigoApp)->exists();
    }

    /** @return string[] códigos de aplicaciones a las que el usuario tiene al menos una sección efectiva */
    public function codigosDeAplicacionesConAcceso(): array
    {
        return $this->accesoEfectivoQuery()->distinct()->pluck('a.codigo')->all();
    }

    /** @return array<string,string> codigo de sección => nivel efectivo ('ver'|'editar') para una app */
    public function seccionesDeAplicacion(string $codigoApp): array
    {
        return $this->accesoEfectivoQuery()
            ->where('a.codigo', $codigoApp)
            ->select('s.codigo as seccion_codigo', DB::raw("MAX(CASE WHEN tas.nivel = 'editar' THEN 1 ELSE 0 END) as gana_editar"))
            ->groupBy('s.codigo')
            ->get()
            ->mapWithKeys(fn ($row) => [$row->seccion_codigo => $row->gana_editar ? 'editar' : 'ver'])
            ->all();
    }

    /**
     * Base de la unión de permisos: todos los tipos ACTIVOS asignados al usuario, unidos a las
     * secciones que cada uno otorga. "Gana editar" se resuelve en el llamador vía CASE WHEN,
     * NUNCA con MAX() directo sobre la columna nivel -- el enum se guarda como texto y ordena
     * alfabético ('editar' < 'ver'), así que MAX() de texto da el resultado contrario al
     * pretendido (confirmado real en Postgres y SQLite, hallazgo architect #5).
     */
    private function accesoEfectivoQuery()
    {
        return DB::table('usuarios_tipos_usuario as ut')
            ->join('tipos_usuario as t', 't.id', '=', 'ut.tipo_usuario_id')
            ->join('tipo_usuario_aplicacion_secciones as tas', 'tas.tipo_usuario_id', '=', 't.id')
            ->join('aplicaciones_secciones as s', 's.id', '=', 'tas.seccion_id')
            ->join('aplicaciones_externas as a', 'a.id', '=', 's.aplicacion_id')
            ->where('ut.usuario_id', $this->id)
            ->where('t.activo', true);
    }
}
