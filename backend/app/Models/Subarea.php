<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Subarea extends Model
{
    use HasFactory;

    protected $table = 'subareas';

    protected $fillable = ['proyecto_id', 'area_id', 'codigo', 'nombre', 'orden'];

    public function area()
    {
        return $this->belongsTo(Area::class, 'area_id');
    }

    public function sistemas()
    {
        return $this->hasMany(Sistema::class, 'subarea_id')->orderBy('orden');
    }
}
