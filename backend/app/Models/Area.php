<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Area extends Model
{
    use HasFactory;

    protected $table = 'areas';

    protected $fillable = ['proyecto_id', 'codigo', 'nombre', 'orden'];

    public function proyecto()
    {
        return $this->belongsTo(Proyecto::class, 'proyecto_id');
    }

    public function subareas()
    {
        return $this->hasMany(Subarea::class, 'area_id')->orderBy('orden');
    }
}
