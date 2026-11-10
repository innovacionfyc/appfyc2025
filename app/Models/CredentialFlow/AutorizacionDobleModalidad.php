<?php

namespace App\Models\CredentialFlow;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Una autorización (vigente o revocada) de la doble certificación por modalidad para un evento. Solo la crea y la revoca el servicio
 * Eventos\AutorizacionesDobleModalidad; es un registro de auditoría: no se borra ni se reescribe.
 */
class AutorizacionDobleModalidad extends Model
{
    public const VIGENTE = 'vigente';

    public const REVOCADA = 'revocada';

    public const ORIGEN_PANEL = 'panel';

    public const ORIGEN_HEREDADA = 'configuracion_heredada';

    public const ORIGENES = [self::ORIGEN_PANEL, self::ORIGEN_HEREDADA];

    protected $table = 'cf_eventos_doble_modalidad';

    protected $fillable = [
        'evento_id', 'estado', 'origen', 'motivo', 'autorizada_por', 'autorizada_at', 'revocada_por', 'revocada_at', 'motivo_revocacion', 'vigente_evento_id',
    ];

    protected $casts = [
        'evento_id' => 'integer',
        'autorizada_por' => 'integer',
        'revocada_por' => 'integer',
        'vigente_evento_id' => 'integer',
        'autorizada_at' => 'datetime',
        'revocada_at' => 'datetime',
    ];

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoCertificacion::class, 'evento_id')->withTrashed();
    }

    public function autor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'autorizada_por');
    }

    public function revocador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revocada_por');
    }

    public function estaVigente(): bool
    {
        return $this->estado === self::VIGENTE;
    }
}
