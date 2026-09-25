<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class PodcastComentario extends Model
{
    use SoftDeletes;

    public const PENDIENTE = 'pendiente';

    public const APROBADO = 'aprobado';

    public const RECHAZADO = 'rechazado';

    public const ESTADOS_MODERACION = [
        self::PENDIENTE,
        self::APROBADO,
        self::RECHAZADO,
    ];

    protected $table = 'podcast_comentarios';

    protected $fillable = [
        'episodio_id',
        'nombre',
        'correo',
        'contenido',
        'estado_moderacion',
        'ip_hash',
        'user_agent',
        'moderado_por',
        'moderado_en',
    ];

    // Datos del autor que nunca deben salir en respuestas públicas; el admin usa makeVisible().
    protected $hidden = [
        'correo',
        'ip_hash',
        'user_agent',
    ];

    protected $casts = [
        'moderado_en' => 'datetime',
    ];

    public function episodio()
    {
        return $this->belongsTo(PodcastEpisodio::class, 'episodio_id');
    }

    public function moderador()
    {
        return $this->belongsTo(Usuario::class, 'moderado_por');
    }
}
