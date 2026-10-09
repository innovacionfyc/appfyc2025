<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use InvalidArgumentException;

/**
 * Envío lógico de un correo (Fase 9). `aceptado_por_transporte` NO significa «entregado en la bandeja»: solo que el servidor de correo
 * aceptó el mensaje. No guarda el OTP, el cuerpo, el documento ni la dirección completa (solo HMAC y máscara).
 */
class Envio extends Model
{
    public const PENDIENTE = 'pendiente';

    public const PROCESANDO = 'procesando';

    public const ACEPTADO = 'aceptado_por_transporte';

    public const FALLIDO_TEMPORAL = 'fallido_temporal';

    public const FALLIDO_PERMANENTE = 'fallido_permanente';

    public const ESTADOS = [self::PENDIENTE, self::PROCESANDO, self::ACEPTADO, self::FALLIDO_TEMPORAL, self::FALLIDO_PERMANENTE];

    public const TIPO_OTP = 'otp_acceso';

    protected $table = 'cf_envios';

    protected $guarded = [];

    protected $casts = ['solicitado_at' => 'datetime', 'primer_intento_at' => 'datetime', 'ultimo_intento_at' => 'datetime', 'aceptado_at' => 'datetime'];

    protected static function booted(): void
    {
        static::saving(function (Envio $e) {
            if (! in_array($e->estado, self::ESTADOS, true)) {
                throw new InvalidArgumentException('El estado del envío no es válido.');
            }
        });
    }

    public function intentosRegistrados(): HasMany
    {
        return $this->hasMany(EnvioIntento::class, 'envio_id')->orderBy('numero');
    }
}
