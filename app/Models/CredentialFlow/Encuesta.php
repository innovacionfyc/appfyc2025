<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Definición de una encuesta (el «contenedor»): sus estructuras vivas en versiones. Ver migración cf_encuestas_tables. */
class Encuesta extends Model
{
    use HasAuditFields;

    public const ESTADO_BORRADOR = 'borrador';

    public const ESTADO_PUBLICADA = 'publicada';

    public const ESTADO_ARCHIVADA = 'archivada';

    public const ORIGEN_LEGADO = 'legado';

    public const ORIGEN_CREDENTIAL_FLOW = 'credential_flow';

    protected $table = 'cf_encuestas';

    protected $guarded = [];

    protected $casts = ['activa' => 'boolean'];

    public function versiones(): HasMany
    {
        return $this->hasMany(EncuestaVersion::class, 'encuesta_id')->orderBy('numero');
    }
}
