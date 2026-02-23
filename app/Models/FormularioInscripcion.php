<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class FormularioInscripcion extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'formularios_inscripcion';

    public function eventos() {
        return $this->hasMany(Evento::class, 'formulario_inscripcion_id');
    }
}