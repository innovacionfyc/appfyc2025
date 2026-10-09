<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\ConstructorProtegido;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use LogicException;

/** Bitácora APPEND-ONLY de un caso: se inserta y nunca se modifica ni se borra desde la aplicación. */
class ConciliacionEvento extends Model
{
    public const DETECTADO = 'detectado';

    public const UPDATED_AT = null;

    protected $table = 'cf_conciliaciones_eventos';

    protected $guarded = [];

    protected $casts = ['evidencia' => 'array', 'created_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Los eventos de conciliación son de solo añadir: no se modifican.');
        });
        static::deleting(function () {
            throw new LogicException('Los eventos de conciliación son de solo añadir: no se eliminan.');
        });
    }

    /** Tampoco en lote: `$caso->eventos()->delete()` o `->update()` se niegan. */
    public function newEloquentBuilder($query): ConstructorProtegido
    {
        return (new ConstructorProtegido($query))->protegiendo(true, true);
    }

    public function conciliacion(): BelongsTo
    {
        return $this->belongsTo(Conciliacion::class, 'conciliacion_id');
    }
}
