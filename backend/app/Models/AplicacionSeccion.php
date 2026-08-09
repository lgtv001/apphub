<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AplicacionSeccion extends Model
{
    protected $table = 'aplicaciones_secciones';
    protected $fillable = ['aplicacion_id', 'codigo', 'nombre'];

    public function aplicacion()
    {
        return $this->belongsTo(AplicacionExterna::class, 'aplicacion_id');
    }
}
