<?php

namespace App\Support\CredentialFlow\Participantes;

/** Resultado de validar un participante (una fila del archivo o el formulario manual). */
final readonly class ResultadoFila
{
    /**
     * @param  array<int,ErrorFila>  $errores
     * @param  array<int,ErrorFila>  $avisos
     */
    public function __construct(
        public ?string $nombre,
        public ?string $documento,
        public ?string $clave,
        public string $nombreVista,
        public string $documentoVista,
        public array $errores,
        public array $avisos,
    ) {}

    public function valido(): bool
    {
        return $this->errores === [];
    }
}
