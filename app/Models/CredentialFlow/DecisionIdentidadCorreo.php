<?php

namespace App\Models\CredentialFlow;

use App\Models\CredentialFlow\Concerns\ConstructorProtegido;
use Illuminate\Database\Eloquent\Model;
use LogicException;

/** Correo (solo HMAC y máscara) autorizado para un grupo concreto. Nunca se elimina. */
class DecisionIdentidadCorreo extends Model
{
    protected $table = 'cf_decisiones_identidad_correos';

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
