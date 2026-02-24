<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class Rol extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'roles';

    protected $fillable = [
        'tipo_rol',
        'slug',
        'descripcion',
        'permisos',
    ];

    protected $casts = [
        'permisos' => 'array',
    ];

    public function organizadores() {
        return $this->hasMany(PerfilOrganizador::class, 'rol_id');
    }
}