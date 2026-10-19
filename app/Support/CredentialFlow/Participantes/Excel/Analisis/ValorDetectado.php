<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

use App\Support\CredentialFlow\Participantes\Excel\CeldaLeida;

/**
 * Un dato propuesto por el análisis, con TODA su trazabilidad: de qué hoja, fila y columna salió, el texto original, la transformación aplicada y qué tan
 * seguro es. Nada se escribe en ningún sitio: es solo una propuesta que una persona revisa y corrige.
 *
 * Confianza:
 *  - confiable: rótulo o encabezado exacto, un único candidato, valor presente y sin interpretación dudosa;
 *  - ambiguo:   hay más de un candidato, el rótulo es aproximado, el formato admite varias lecturas o el valor no se pudo interpretar con certeza;
 *  - faltante:  no se encontró, está vacío o su fórmula no tiene resultado recuperable.
 */
final readonly class ValorDetectado
{
    public const CONFIABLE = 'confiable';

    public const AMBIGUO = 'ambiguo';

    public const FALTANTE = 'faltante';

    /**
     * @param  list<string>  $motivos
     * @param  list<array{valor: string, referencia: string}>  $alternativas  otros candidatos (cuando es ambiguo)
     * @param  array<string, mixed>  $extra  datos auxiliares de la propuesta (p. ej. la fecha en formato ISO)
     */
    public function __construct(
        public string $campo,
        public string|int|float|bool|null $valor,
        public ?string $original,
        public ?string $transformacion,
        public string $confianza,
        public array $motivos = [],
        public ?string $hoja = null,
        public ?int $fila = null,
        public ?int $columna = null,
        public ?string $referencia = null,
        public ?string $origenCelda = null,
        public bool $desactualizable = false,
        public array $alternativas = [],
        public array $extra = [],
        public ?string $etiqueta = null,
    ) {}

    /** @param  list<string>  $motivos */
    public static function faltante(string $campo, array $motivos, ?string $hoja = null, ?CeldaLeida $celda = null, ?string $etiqueta = null): self
    {
        return new self($campo, null, null, null, self::FALTANTE, $motivos, $hoja, $celda?->fila, $celda?->columna, $celda?->referencia(), $celda?->origen, false, [], [], $etiqueta);
    }

    /** Una fórmula cuyo resultado guardado pudo quedar viejo (importada de otra hoja, valor de reserva o con diferencias). */
    public static function esDesactualizable(?CeldaLeida $c): bool
    {
        return $c !== null && $c->esFormula() && ($c->importrange || $c->origen === CeldaLeida::FORMULA_RESERVA || $c->discrepancia);
    }

    /** Una copia con otra confianza y motivos añadidos (para propagar la duda de una columna o un rótulo aproximados). */
    public function conDuda(string $motivo): self
    {
        if ($this->confianza === self::FALTANTE) {
            return $this;
        }

        return new self($this->campo, $this->valor, $this->original, $this->transformacion, self::AMBIGUO, [...$this->motivos, $motivo], $this->hoja, $this->fila, $this->columna, $this->referencia, $this->origenCelda, $this->desactualizable, $this->alternativas, $this->extra, $this->etiqueta);
    }

    /** @return array<string,mixed> */
    public function toArray(): array
    {
        return [
            'campo' => $this->campo, 'valor' => $this->valor, 'original' => $this->original, 'transformacion' => $this->transformacion,
            'confianza' => $this->confianza, 'motivos' => $this->motivos, 'hoja' => $this->hoja, 'fila' => $this->fila, 'columna' => $this->columna,
            'referencia' => $this->referencia, 'origen_celda' => $this->origenCelda, 'desactualizable' => $this->desactualizable,
            'alternativas' => $this->alternativas, 'extra' => $this->extra, 'etiqueta' => $this->etiqueta,
        ];
    }
}
