<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/** Respuesta completa a una versión de encuesta. A lo sumo un origen de certificado (legado o moderno); puede ser huérfana. */
class EncuestaRespuesta extends Model
{
    protected $table = 'cf_encuestas_respuestas';

    protected $guarded = [];

    protected $casts = ['completada_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (EncuestaRespuesta $r) {
            if ($r->certificado_legado_id !== null && $r->emision_id !== null) {
                throw new InvalidArgumentException('Una respuesta pertenece a un certificado histórico o a una emisión moderna, no a ambos.');
            }
        });
    }

    public function version(): BelongsTo
    {
        return $this->belongsTo(EncuestaVersion::class, 'version_id');
    }

    public function detalle(): HasMany
    {
        return $this->hasMany(EncuestaRespuestaDetalle::class, 'respuesta_id');
    }
}
