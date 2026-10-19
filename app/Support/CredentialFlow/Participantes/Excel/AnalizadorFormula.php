<?php

namespace App\Support\CredentialFlow\Participantes\Excel;

/**
 * Análisis CONTROLADO de una fórmula de celda. Nunca se evalúa nada: ni funciones, ni referencias a otras celdas u otros libros, ni IMPORTRANGE. Solo se
 * reconoce, con un analizador lineal escrito a mano (sin expresiones regulares sobre el contenido, sin riesgo de backtracking), la envoltura exacta que
 * exporta Google Sheets cuando el libro se descarga como .xlsx:
 *
 *     =IFERROR(__xludf.DUMMYFUNCTION("<fórmula original>"), <valor literal de reserva>)
 *
 * donde el valor de reserva es el último valor conocido de la celda: un texto entre comillas (con "" como comilla escapada), un número, TRUE/FALSE o vacío.
 * Cualquier otra forma (funciones anidadas, concatenaciones, operaciones, referencias) devuelve `null`: la celda queda «sin valor recuperable».
 */
final class AnalizadorFormula
{
    /** Una fórmula más larga que esto no se analiza (protección frente a entradas hostiles). */
    public const LONGITUD_MAX = 32768;

    /** Tamaño máximo del literal de reserva que se acepta. */
    public const LITERAL_MAX = 8192;

    /**
     * Valor literal de reserva de una envoltura IFERROR(__xludf.DUMMYFUNCTION(...), valor), o null si la fórmula no es esa envoltura exacta.
     *
     * @return array{valor: string|int|float|bool, tipo: string}|null `tipo`: texto | numero | booleano | vacio
     */
    public static function reserva(string $formula): ?array
    {
        $n = strlen($formula);
        if ($n === 0 || $n > self::LONGITUD_MAX) {
            return null;
        }
        $i = 0;

        if (! self::literal($formula, $i, '=') || ! self::palabra($formula, $i, 'IFERROR') || ! self::literal($formula, $i, '(')) {
            return null;
        }
        if (! self::palabra($formula, $i, '__xludf.DUMMYFUNCTION') || ! self::literal($formula, $i, '(')) {
            return null;
        }
        // Primer argumento: la fórmula original, SIEMPRE como texto entre comillas. Se salta sin interpretarla.
        if (self::cadena($formula, $i) === null || ! self::literal($formula, $i, ')') || ! self::literal($formula, $i, ',')) {
            return null;
        }

        self::espacios($formula, $i);
        $resultado = null;
        if ($i < $n && $formula[$i] === '"') {
            $texto = self::cadena($formula, $i);
            if ($texto === null || strlen($texto) > self::LITERAL_MAX) {
                return null;
            }
            $resultado = ['valor' => $texto, 'tipo' => $texto === '' ? 'vacio' : 'texto'];
        } elseif ($i < $n && $formula[$i] === ')') {
            $resultado = ['valor' => '', 'tipo' => 'vacio'];   // IFERROR(…,) → argumento vacío
        } else {
            $inicio = $i;
            while ($i < $n && $formula[$i] !== ')' && $formula[$i] !== ' ' && $formula[$i] !== "\t" && $formula[$i] !== "\r" && $formula[$i] !== "\n") {
                $i++;
            }
            $palabra = substr($formula, $inicio, $i - $inicio);
            if (strcasecmp($palabra, 'TRUE') === 0 || strcasecmp($palabra, 'FALSE') === 0) {
                $resultado = ['valor' => strcasecmp($palabra, 'TRUE') === 0, 'tipo' => 'booleano'];
            } elseif (self::esNumero($palabra)) {
                $resultado = ['valor' => self::aNumero($palabra), 'tipo' => 'numero'];
            } else {
                return null;   // cualquier otra cosa (referencia, función, operación…) no se interpreta
            }
        }

        if (! self::literal($formula, $i, ')')) {
            return null;
        }
        self::espacios($formula, $i);

        return $i === $n ? $resultado : null;
    }

    /**
     * Rasgos de la fórmula para el diagnóstico (sin guardar su texto: puede contener identificadores de hojas externas).
     *
     * @return array{funcion: ?string, importrange: bool, externa: bool}
     */
    public static function perfil(string $formula): array
    {
        $muestra = substr($formula, 0, self::LONGITUD_MAX);
        $funcion = null;
        if (strlen($muestra) > 1 && $muestra[0] === '=') {
            $i = 1;
            while ($i < strlen($muestra) && ($muestra[$i] === ' ' || $muestra[$i] === "\t")) {
                $i++;
            }
            $inicio = $i;
            while ($i < strlen($muestra) && $i - $inicio < 30 && (ctype_alnum($muestra[$i]) || $muestra[$i] === '_' || $muestra[$i] === '.')) {
                $i++;
            }
            if ($i > $inicio && ! ctype_digit($muestra[$inicio])) {
                $funcion = strtoupper(substr($muestra, $inicio, $i - $inicio));
            }
        }

        return [
            'funcion' => $funcion,
            'importrange' => stripos($muestra, 'IMPORTRANGE') !== false,
            'externa' => str_contains($muestra, '[') || stripos($muestra, 'http') !== false || stripos($muestra, 'HYPERLINK') !== false,
        ];
    }

    // ── Analizador lineal ─────────────────────────────────────────────────────

    private static function espacios(string $f, int &$i): void
    {
        $n = strlen($f);
        while ($i < $n && ($f[$i] === ' ' || $f[$i] === "\t" || $f[$i] === "\r" || $f[$i] === "\n")) {
            $i++;
        }
    }

    private static function literal(string $f, int &$i, string $c): bool
    {
        self::espacios($f, $i);
        if ($i < strlen($f) && $f[$i] === $c) {
            $i++;

            return true;
        }

        return false;
    }

    private static function palabra(string $f, int &$i, string $palabra): bool
    {
        self::espacios($f, $i);
        $largo = strlen($palabra);
        if (substr_compare($f, $palabra, $i, $largo, true) === 0) {
            $i += $largo;

            return true;
        }

        return false;
    }

    /** Texto entre comillas con "" como comilla escapada. Devuelve el contenido sin comillas o null si no está bien cerrado. */
    private static function cadena(string $f, int &$i): ?string
    {
        self::espacios($f, $i);
        $n = strlen($f);
        if ($i >= $n || $f[$i] !== '"') {
            return null;
        }
        $i++;
        $salida = '';
        $desde = $i;
        while ($i < $n) {
            if ($f[$i] === '"') {
                if ($i + 1 < $n && $f[$i + 1] === '"') {
                    $salida .= substr($f, $desde, $i - $desde + 1);
                    $i += 2;
                    $desde = $i;

                    continue;
                }
                $salida .= substr($f, $desde, $i - $desde);
                $i++;

                return $salida;
            }
            $i++;
        }

        return null;   // comilla sin cerrar
    }

    private static function esNumero(string $p): bool
    {
        $n = strlen($p);
        if ($n === 0 || $n > 40) {
            return false;
        }
        $i = 0;
        if ($p[$i] === '-' || $p[$i] === '+') {
            $i++;
        }
        $digitos = 0;
        while ($i < $n && ctype_digit($p[$i])) {
            $i++;
            $digitos++;
        }
        if ($i < $n && $p[$i] === '.') {
            $i++;
            while ($i < $n && ctype_digit($p[$i])) {
                $i++;
                $digitos++;
            }
        }
        if ($digitos === 0) {
            return false;
        }
        if ($i < $n && ($p[$i] === 'e' || $p[$i] === 'E')) {
            $i++;
            if ($i < $n && ($p[$i] === '-' || $p[$i] === '+')) {
                $i++;
            }
            $exp = 0;
            while ($i < $n && ctype_digit($p[$i])) {
                $i++;
                $exp++;
            }
            if ($exp === 0) {
                return false;
            }
        }

        return $i === $n;
    }

    private static function aNumero(string $p): int|float
    {
        return preg_match('/^[+-]?\d+$/', $p) === 1 && strlen($p) < 16 ? (int) $p : (float) $p;
    }
}
