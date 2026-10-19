<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lote de participantes de Credential Flow: una importación (o alta manual) asociada a una plantilla,
 * con los datos comunes (evento, fecha, intensidad horaria) guardados como snapshot.
 */
class Lote extends Model
{
    use HasAuditFields, SoftDeletes;

    protected $table = 'cf_lotes';

    /** Estado OPERATIVO de la base (solo organiza el listado; no afecta emisiones ni descargas). Todas las bases existentes quedan «activa». */
    public const ACTIVA = 'activa';

    public const BORRADOR = 'borrador';

    public const ARCHIVADA = 'archivada';

    public const PRUEBA = 'prueba';

    public const ESTADOS_OPERATIVOS = [self::ACTIVA, self::BORRADOR, self::ARCHIVADA, self::PRUEBA];

    protected $fillable = [
        'plantilla_id',
        'evento_id',
        'estado_operativo',
        'vista_previa_generada_at',
        'vista_previa_confirmada_at',
        'vista_previa_plantilla_id',
        'vista_previa_huella',
        'nombre',
        'descripcion',
        'datos_comunes',
        'archivo_nombre',
        'archivo_hash',
    ];

    protected $casts = [
        'datos_comunes' => 'array',
        'vista_previa_generada_at' => 'datetime',
        'vista_previa_confirmada_at' => 'datetime',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(Plantilla::class);
    }

    /** Evento de certificación al que pertenece la base (null en las bases anteriores a los eventos). */
    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoCertificacion::class, 'evento_id')->withTrashed();
    }

    public function emisiones(): HasMany
    {
        return $this->hasMany(Emision::class);
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(Participante::class);
    }
}
