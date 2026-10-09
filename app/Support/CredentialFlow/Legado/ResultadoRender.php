<?php

namespace App\Support\CredentialFlow\Legado;

/** El PDF generado (en memoria; el renderer NO lo guarda) y su huella. */
final class ResultadoRender
{
    /** @param array<string,mixed> $metadata datos técnicos SIN datos personales */
    public function __construct(
        public readonly string $pdf,
        public readonly string $sha256,
        public readonly int $bytes,
        public readonly array $metadata,
    ) {}
}
