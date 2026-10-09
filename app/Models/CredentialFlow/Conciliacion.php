<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\ConstructorProtegido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;
use LogicException;

/**
 * Caso de conciliación histórica (Fase 10A): algo del histórico que requiere una decisión humana. NO modifica el histórico: describe,
 * y (en 10B) registrará la decisión en su bitácora. Nunca se borra desde la aplicación (solo el rollback técnico controlado, por SQL).
 * `referencia_clave` jamás lleva datos personales.
 */
class Conciliacion extends Model
{
    public const TIPO_CONFLICTO_VARIANTES = 'conflicto_variantes';

    public const TIPO_REVISION_DOCUMENTO = 'revision_documento';

    public const TIPO_IDENTIDAD_AMBIGUA = 'identidad_ambigua';

    public const TIPO_PLANTILLA_CANDIDATA = 'plantilla_candidata';

    public const TIPO_PLANTILLA_FALTANTE = 'plantilla_faltante';

    public const TIPO_PLANTILLA_TIPO_INVALIDO = 'plantilla_tipo_invalido';

    public const TIPOS = [
        self::TIPO_CONFLICTO_VARIANTES, self::TIPO_REVISION_DOCUMENTO, self::TIPO_IDENTIDAD_AMBIGUA,
        self::TIPO_PLANTILLA_CANDIDATA, self::TIPO_PLANTILLA_FALTANTE, self::TIPO_PLANTILLA_TIPO_INVALIDO,
    ];

    public const ABIERTO = 'abierto';

    public const RESUELTO = 'resuelto';

    public const DESCARTADO = 'descartado';

    public const REQUIERE_SOPORTE = 'requiere_soporte';

    public const ESTADOS = [self::ABIERTO, self::RESUELTO, self::DESCARTADO, self::REQUIERE_SOPORTE];

    protected $table = 'cf_conciliaciones';

    protected $guarded = [];

    protected $attributes = ['estado' => self::ABIERTO];

    protected $casts = ['resuelto_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (Conciliacion $c) {
            if (! in_array($c->tipo, self::TIPOS, true)) {
                throw new InvalidArgumentException('El tipo de caso no es válido.');
            }
            if (! in_array($c->estado, self::ESTADOS, true)) {
                throw new InvalidArgumentException('El estado del caso no es válido.');
            }
        });
        static::deleting(function () {
            throw new LogicException('Un caso de conciliación no se elimina desde la aplicación.');
        });
    }

    /** Ni el borrado individual (evento `deleting`) ni el borrado en lote (`Conciliacion::query()->delete()`) están permitidos. */
    public function newEloquentBuilder($query): ConstructorProtegido
    {
        return (new ConstructorProtegido($query))->protegiendo(true, false);
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoCertificacion::class, 'evento_id')->withTrashed();
    }

    public function certificados(): BelongsToMany
    {
        return $this->belongsToMany(CertificadoLegado::class, 'cf_conciliaciones_certificados', 'conciliacion_id', 'certificado_legado_id')->withPivot('rol')->withTimestamps();
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(ConciliacionEvento::class, 'conciliacion_id')->orderBy('id');
    }
}
