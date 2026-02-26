<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class FormularioBase extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'formularios_base';
    protected $fillable = [
        'nombre_plantilla',
        'tipo_persona',
        'solicitar_cargo',
        'solicitar_empresa',
        'solicitar_correo_corp',
        'solicitar_soporte',
        'politica_datos'
    ];

    // Una plantilla puede usarse en muchos eventos
    public function eventos()
    {
        return $this->hasMany(Evento::class, 'formulario_base_id');
    }
}