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
