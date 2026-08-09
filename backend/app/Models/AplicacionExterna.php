<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AplicacionExterna extends Model
{
    protected $table = 'aplicaciones_externas';
    protected $fillable = ['codigo', 'nombre', 'url_base', 'activo'];
    protected $casts = ['activo' => 'boolean'];

    public function secciones()
    {
        return $this->hasMany(AplicacionSeccion::class, 'aplicacion_id');
    }

    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'usuarios_aplicaciones', 'aplicacion_id', 'usuario_id')
            ->withTimestamps();
    }
}
