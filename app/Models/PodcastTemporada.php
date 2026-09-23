<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class PodcastTemporada extends Model
{
    use SoftDeletes, HasAuditFields;

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
