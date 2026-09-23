<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use App\Traits\HasAuditFields;

class PodcastEpisodio extends Model
{
    use SoftDeletes, HasAuditFields;

    protected $table = 'podcast_episodios';

    protected $fillable = [
        'temporada_id',
        'numero',
        'titulo',
        'slug',
        'invitado_nombre',
        'invitado_cargo',
        'invitado_foto',
        'descripcion',
        'fecha_publicacion',
        'youtube_video_id',
        'youtube_url',
        'imagen_miniatura',
        'duracion_segundos',
        'destacado',
        'estado_id',
    ];

    protected $casts = [
        'numero' => 'integer',
        'fecha_publicacion' => 'date',
        'duracion_segundos' => 'integer',
        'destacado' => 'boolean',
    ];

    public function temporada()
    {
        return $this->belongsTo(PodcastTemporada::class, 'temporada_id');
    }

    public function estado()
    {
        return $this->belongsTo(Estado::class, 'estado_id');
    }

    public function comentarios()
    {
        return $this->hasMany(PodcastComentario::class, 'episodio_id');
    }

    public function comentariosAprobados()
    {
        return $this->hasMany(PodcastComentario::class, 'episodio_id')
            ->where('estado_moderacion', PodcastComentario::APROBADO);
    }

    public function reacciones()
    {
        return $this->hasMany(PodcastReaccion::class, 'episodio_id');
    }
}
