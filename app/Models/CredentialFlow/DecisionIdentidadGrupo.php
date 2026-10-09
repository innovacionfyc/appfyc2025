<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\ConstructorProtegido;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Un `grupo_hash` técnico abarcado por una decisión (misma persona / personas distintas). Nunca se elimina. */
class DecisionIdentidadGrupo extends Model
{
    protected $table = 'cf_decisiones_identidad_grupos';

    protected $guarded = [];

    protected static function booted(): void
    {
        static::deleting(function () {
            throw new LogicException('El detalle de una decisión de identidad no se elimina.');
        });
    }

    public function newEloquentBuilder($query): ConstructorProtegido
    {
        return (new ConstructorProtegido($query))->protegiendo(true, false);
    }
}
