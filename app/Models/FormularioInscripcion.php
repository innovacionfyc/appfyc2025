<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class FormularioInscripcion extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'formularios_inscripcion';


    protected $fillable = [
        'tipo_persona',
        'nombres',
        'apellidos',
        'cedula',
        'cargo',
        'entidad_empresa',
        'celular',
        'ciudad',
        'correo_personal',
        'correo_corporativo',
        'modo_asistencia',
        'politica_datos',
        'medio_reconocimiento'
    ];

    public function eventos()
    {
        return $this->hasMany(Evento::class, 'formulario_inscripcion_id');
    }
}