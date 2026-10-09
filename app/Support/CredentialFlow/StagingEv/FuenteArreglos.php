<?php

namespace App\Support\CredentialFlow\StagingEv;

/** Fuente en memoria (pruebas con datos sintéticos). */
final class FuenteArreglos implements FuenteSnapshot
{
    /** @param array<string,array<int,array<string,mixed>>> $tablas */
    public function __construct(private readonly array $tablas = [], private readonly ?string $esquema = null) {}

    public function filas(string $tabla): iterable
    {
        $filas = $this->tablas[$tabla] ?? [];
        usort($filas, fn ($a, $b) => (int) $a['id'] <=> (int) $b['id']);

        yield from $filas;
    }

    public function conteos(): array
    {
        $r = [];
        foreach (self::TABLAS as $tabla) {
            $r[$tabla] = count($this->tablas[$tabla] ?? []);
        }

        return $r;
    }

    public function esquemaSha256(): ?string
    {
        return $this->esquema;
    }
}
