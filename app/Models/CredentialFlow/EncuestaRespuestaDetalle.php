<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;

/** Una respuesta a una pregunta: lo que la persona contestó, con el snapshot de lo que vio (pregunta y opción). */
class EncuestaRespuestaDetalle extends Model
{
    protected $table = 'cf_encuestas_respuestas_detalle';

    protected $guarded = [];

    protected $casts = ['snapshot_pregunta' => 'array', 'valor_numero' => 'decimal:2'];
}
