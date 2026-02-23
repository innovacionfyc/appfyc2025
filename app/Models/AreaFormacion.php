<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class AreaFormacion extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'areas_formacion';

    public function estado() {
        return $this->belongsTo(Estado::class, 'estado_id');
    }
    public function organizadores() {
        return $this->hasMany(PerfilOrganizador::class, 'area_encargada_id');
    }
    public function conferencistas() {
        return $this->hasMany(PerfilConferencista::class, 'area_encargada_id');
    }
    public function eventos() {
        return $this->hasMany(Evento::class, 'area_formacion_id');
    }
}