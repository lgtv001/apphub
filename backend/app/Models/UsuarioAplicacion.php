<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioAplicacion extends Model
{
    protected $table = 'usuarios_aplicaciones';
    protected $fillable = ['usuario_id', 'aplicacion_id'];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function aplicacion()
    {
        return $this->belongsTo(AplicacionExterna::class, 'aplicacion_id');
    }
}
