<?php

namespace App\Support\CredentialFlow\Legado;

/**
 * Resultado de la verificación pública de un código legado. Solo lo que puede mostrarse en público: evento, año y estado.
 * Nunca nombre, documento, correo, old_id, grupo, diferencias, corrida ni notas.
 */
final class ResultadoVerificacionLegado
{
    public const NO_ENCONTRADO = 'no_encontrado';

    /** Mismo código en pares (evento, documento) distintos: anomalía. Se responde como no verificable, sin revelar nada. */
    public const COLISION = 'colision';

    public const VALIDO = 'valido';

    public const EN_REVISION = 'en_revision';

    public const REVOCADO = 'revocado';

    public const REEMPLAZADO = 'reemplazado';

    public function __construct(
        public readonly string $estado,
        public readonly ?string $evento = null,
        public readonly ?int $anio = null,
        /** Código PÚBLICO de la emisión moderna que lo reemplaza (nunca ids internos). */
        public readonly ?string $codigoModerno = null,
        public readonly int $pares = 0,
    ) {}

    /** ¿Se muestra como «no encontrado» (404 uniforme)? */
    public function esInexistente(): bool
    {
        return in_array($this->estado, [self::NO_ENCONTRADO, self::COLISION], true);
    }
}
