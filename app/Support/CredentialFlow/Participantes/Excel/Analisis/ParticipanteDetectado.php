<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

/**
 * Una fila de la tabla reconocida como participante, con cada dato y su trazabilidad.
 *
 * `senal`: lo que dice el archivo sobre la asistencia, SIN decidir la elegibilidad (eso lo confirma una persona después):
 *  completo | cupo | conflicto | desconocida | sin_senal.
 * `diploma` solo informa lo que marca el archivo: nunca autoriza ni impide emitir un certificado.
 */
final readonly class ParticipanteDetectado
{
    public const SENAL_COMPLETO = 'completo';

    public const SENAL_CUPO = 'cupo';

    public const SENAL_CONFLICTO = 'conflicto';

    public const SENAL_DESCONOCIDA = 'desconocida';

    public const SENAL_NINGUNA = 'sin_senal';

    /** @param  array<string, ValorDetectado>  $campos */
    public function __construct(
        public int $fila,
        public array $campos,
        public string $senal,
        public ?bool $diploma,
        public ?string $posibleQa = null,      // correo | documento: coincide con un contacto QA permanente (informativo)
    ) {}

    public function campo(string $campo): ?ValorDetectado
    {
        return $this->campos[$campo] ?? null;
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'fila' => $this->fila, 'senal' => $this->senal, 'diploma' => $this->diploma, 'posible_qa' => $this->posibleQa,
            'campos' => array_map(fn (ValorDetectado $v) => $v->toArray(), $this->campos),
        ];
    }
}
