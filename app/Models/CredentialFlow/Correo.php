<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Correo asociado a EXACTAMENTE un propietario: un participante nativo o un certificado histórico. La base de datos lo
 * garantiza con un CHECK en MySQL/MariaDB; esta validación es la segunda barrera (y la única en SQLite).
 *
 * `correo` conserva la dirección original; `correo_normalizado` (trim + minúsculas) se calcula aquí y sirve para comparar.
 * No hay fila para «sin correo»: simplemente no existen filas. El principal es opcional (como máximo uno por propietario,
 * siempre válido) y la búsqueda por documento + correo debe mirar TODOS los correos válidos, no solo el principal.
 */
class Correo extends Model
{
    use HasAuditFields;

    public const ESTADO_VALIDO = 'valido';

    public const ESTADO_INVALIDO = 'invalido';

    public const ESTADOS = [self::ESTADO_VALIDO, self::ESTADO_INVALIDO];

    public const ORIGEN_CREDENTIAL_FLOW = 'credential_flow';

    public const ORIGEN_LEGADO = 'legado';

    public const ORIGENES = [self::ORIGEN_CREDENTIAL_FLOW, self::ORIGEN_LEGADO];

    protected $table = 'cf_correos';

    protected $fillable = [
        'corrida_id',
        'participante_id',
        'certificado_legado_id',
        'correo',
        'correo_normalizado',
        'estado',
        'orden',
        'es_principal',
        'origen',
    ];

    protected $attributes = [
        'orden' => 1,
        'es_principal' => false,
    ];

    protected $casts = [
        'orden' => 'integer',
        'es_principal' => 'boolean',
    ];

    /** Limpio y en minúsculas. Es lo único que se compara; la dirección original no se toca. */
    public static function normalizar(string $correo): string
    {
        return mb_strtolower(preg_replace('/^[\s\x{00A0}]+|[\s\x{00A0}]+$/u', '', $correo) ?? $correo, 'UTF-8');
    }

    /** valido | invalido, a partir del correo ya normalizado (misma regla que el staging histórico). */
    public static function estadoDe(string $normalizado): string
    {
        if ($normalizado === '' || mb_strlen($normalizado) > 254 || preg_match('/\s/u', $normalizado) === 1) {
            return self::ESTADO_INVALIDO;
        }
        $arroba = strrpos($normalizado, '@');
        $dominio = $arroba === false ? '' : substr($normalizado, $arroba + 1);

        // Exige un punto en el dominio (user@localhost no sirve para escribirle a una persona).
        return filter_var($normalizado, FILTER_VALIDATE_EMAIL) !== false && str_contains($dominio, '.')
            ? self::ESTADO_VALIDO
            : self::ESTADO_INVALIDO;
    }

    protected static function booted(): void
    {
        static::saving(function (Correo $correo) {
            $conParticipante = $correo->participante_id !== null;
            $conLegado = $correo->certificado_legado_id !== null;

            if ($conParticipante === $conLegado) {
                throw new InvalidArgumentException('Un correo debe pertenecer a un participante o a un certificado histórico, y solo a uno.');
            }
            if (! in_array($correo->origen, self::ORIGENES, true)) {
                throw new InvalidArgumentException('El origen del correo no es válido.');
            }
            if (trim((string) $correo->correo) === '') {
                throw new InvalidArgumentException('El correo no puede estar vacío: sin correo simplemente no hay fila.');
            }

            // Derivado: siempre coincide con la dirección original.
            $correo->correo_normalizado = self::normalizar((string) $correo->correo);

            $calculado = self::estadoDe($correo->correo_normalizado);
            if ($correo->estado === null) {
                $correo->estado = $calculado;
            } elseif (! in_array($correo->estado, self::ESTADOS, true)) {
                throw new InvalidArgumentException('El estado del correo no es válido.');
            } elseif ($correo->estado === self::ESTADO_VALIDO && $calculado !== self::ESTADO_VALIDO) {
                throw new InvalidArgumentException('Un correo con formato inválido no puede marcarse como válido.');
            }

            if ($correo->es_principal) {
                if ($correo->estado !== self::ESTADO_VALIDO) {
                    throw new InvalidArgumentException('Solo un correo válido puede ser el principal.');
                }
                $otro = self::query()
                    ->where($conParticipante ? 'participante_id' : 'certificado_legado_id', $conParticipante ? $correo->participante_id : $correo->certificado_legado_id)
                    ->where('es_principal', true)
                    ->when($correo->exists, fn ($q) => $q->where('id', '!=', $correo->id))
                    ->exists();
                if ($otro) {
                    throw new InvalidArgumentException('Ese propietario ya tiene un correo principal.');
                }
            }
        });
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(Participante::class)->withTrashed();
    }

    public function certificadoLegado(): BelongsTo
    {
        return $this->belongsTo(CertificadoLegado::class, 'certificado_legado_id');
    }
}
