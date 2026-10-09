<?php

namespace App\Support\CredentialFlow\Legado;

use DateTimeImmutable;

/**
 * Todo lo que el renderer necesita y NADA más (sin Eloquent, sin reloj, sin azar): mismo input ⇒ mismos bytes.
 * Los textos son los valores del snapshot TAL COMO estaban en el sistema viejo (el renderer no los limpia ni los mejora).
 */
final class SolicitudRender
{
    public function __construct(
        public readonly PlantillaImagen $plantilla,
        public readonly string $nombre,
        public readonly ?string $tipoDocumento,
        public readonly ?string $documento,
        public readonly ?string $codigoLegado,
        /** Fecha de creación que se escribe en el PDF: derivada del snapshot (nunca «ahora»). */
        public readonly DateTimeImmutable $fechaCreacion,
    ) {}
}
