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
}
