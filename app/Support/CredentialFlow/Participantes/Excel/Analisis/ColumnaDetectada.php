<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

/** Una columna de la tabla de participantes reconocida (o propuesta) para un campo, con el encabezado original y otras columnas que también podrían serlo. */
final readonly class ColumnaDetectada
{
    /** @param  list<array{letra: string, encabezado: string}>  $alternativas */
    public function __construct(
        public string $campo,
        public int $indice,
        public string $letra,
        public string $encabezado,
        public string $metodo,       // exacto | aproximado
        public string $confianza,    // confiable | ambiguo
        public array $alternativas = [],
    ) {}

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return ['campo' => $this->campo, 'indice' => $this->indice, 'letra' => $this->letra, 'encabezado' => $this->encabezado, 'metodo' => $this->metodo, 'confianza' => $this->confianza, 'alternativas' => $this->alternativas];
    }
}
