<?php

namespace App\Support\CredentialFlow\Programacion;

/**
 * Boleto del ejecutor automático para UNA programación que reservó. El boleto es un secreto aleatorio que solo vive en la memoria del proceso que hizo la
 * reserva; en la base se guarda únicamente su SHA-256 (`reclamo_hash`). El candado de la emisión manual solo deja pasar a quien presente el boleto correcto de
 * una programación en estado «ejecutando»: ninguna petición web ni otro proceso lo tiene, así que la excepción del candado no se puede usar desde fuera.
 */
final class EjecucionAutorizada
{
    private function __construct(
        public readonly int $programacionId,
        private readonly string $boleto,
    ) {}

    /** Un boleto nuevo para reservar la programación (se guarda su hash con `hash()`). */
    public static function nueva(int $programacionId): self
    {
        return new self($programacionId, bin2hex(random_bytes(32)));
    }

    public function hash(): string
    {
        return hash('sha256', $this->boleto);
    }

    public function corresponde(?string $hashGuardado): bool
    {
        return is_string($hashGuardado) && hash_equals($hashGuardado, $this->hash());
    }
}
