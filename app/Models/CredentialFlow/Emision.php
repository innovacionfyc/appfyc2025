<?php

namespace App\Models\CredentialFlow;

use App\Models\Usuario;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Emisión oficial de una credencial. Es INMUTABLE: tras crearse solo puede cambiar lo necesario para
 * revocarla (estado, participante_vigente y los campos revocado_*). No se puede borrar (ni siquiera con
 * soft delete). El código público se genera con CodigoEmision antes de renderizar el PDF.
 */
class Emision extends Model
{
    public const EMITIDA = 'emitida';

    public const REVOCADA = 'revocada';

    /** Campos que se pueden modificar después de la creación (revocación). */
    public const MODIFICABLES = ['estado', 'participante_vigente', 'revocado_at', 'revocado_por', 'motivo_revocacion', 'updated_at'];

    protected $table = 'cf_emisiones';

    protected $guarded = [];

    protected $casts = [
        'datos_snapshot' => 'array',
        'diseno_snapshot' => 'array',
        'generador_snapshot' => 'array',
        'schema_version' => 'integer',
        'version' => 'integer',
        'pdf_bytes' => 'integer',
        'emitido_at' => 'datetime',
        'revocado_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::updating(function (Emision $emision) {
            $prohibidos = array_diff(array_keys($emision->getDirty()), self::MODIFICABLES);
            if ($prohibidos !== []) {
                throw new LogicException('Una emisión es inmutable: solo puede cambiar al revocarse (campos no permitidos: '.implode(', ', $prohibidos).').');
            }
        });

        static::deleting(function () {
            throw new LogicException('Una emisión no se puede eliminar.');
        });
    }

    public function vigente(): bool
    {
        return $this->estado === self::EMITIDA;
    }

    /** Incluye participantes eliminados (soft delete): la emisión sobrevive a su participante. */
    public function participante(): BelongsTo
    {
        return $this->belongsTo(Participante::class)->withTrashed();
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class)->withTrashed();
    }

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(Plantilla::class)->withTrashed();
    }

    /** Emisión a la que esta sustituye (reemisión). */
    public function reemplaza(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reemplaza_id');
    }

    /** Emisiones posteriores que sustituyen a esta. */
    public function reemisiones(): HasMany
    {
        return $this->hasMany(self::class, 'reemplaza_id');
    }

    public function emisor(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'emitido_por');
    }

    public function revocador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revocado_por');
    }
}
