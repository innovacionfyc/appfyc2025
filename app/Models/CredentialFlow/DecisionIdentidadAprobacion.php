<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;
use LogicException;

/**
 * Segunda aprobación (doble control) de una autorización masiva de identidad (Fase 10B-3C-3). Nunca se elimina: se revoca hacia adelante. Sin datos personales.
 */
class DecisionIdentidadAprobacion extends Model
{
    public const PENDIENTE = 'pendiente';

    public const APROBADA = 'aprobada';

    public const REVOCADA = 'revocada';

    public const FUENTES = ['propiedad_buzon', 'certificacion_organizador', 'registro_validado', 'otra'];

    protected $table = 'cf_decisiones_identidad_aprobaciones';

    protected $guarded = [];

    protected $casts = ['solicitada_at' => 'datetime', 'aprobada_at' => 'datetime', 'revocada_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (DecisionIdentidadAprobacion $a) {
            if (! in_array($a->estado, [self::PENDIENTE, self::APROBADA, self::REVOCADA], true) || ! in_array($a->fuente_evidencia, self::FUENTES, true)) {
                throw new InvalidArgumentException('El estado o la fuente de la aprobación no es válido.');
            }
            if ($a->aprobada_por !== null && (int) $a->aprobada_por === (int) $a->solicitada_por) {
                throw new InvalidArgumentException('El aprobador debe ser distinto del solicitante.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Una aprobación de identidad no se elimina: se revoca.');
        });
    }

    public function decision(): BelongsTo
    {
        return $this->belongsTo(DecisionIdentidad::class, 'decision_id');
    }
}
