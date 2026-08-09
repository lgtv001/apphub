<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;
use App\Models\AplicacionExterna;
use App\Models\AplicacionSeccion;

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

    public function aplicaciones()
    {
        return $this->belongsToMany(AplicacionExterna::class, 'usuarios_aplicaciones', 'usuario_id', 'aplicacion_id')
            ->withTimestamps();
    }

    public function seccionesAplicaciones()
    {
        return $this->belongsToMany(AplicacionSeccion::class, 'usuario_aplicacion_secciones', 'usuario_id', 'seccion_id')
            ->withPivot('aplicacion_id', 'nivel')
            ->withTimestamps();
    }

    /** @return array<string,string> codigo de sección => nivel ('ver'|'editar') para una app dada */
    public function seccionesDeAplicacion(string $codigoApp): array
    {
        return $this->seccionesAplicaciones()
            ->whereHas('aplicacion', fn ($q) => $q->where('codigo', $codigoApp))
            ->get()
            ->mapWithKeys(fn ($seccion) => [$seccion->codigo => $seccion->pivot->nivel])
            ->all();
    }
}
