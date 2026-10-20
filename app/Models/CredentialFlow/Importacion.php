<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Borrador de la importación inteligente: lo que el analizador encontró en un Excel y lo que el administrador corrige antes de crear una base.
 * Un borrador NUNCA crea participantes, bases, eventos ni emisiones; solo la confirmación explícita lo hace.
 *
 * `analisis` y `correcciones` contienen datos personales: se guardan cifrados y se borran al terminar el borrador.
 */
class Importacion extends Model
{
    public const EN_REVISION = 'en_revision';

    public const CONFIRMADO = 'confirmado';

    public const EXPIRADO = 'expirado';

    public const RECHAZADO = 'rechazado';

    public const ESTADOS = [self::EN_REVISION, self::CONFIRMADO, self::EXPIRADO, self::RECHAZADO];

    protected $table = 'cf_importaciones';

    protected $fillable = [
        'usuario_id', 'estado', 'version', 'clave_activa', 'archivo_nombre', 'archivo_hash', 'archivo_bytes',
        'analisis', 'correcciones', 'resumen', 'advertencias', 'lote_id', 'confirmado_at', 'confirmado_por', 'rechazado_at', 'expira_at',
    ];

    protected $casts = [
        'version' => 'integer',
        'archivo_bytes' => 'integer',
        'analisis' => 'encrypted:array',
        'correcciones' => 'encrypted:array',
        'resumen' => 'array',
        'advertencias' => 'array',
        'confirmado_at' => 'datetime',
        'rechazado_at' => 'datetime',
        'expira_at' => 'datetime',
    ];

    /** Los datos personales nunca salen en un volcado accidental del modelo. */
    protected $hidden = ['analisis', 'correcciones'];

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class, 'lote_id');
    }

    public function enRevision(): bool
    {
        return $this->estado === self::EN_REVISION;
    }

    public function vencido(): bool
    {
        return $this->expira_at !== null && $this->expira_at->isPast();
    }

    public static function claveActiva(int $usuarioId, string $hash): string
    {
        return $usuarioId.':'.$hash;
    }
}
