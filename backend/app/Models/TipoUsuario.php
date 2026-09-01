<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TipoUsuario extends Model
{
    use HasFactory;

    protected $table = 'tipos_usuario';

    protected $fillable = ['nombre', 'descripcion', 'activo'];

    protected $casts = ['activo' => 'boolean'];

    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'usuarios_tipos_usuario', 'tipo_usuario_id', 'usuario_id')
            ->withTimestamps();
    }

    public function secciones()
    {
        return $this->belongsToMany(AplicacionSeccion::class, 'tipo_usuario_aplicacion_secciones', 'tipo_usuario_id', 'seccion_id')
            ->withPivot('nivel')
            ->withTimestamps();
    }
}
