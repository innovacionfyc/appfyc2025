<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\TieneCorreos;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Participante extends Model
{
    use HasAuditFields, SoftDeletes, TieneCorreos;

    /**
     * Estados de `correo_estado` (compartidos con CertificadoLegado). `multiple` = más de un correo válido: entonces
     * `correo_normalizado` queda NULL y la fuente de verdad son las filas de cf_correos.
     */
    public const CORREO_VALIDO = 'valido';

    public const CORREO_MULTIPLE = 'multiple';

    public const CORREO_INVALIDO = 'invalido';

    public const CORREO_SIN_CORREO = 'sin_correo';

    public const CORREO_ESTADOS = [self::CORREO_VALIDO, self::CORREO_MULTIPLE, self::CORREO_INVALIDO, self::CORREO_SIN_CORREO];

    /** Elegibilidad para certificación: solo los ELEGIBLES entran a una emisión oficial. */
    public const ELEGIBLE = 'elegible';

    public const PENDIENTE = 'pendiente';

    public const NO_ELEGIBLE = 'no_elegible';

    public const ELEGIBILIDADES = [self::ELEGIBLE, self::PENDIENTE, self::NO_ELEGIBLE];

    protected $table = 'cf_participantes';

    protected $fillable = [
        'lote_id',
        'nombre_completo',
        'documento',
        'documento_clave',
        'correo',
        'correo_normalizado',
        'correo_estado',
        'fila_origen',
        'es_control_qa',
        'elegibilidad',
        'intensidad_horaria',
        'modalidad',
        'elegibilidad_motivo',
        'correo_actualizado_at',
        'tipo_documento_confirmado',
    ];

    protected $casts = [
        'es_control_qa' => 'boolean',
        'correo_actualizado_at' => 'datetime',
        'tipo_documento_confirmado' => 'boolean',
    ];

    public function emisiones(): HasMany
    {
        return $this->hasMany(Emision::class);
    }

    /** La emisión vigente (como máximo una; lo garantiza el índice único participante_vigente). */
    public function emisionVigente(): HasOne
    {
        return $this->hasOne(Emision::class)->where('estado', Emision::EMITIDA);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }

    /** Todos los correos del participante (cf_correos), en su orden. */
    public function correos(): HasMany
    {
        return $this->hasMany(Correo::class, 'participante_id')->orderBy('orden')->orderBy('id');
    }
}
