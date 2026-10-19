<?php

namespace App\Support\CredentialFlow\Eventos;

use App\Models\CredentialFlow\EventoCertificacion;
use Illuminate\Support\Collection;
use RuntimeException;

/** La base podría pertenecer a más de un evento (o a ninguno con certeza): el administrador debe elegir el evento o confirmar que es uno nuevo. */
final class AsociacionAmbiguaException extends RuntimeException
{
    /**
     * @param  list<string>  $motivos
     * @param  Collection<int,EventoCertificacion>  $candidatos
     */
    public function __construct(public readonly array $motivos, public readonly Collection $candidatos)
    {
        parent::__construct(implode(' ', $motivos).' Elige el evento al que pertenece o confirma que es un evento nuevo.');
    }
}
