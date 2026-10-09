<?php

namespace App\Support\CredentialFlow\Legado;

/** Resultado de CongeladorCertificadoLegado::servir(): el PDF congelado y verificado. */
final class ArchivoCongelado
{
    public function __construct(
        /** Ruta relativa en el disco privado (RutasLegado::certificado). */
        public readonly string $ruta,
        public readonly string $sha256,
        public readonly int $bytes,
        /** true si esta llamada lo generó; false si ya existía y solo se verificó. */
        public readonly bool $recienGenerado,
    ) {}
}
