<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Emisión programada de una base: «pendiente → ejecutando → completada | fallida», o «cancelada». Solo una ACTIVA (pendiente o ejecutando) por base, garantizado
 * por el índice único de `lote_activa`. No guarda datos personales: la huella son hashes y el resultado son cifras y códigos.
 */
class EmisionProgramada extends Model
{
    protected $table = 'cf_emisiones_programadas';

    public const PENDIENTE = 'pendiente';

    public const EJECUTANDO = 'ejecutando';

    public const COMPLETADA = 'completada';

    public const FALLIDA = 'fallida';

    public const CANCELADA = 'cancelada';

    /** Estados que ocupan la base (bloquean otra programación y la emisión manual). */
    public const ACTIVOS = [self::PENDIENTE, self::EJECUTANDO];

    public const ESTADOS = [self::PENDIENTE, self::EJECUTANDO, self::COMPLETADA, self::FALLIDA, self::CANCELADA];

    protected $fillable = [
        'lote_id', 'lote_activa', 'estado', 'version', 'programada_para', 'zona_horaria', 'huella', 'huella_detalle', 'oficiales_esperados', 'qa_esperados',
        'plantilla_id', 'variante_qa_id', 'programada_por', 'confirmada_at', 'confirmada_por', 'reprogramaciones', 'cancelada_at', 'cancelada_por',
        'motivo_cancelacion', 'intentos', 'iniciada_at', 'latido_at', 'terminada_at', 'resultado', 'error_codigo', 'error_mensaje',
    ];

    protected $casts = [
        'programada_para' => 'datetime',
        'huella_detalle' => 'array',
        'resultado' => 'array',
        'confirmada_at' => 'datetime',
        'cancelada_at' => 'datetime',
        'iniciada_at' => 'datetime',
        'latido_at' => 'datetime',
        'terminada_at' => 'datetime',
        'version' => 'integer',
        'oficiales_esperados' => 'integer',
        'qa_esperados' => 'integer',
        'reprogramaciones' => 'integer',
        'intentos' => 'integer',
    ];

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class)->withTrashed();
    }

    public function activa(): bool
    {
        return in_array($this->estado, self::ACTIVOS, true);
    }

    /** @param  Builder<EmisionProgramada>  $q */
    public function scopeActivas(Builder $q): void
    {
        $q->whereIn('estado', self::ACTIVOS);
    }
}
