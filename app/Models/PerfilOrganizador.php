<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class PerfilOrganizador extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'perfil_organizadores';

    protected $fillable = [
        'usuario_id',
        'primer_nombre',
        'segundo_nombre',
        'primer_apellido',
        'segundo_apellido',
        'foto',
        'telefono_corporativo',
        'telefono_personal',
        'correo_corporativo',
        'cargo',
        'tipo_documento_id',
        'numero_documento',
        'rol_id',
        'area_encargada_id',
        'equipo_id',
    ];

    public function usuario() {
        return $this->belongsTo(Usuario::class, 'usuario_id');
    }
    public function rol() {
        return $this->belongsTo(Rol::class, 'rol_id');
    }
    public function areaEncargada() {
        return $this->belongsTo(AreaFormacion::class, 'area_encargada_id');
    }
    public function equipo() {
        return $this->belongsTo(EquipoFyc::class, 'equipo_id');
    }
    public function tipoDocumento() {
        return $this->belongsTo(TipoDocumento::class, 'tipo_documento_id');
    }
}