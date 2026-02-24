<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class Estado extends Model
{
    use SoftDeletes, HasAuditFields;
    protected $table = 'estados';

    protected $fillable = [
        'tipo_estado',
        'categoria_estado',
    ];

    public function areasFormacion() {
        return $this->hasMany(AreaFormacion::class, 'estado_id');
    }
    public function equiposFyc() {
        return $this->hasMany(EquipoFyc::class, 'estado_id');
    }
    public function usuarios() {
        return $this->hasMany(Usuario::class, 'estado_id');
    }
    public function eventos() {
        return $this->hasMany(Evento::class, 'estado_id');
    }
}