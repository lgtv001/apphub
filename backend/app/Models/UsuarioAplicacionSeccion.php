<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class UsuarioAplicacionSeccion extends Model
{
    protected $table = 'usuario_aplicacion_secciones';
    protected $fillable = ['usuario_id', 'aplicacion_id', 'seccion_id', 'nivel'];

    public function usuario()
    {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }

    public function aplicacion()
    {
        return $this->belongsTo(AplicacionExterna::class, 'aplicacion_id');
    }

    public function seccion()
    {
        return $this->belongsTo(AplicacionSeccion::class, 'seccion_id');
    }
}
