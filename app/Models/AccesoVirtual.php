<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class AccesoVirtual extends Model
{
    use SoftDeletes, HasAuditFields;

    protected $table = 'accesos_virtuales';

    protected $fillable = [
        'organizador_id',
        'estado_id',
        'nombre',
        'slug',
        'descripcion',
        'fecha',
        'hora',
        'url_zoom',
        'imagen_banner',
    ];

    protected $casts = [
        'fecha' => 'date',
    ];

    public function organizador()
    {
        return $this->belongsTo(Usuario::class, 'organizador_id');
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class, 'estado_id');
    }
}
