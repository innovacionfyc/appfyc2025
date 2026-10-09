<?php

namespace App\Support\CredentialFlow\Migracion;

/** Resultado de MigradorHistorico::ejecutar(): una corrida nueva, o NO-OP si esas huellas ya estaban migradas. */
final class ResultadoMigracion
{
    /** @param array<string,mixed> $totales sin datos personales */
    public function __construct(
        public readonly bool $noOp,
        public readonly int $corridaId,
        public readonly array $totales,
        public readonly string $mensaje,
    ) {}
}
