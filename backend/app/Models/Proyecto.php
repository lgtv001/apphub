<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Proyecto extends Model
{
    use HasFactory;

    protected $table = 'proyectos';

    protected $fillable = ['codigo', 'nombre', 'estado'];

    public function usuarios()
    {
        return $this->belongsToMany(Usuario::class, 'usuarios_proyectos', 'proyecto_id', 'usuario_id')
            ->withPivot('rol', 'tipo_id')
            ->withTimestamps();
    }

    public function asignaciones()
    {
        return $this->hasMany(UsuarioProyecto::class, 'proyecto_id');
    }

    public function areas()
    {
        return $this->hasMany(Area::class, 'proyecto_id')->orderBy('orden');
    }
}
