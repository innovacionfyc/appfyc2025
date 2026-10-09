<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\TieneCorreos;
use App\Models\Usuario;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use LogicException;

/**
 * Certificado histórico del sistema viejo (participante + certificado en una fila). Separado de Participante y de
 * Emision para no debilitar las garantías de cf_emisiones. El PDF congelado (pdf_*) es NULL hasta el primer acceso.
 * Esta fase solo define el modelo: no hay generación, congelado ni verificación todavía.
 */
class CertificadoLegado extends Model
{
    use HasAuditFields, TieneCorreos;

    public const ESTADO_VIGENTE = 'vigente';

    public const ESTADO_REVOCADO = 'revocado';

    public const ESTADO_REEMPLAZADO = 'reemplazado';

    public const ESTADOS = [self::ESTADO_VIGENTE, self::ESTADO_REVOCADO, self::ESTADO_REEMPLAZADO];

    public const CONCILIACION_OK = 'ok';

    public const CONCILIACION_DUPLICADO_CONSOLIDADO = 'duplicado_consolidado';

    public const CONCILIACION_PENDIENTE = 'pendiente_conciliacion';

    public const CONCILIACION_REVISION_DOCUMENTO = 'revision_documento';

    public const CONCILIACION_PENDIENTE_PLANTILLA = 'pendiente_plantilla';

    public const CONCILIACIONES = [
        self::CONCILIACION_OK,
        self::CONCILIACION_DUPLICADO_CONSOLIDADO,
        self::CONCILIACION_PENDIENTE,
        self::CONCILIACION_REVISION_DOCUMENTO,
        self::CONCILIACION_PENDIENTE_PLANTILLA,
    ];

    /** Estados de `correo_estado`: los mismos que en cf_participantes. */
    public const CORREO_ESTADOS = Participante::CORREO_ESTADOS;

    protected $table = 'cf_certificados_legado';

    protected $fillable = [
        'corrida_id',
        'evento_id',
        'plantilla_legado_id',
        'tipo_documento',
        'documento',
        'documento_clave',
        'nombre_completo',
        'correo',
        'correo_normalizado',
        'correo_estado',
        'codigo_legado',
        'snapshot_legado',
        'pdf_archivo',
        'pdf_hash',
        'pdf_bytes',
        'materializado_at',
        'estado',
        'conciliacion_estado',
        'grupo_duplicado',
        'visible_portal',
        'intentos_generacion',
        'ultimo_error_codigo',
        'reemplazado_por_emision_id',
        'revocado_at',
        'revocado_por',
        'motivo_revocacion',
    ];

    protected $attributes = [
        'estado' => self::ESTADO_VIGENTE,
        'conciliacion_estado' => self::CONCILIACION_PENDIENTE,
        'visible_portal' => false,
        'intentos_generacion' => 0,
    ];

    protected $casts = [
        'snapshot_legado' => 'array',
        'pdf_bytes' => 'integer',
        'materializado_at' => 'datetime',
        'visible_portal' => 'boolean',
        'intentos_generacion' => 'integer',
        'revocado_at' => 'datetime',
    ];

    /**
     * Es histórico preservado: NO existe eliminación administrativa (ni por modelo ni por pantalla). Se retira revocándolo.
     * Solo un mecanismo técnico y controlado de rollback de una corrida de migración podrá borrarlo, con consultas directas.
     */
    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('Un certificado histórico no se puede eliminar; se revoca.');
        });
    }

    public function evento(): BelongsTo
    {
        return $this->belongsTo(EventoCertificacion::class, 'evento_id')->withTrashed();
    }

    public function plantillaLegado(): BelongsTo
    {
        return $this->belongsTo(PlantillaLegado::class, 'plantilla_legado_id');
    }

    /** Emisión moderna que sustituye a este certificado histórico (corrección). */
    public function reemplazadoPor(): BelongsTo
    {
        return $this->belongsTo(Emision::class, 'reemplazado_por_emision_id');
    }

    public function revocador(): BelongsTo
    {
        return $this->belongsTo(Usuario::class, 'revocado_por');
    }

    /** Todos los correos del certificado (cf_correos): en el legado se conservan todos, sin elegir uno. */
    public function correos(): HasMany
    {
        return $this->hasMany(Correo::class, 'certificado_legado_id')->orderBy('orden')->orderBy('id');
    }

    public function descargas(): HasMany
    {
        return $this->hasMany(Descarga::class, 'certificado_legado_id');
    }

    /** ¿Ya tiene su PDF congelado? */
    public function materializado(): bool
    {
        return $this->materializado_at !== null;
    }
}
