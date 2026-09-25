<?php

namespace App\Models;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PodcastTemporada extends Model
{
    use HasAuditFields, SoftDeletes;

    protected $table = 'podcast_temporadas';

    protected $fillable = [
        'numero',
        'titulo',
        'slug',
        'descripcion',
        'imagen_portada',
        'estado_id',
    ];

    protected $casts = [
        'numero' => 'integer',
    ];

    public function episodios()
    {
        return $this->hasMany(PodcastEpisodio::class, 'temporada_id')->orderBy('numero');
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class, 'estado_id');
    }
}
