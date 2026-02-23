<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class ContenidoTematico extends Model
{
    use SoftDeletes, HasAuditFields;

    protected $table = 'contenidos_tematicos';

    protected $fillable = [
        'tema',
        'subtemas',
        'alcance',
        'bg_estilo'
    ];

    protected $casts = [
        'subtemas' => 'array',
    ];

    public function equipoAcademico()
    {
        return $this->belongsToMany(
            PerfilConferencista::class,
            'contenido_conferencista',
            'contenido_tematico_id',
            'conferencista_id'
        )->withTimestamps();
    }

    public function eventos()
    {
        return $this->hasMany(Evento::class, 'contenido_tematico_id');
    }
}