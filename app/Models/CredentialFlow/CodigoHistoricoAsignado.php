<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\ConstructorProtegido;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/**
 * Código histórico asignado por Credential Flow (Fase 10B-1.5). INMUTABLE: nunca cambia, nunca se recicla y nunca se borra desde la
 * aplicación (ni individualmente ni en lote). Permanece aunque el certificado se revoque o se reemplace.
 */
class CodigoHistoricoAsignado extends Model
{
    public const ORIGEN = 'credential_flow';

    protected $table = 'cf_codigos_historicos';

    protected $guarded = [];

    protected $casts = ['asignado_at' => 'datetime'];

    protected static function booted(): void
    {
        static::updating(function () {
            throw new LogicException('Un código histórico asignado es inmutable: no se modifica.');
        });
        static::deleting(function () {
            throw new LogicException('Un código histórico asignado es inmutable: no se elimina.');
        });
    }

    public function newEloquentBuilder($query): ConstructorProtegido
    {
        return (new ConstructorProtegido($query))->protegiendo(true, true);
    }
}
