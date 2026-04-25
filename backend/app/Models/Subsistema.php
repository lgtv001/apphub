<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subsistema extends Model
{
    use HasFactory;

    protected $table = 'subsistemas';

    protected $fillable = ['proyecto_id', 'sistema_id', 'codigo', 'nombre', 'orden'];

    public function sistema()
    {
        return $this->belongsTo(Sistema::class, 'sistema_id');
    }
}
