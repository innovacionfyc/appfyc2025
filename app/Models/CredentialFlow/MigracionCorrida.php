<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;

/**
 * Una corrida de migración histórica: qué snapshot (SHA + huellas del staging) y cómo terminó. Los totales no llevan datos
 * personales. Una pareja de huellas con una corrida COMPLETADA no se migra otra vez; si se revierte, puede haber una nueva.
 */
class MigracionCorrida extends Model
{
    use HasAuditFields;

    public const TIPO_LEGADO = 'legado_evaluaciones';

    /** Corrida de las encuestas históricas (Fase 8): independiente de la de certificados, con su propio rollback. */
    public const TIPO_ENCUESTAS = 'legado_encuestas';

    public const ESTADO_PENDIENTE = 'pendiente';

    public const ESTADO_EJECUTANDO = 'ejecutando';

    public const ESTADO_COMPLETADA = 'completada';

    public const ESTADO_FALLIDA = 'fallida';

    public const ESTADO_REVERTIDA = 'revertida';

    public const ESTADOS = [self::ESTADO_PENDIENTE, self::ESTADO_EJECUTANDO, self::ESTADO_COMPLETADA, self::ESTADO_FALLIDA, self::ESTADO_REVERTIDA];

    protected $table = 'cf_migraciones_corridas';

    protected $fillable = [
        'tipo',
        'snapshot_sha256',
        'huella_global',
        'huella_derivada',
        'estado',
        'iniciado_at',
        'finalizado_at',
        'rollback_at',
        'totales',
        'error_codigo',
    ];

    protected $attributes = [
        'estado' => self::ESTADO_PENDIENTE,
    ];

    protected $casts = [
        'iniciado_at' => 'datetime',
        'finalizado_at' => 'datetime',
        'rollback_at' => 'datetime',
        'totales' => 'array',
    ];
}
