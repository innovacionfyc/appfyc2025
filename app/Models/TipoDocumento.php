<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class TipoDocumento extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'tipos_documento';

    protected $fillable = [
        'documento_legal',
        'sigla',
        'codigo_dian',
    ];

    public function organizadores() {
        return $this->hasMany(PerfilOrganizador::class, 'tipo_documento_id');
    }
}