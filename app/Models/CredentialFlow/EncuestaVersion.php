<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Versión/snapshot de una encuesta. Una respuesta queda ligada a la versión exacta vigente al contestar. */
class EncuestaVersion extends Model
{
    use HasAuditFields;

    protected $table = 'cf_encuestas_versiones';

    protected $guarded = [];

    protected $casts = ['obligatoria' => 'boolean', 'publicada_at' => 'datetime', 'activa_desde' => 'datetime', 'activa_hasta' => 'datetime', 'snapshot' => 'array'];

    public function encuesta(): BelongsTo
    {
        return $this->belongsTo(Encuesta::class, 'encuesta_id');
    }

    public function preguntas(): HasMany
    {
        return $this->hasMany(EncuestaPregunta::class, 'version_id')->orderBy('orden');
    }
}
