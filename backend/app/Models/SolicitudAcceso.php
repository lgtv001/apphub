<?php
namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class SolicitudAcceso extends Model
{
    protected $table = 'solicitudes_acceso';

    protected $fillable = [
        'nombre',
        'email',
        'avatar_url',
        'provider',
        'provider_id',
        'estado',
    ];
}
