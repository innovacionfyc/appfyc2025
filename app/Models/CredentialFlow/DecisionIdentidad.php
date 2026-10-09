<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\ConstructorProtegido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

/**
 * Decisión humana de identidad sobre un caso `identidad_ambigua` (Fase 10B-3A). Registra, no autoriza: en esta fase ninguna decisión cambia el portal.
 * NUNCA se elimina (se revoca hacia adelante). Sin datos personales: documento y correo solo como HMAC.
 */
class DecisionIdentidad extends Model
{
    public const MISMA_PERSONA = 'misma_persona';

    public const PERSONAS_DISTINTAS = 'personas_distintas';

    public const CORREO_AUTORIZADO = 'correo_autorizado';

    public const REQUIERE_SOPORTE = 'requiere_soporte';

    public const NO_RESOLUBLE = 'no_resoluble';

    public const TIPOS = [self::MISMA_PERSONA, self::PERSONAS_DISTINTAS, self::CORREO_AUTORIZADO, self::REQUIERE_SOPORTE, self::NO_RESOLUBLE];

    /** Decisiones sobre el caso en su conjunto (no abarcan grupos ni correos). */
    public const TERMINALES = [self::REQUIERE_SOPORTE, self::NO_RESOLUBLE];

    public const VIGENTE = 'vigente';

    public const REVOCADA = 'revocada';

    protected $table = 'cf_decisiones_identidad';

    protected $guarded = [];

    protected $attributes = ['estado' => self::VIGENTE];

    protected $casts = [
        'revocada_at' => 'datetime', 'declaro_evidencia_externa' => 'boolean', 'confirmo_alcance_masivo' => 'boolean', 'confirmacion_reforzada' => 'boolean',
    ];

    protected static function booted(): void
    {
        static::saving(function (DecisionIdentidad $d) {
            if (! in_array($d->tipo, self::TIPOS, true) || ! in_array($d->estado, [self::VIGENTE, self::REVOCADA], true)) {
                throw new InvalidArgumentException('El tipo o el estado de la decisión no es válido.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Una decisión de identidad no se elimina: se revoca.');
        });
    }

    public function newEloquentBuilder($query): ConstructorProtegido
    {
        return (new ConstructorProtegido($query))->protegiendo(true, false);
    }

    public function conciliacion(): BelongsTo
    {
        return $this->belongsTo(Conciliacion::class, 'conciliacion_id');
    }

    public function grupos(): HasMany
    {
        return $this->hasMany(DecisionIdentidadGrupo::class, 'decision_id');
    }

    public function correos(): HasMany
    {
        return $this->hasMany(DecisionIdentidadCorreo::class, 'decision_id');
    }

    public function esVigente(): bool
    {
        return $this->estado === self::VIGENTE;
    }
}
