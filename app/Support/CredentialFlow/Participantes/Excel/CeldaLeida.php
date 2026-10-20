<?php

namespace App\Support\CredentialFlow\Participantes\Excel;

/**
 * Una celda con su valor ya resuelto y el ORIGEN de ese valor, para saber hasta dónde se puede confiar en él. No guarda el texto de la fórmula.
 *
 * Origen:
 *  - literal:                 valor escrito en la celda (no es fórmula);
 *  - formula_guardada:        fórmula con el resultado que el programa de origen dejó guardado (el más fiable);
 *  - formula_reserva:         sin resultado guardado, pero la envoltura IFERROR(__xludf.DUMMYFUNCTION(…), valor) trae el último valor conocido;
 *  - formula_vacia:           fórmula cuyo resultado guardado (o de reserva) es, comprobado, un texto vacío;
 *  - formula_vacia_contexto:  fórmula sin resultado en una fila donde NO hay ningún dato (relleno de la plantilla): vacía por comprobación de la fila;
 *  - formula_error:           fórmula cuyo resultado guardado es un error (#REF!, #N/A…);
 *  - formula_sin_valor:       fórmula sin resultado ni reserva en una fila que sí tiene datos: el dato NO se pudo recuperar.
 */
final readonly class CeldaLeida
{
    public const TEXTO = 'texto';

    public const NUMERO = 'numero';

    public const BOOLEANO = 'booleano';

    public const FECHA = 'fecha';

    public const DURACION = 'duracion';

    public const VACIA = 'vacia';

    public const ERROR = 'error';

    public const LITERAL = 'literal';

    public const FORMULA_GUARDADA = 'formula_guardada';

    public const FORMULA_RESERVA = 'formula_reserva';

    public const FORMULA_VACIA = 'formula_vacia';

    public const FORMULA_VACIA_CONTEXTO = 'formula_vacia_contexto';

    public const FORMULA_ERROR = 'formula_error';

    public const FORMULA_SIN_VALOR = 'formula_sin_valor';

    public function __construct(
        public int $fila,                       // 1 = primera fila de la hoja
        public int $columna,                    // 0 = columna A
        public string|int|float|bool|null $valor,
        public string $tipo,
        public string $origen,
        public ?string $funcion = null,         // primera función de la fórmula (diagnóstico); nunca el texto completo
        public ?string $error = null,           // código de error del resultado (#REF!, #N/A…)
        public bool $importrange = false,
        public bool $discrepancia = false,      // el valor guardado y el de reserva difieren (se conserva el guardado)
    ) {}

    public function esFormula(): bool
    {
        return $this->origen !== self::LITERAL;
    }

    /** ¿Aporta un dato utilizable? (no vacía, no error, no fórmula sin resultado) */
    public function tieneDato(): bool
    {
        return ! in_array($this->tipo, [self::VACIA, self::ERROR], true) && $this->valor !== null && $this->valor !== '';
    }

    public function comoVaciaPorContexto(): self
    {
        return new self($this->fila, $this->columna, null, self::VACIA, self::FORMULA_VACIA_CONTEXTO, $this->funcion, null, $this->importrange, false);
    }

    /** Nombre de la columna en notación de hoja de cálculo (A, B… AA). */
    public function referencia(): string
    {
        $c = $this->columna;
        $letras = '';
        do {
            $letras = chr(65 + $c % 26).$letras;
            $c = intdiv($c, 26) - 1;
        } while ($c >= 0);

        return $letras.$this->fila;
    }
}
