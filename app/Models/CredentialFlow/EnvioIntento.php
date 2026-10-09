<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/** Un intento de envío: su resultado y, si falló, una clasificación técnica (nunca el mensaje de la excepción). */
class EnvioIntento extends Model
{
    public const ACEPTADO = 'aceptado';

    public const FALLIDO_TEMPORAL = 'fallido_temporal';

    public const FALLIDO_PERMANENTE = 'fallido_permanente';

    protected $table = 'cf_envios_intentos';

    protected $guarded = [];

    protected $casts = ['iniciado_at' => 'datetime', 'finalizado_at' => 'datetime'];

    public function envio(): BelongsTo
    {
        return $this->belongsTo(Envio::class, 'envio_id');
    }
}
