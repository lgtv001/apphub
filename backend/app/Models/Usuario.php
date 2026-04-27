<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

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
}
