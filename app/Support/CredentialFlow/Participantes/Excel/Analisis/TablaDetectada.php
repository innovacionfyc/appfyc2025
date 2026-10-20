<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

/** La tabla de participantes elegida: dónde está, qué columnas se reconocieron y quiénes son. */
final readonly class TablaDetectada
{
    /**
     * @param  array<string, ColumnaDetectada>  $columnas
     * @param  list<ParticipanteDetectado>  $participantes
     * @param  list<string>  $encabezadosNoReconocidos  textos de encabezado ignorados (no son datos de personas)
     */
    public function __construct(
        public string $hoja,
        public int $filaEncabezado,
        public array $columnas,
        public array $participantes,
        public int $filasOmitidas,
        public bool $truncada,
        public string $confianza,
        public array $encabezadosNoReconocidos = [],
        public int $filaFin = 0,
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'hoja' => $this->hoja, 'fila_encabezado' => $this->filaEncabezado, 'fila_fin' => $this->filaFin, 'confianza' => $this->confianza,
            'filas_omitidas' => $this->filasOmitidas, 'truncada' => $this->truncada, 'encabezados_no_reconocidos' => $this->encabezadosNoReconocidos,
            'columnas' => array_map(fn (ColumnaDetectada $c) => $c->toArray(), $this->columnas),
            'participantes' => array_map(fn (ParticipanteDetectado $p) => $p->toArray(), $this->participantes),
        ];
    }
}
