<?php

namespace App\Support\CredentialFlow\Reemplazo;

/**
 * Lo que un administrador aprueba para reemplazar un certificado histórico. NO incluye el certificado: sale del caso de conciliación (`certificadoId`
 * solo desambigua cuando un caso tiene varios y debe pertenecer a él). Los valores en claro (`valorDocumento`, `valorNombre`, `fecha`, …) viven
 * únicamente en esta solicitud y, después, en el participante y el snapshot de la emisión: la auditoría solo guarda regla + SHA-256 + longitud.
 */
final readonly class SolicitudReemplazo
{
    public function __construct(
        public int $plantillaId,
        public string $reglaDocumento,
        public ?string $valorDocumento = null,
        public string $reglaNombre = ReglasValorAprobado::NOMBRE_HISTORICO,
        public ?string $valorNombre = null,
        public bool $confirmado = false,
        public ?string $evidencia = null,
        public ?string $fecha = null,
        public ?string $intensidadHoraria = null,
        public ?int $certificadoId = null,
    ) {}
}
