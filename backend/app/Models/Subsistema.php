<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subsistema extends Model
{
    use HasFactory;

    protected $table = 'subsistemas';

    protected $fillable = [
        'proyecto_id', 'sistema_id', 'codigo', 'nombre', 'orden',
        'fecha_inicio_plan', 'fecha_termino_plan',
        'fecha_inicio_real', 'fecha_termino_real',
        'avance_constructivo',
    ];

    protected $casts = [
        'fecha_inicio_plan'  => 'date:Y-m-d',
        'fecha_termino_plan' => 'date:Y-m-d',
        'fecha_inicio_real'  => 'date:Y-m-d',
        'fecha_termino_real' => 'date:Y-m-d',
    ];

    public function sistema()
    {
        return $this->belongsTo(Sistema::class, 'sistema_id');
    }

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }
}
