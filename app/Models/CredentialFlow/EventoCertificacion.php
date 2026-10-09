<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Evento de certificación (histórico o nuevo). No es App\Models\Evento (marketing): aquí solo se agrupan bases y
 * certificados. El id del sistema viejo vivirá en el mapa de migración, no en esta tabla.
 */
class EventoCertificacion extends Model
{
    use HasAuditFields, SoftDeletes;

    public const ESTADO_BORRADOR = 'borrador';

    public const ESTADO_ACTIVO = 'activo';

    public const ESTADO_CERRADO = 'cerrado';

    public const ESTADO_ARCHIVADO = 'archivado';

    public const ESTADOS = [self::ESTADO_BORRADOR, self::ESTADO_ACTIVO, self::ESTADO_CERRADO, self::ESTADO_ARCHIVADO];

    public const ORIGEN_CREDENTIAL_FLOW = 'credential_flow';

    public const ORIGEN_LEGADO = 'legado';

    public const ORIGENES = [self::ORIGEN_CREDENTIAL_FLOW, self::ORIGEN_LEGADO];

    protected $table = 'cf_eventos';

    protected $fillable = [
        'corrida_id',
        'nombre',
        'nombre_normalizado',
        'anio',
        'fecha_inicio',
        'fecha_fin',
        'fecha_texto',
        'estado',
        'origen',
        'plantilla_legado_id',
        'notas',
    ];

    protected $casts = [
        'anio' => 'integer',
        'fecha_inicio' => 'date',
        'fecha_fin' => 'date',
    ];

    /** Imagen de fondo con la que el sistema viejo conocía este evento (entrada del catálogo; null si no tiene). */
    public function plantillaLegado(): BelongsTo
    {
        return $this->belongsTo(PlantillaLegado::class, 'plantilla_legado_id');
    }

    /** Bases de participantes de este evento (un evento puede tener varias). */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class, 'evento_id');
    }

    public function certificadosLegado(): HasMany
    {
        return $this->hasMany(CertificadoLegado::class, 'evento_id');
    }
}
