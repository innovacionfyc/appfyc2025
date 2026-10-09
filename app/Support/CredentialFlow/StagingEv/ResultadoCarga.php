<?php

namespace App\Support\CredentialFlow\StagingEv;

/** Resultado de cargar un snapshot en el staging. */
final readonly class ResultadoCarga
{
    /**
     * @param  array<string,array{nuevas:int,iguales:int,cambiadas:int,ausentes:int,origen:int}>  $entidades
     */
    public function __construct(
        public int $snapshotId,
        public bool $yaCargado,
        public array $entidades = [],
    ) {}
}
