<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class EquipoFyc extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'equipos_fyc';

    protected $fillable = [
        'nombre',
        'slug',
        'estado_id'
    ];

    public function estado() {
        return $this->belongsTo(Estado::class, 'estado_id');
    }
    public function organizadores() {
        return $this->hasMany(PerfilOrganizador::class, 'equipo_id');
    }
}