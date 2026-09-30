<?php

namespace App\Support\CredentialFlow\Participantes;

/** Celda tal como sale del archivo: su valor tipado y si era una fórmula (que NUNCA se evalúa). */
final readonly class CeldaCruda
{
    public function __construct(public mixed $valor = null, public bool $formula = false) {}

    public static function texto(?string $valor): self
    {
        return new self($valor);
    }

    public function vacia(): bool
    {
        if ($this->formula) {
            return false;
        }

        return $this->valor === null || (is_string($this->valor) && trim($this->valor) === '');
    }
}
