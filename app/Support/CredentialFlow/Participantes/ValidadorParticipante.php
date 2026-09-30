<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\FuentesCredential;

/**
 * ÚNICA implementación de las reglas de un participante: la usan el importador (filas del archivo) y
 * el alta/edición manual. No hay reglas distintas entre Excel y formulario.
 *
 * Los valores se guardan ya normalizados: lo guardado es lo que se imprime.
 *  - nombre_completo: NFC, espacios limpios y MAYÚSCULAS (formato del catálogo).
 *  - documento: NFC y espacios limpios, sin cambiar su presentación (formato literal).
 */
final class ValidadorParticipante
{
    public const CELDA_MAX = 500;

    public const NOMBRE_MIN = 2;

    public const DOCUMENTO_MIN_ALFANUMERICOS = 4;

    /** Un entero de Excel solo se acepta si cabe sin perder dígitos (Excel guarda 15 cifras significativas). */
    private const ENTERO_EXCEL_MAX = 999_999_999_999_999;

    public static function validar(CeldaCruda $nombre, CeldaCruda $documento, ?int $fila = null): ResultadoFila
    {
        [$n, $errN, $avN, $vistaN] = self::nombre($nombre, $fila);
        [$d, $errD, $avD, $vistaD] = self::documento($documento, $fila);

        $clave = $d !== null ? Texto::claveDocumento($d) : null;

        return new ResultadoFila($n, $d, $clave, $vistaN, $vistaD, [...$errN, ...$errD], [...$avN, ...$avD]);
    }

    /** @return array{0:?string,1:array<int,ErrorFila>,2:array<int,ErrorFila>,3:string} */
    private static function nombre(CeldaCruda $celda, ?int $fila): array
    {
        $col = 'nombre_completo';
        $errores = [];
        $texto = self::textoDeCelda($celda, $col, $fila, $errores);
        if ($texto === null) {
            return [null, $errores, [], ''];
        }

        $vista = $texto;
        if ($texto === '') {
            return [null, [ErrorFila::error(ErrorFila::NOMBRE_VACIO, 'El nombre está vacío.', $fila, $col)], [], ''];
        }
        if (Texto::iniciaConFormula($texto)) {
            return [null, [ErrorFila::error(ErrorFila::FORMULA_NO_PERMITIDA, 'El nombre empieza por = + - o @: parece una fórmula y no se permite.', $fila, $col, $texto)], [], $vista];
        }

        $largo = mb_strlen($texto);
        if ($largo < self::NOMBRE_MIN) {
            return [null, [ErrorFila::error(ErrorFila::NOMBRE_MUY_CORTO, 'El nombre debe tener al menos '.self::NOMBRE_MIN.' caracteres.', $fila, $col, $texto)], [], $vista];
        }
        $max = CamposDinamicos::todos()['nombre_completo']['maxLongitud'];
        if ($largo > $max) {
            return [null, [ErrorFila::error(ErrorFila::NOMBRE_MUY_LARGO, "El nombre supera los {$max} caracteres.", $fila, $col, $texto)], [], $vista];
        }

        $texto = Texto::mayusculas($texto);
        $vista = $texto;
        if (($faltantes = self::noSoportados($texto)) !== []) {
            return [null, [self::errorCaracteres($faltantes, $fila, $col, $texto)], [], $vista];
        }

        return [$texto, [], [], $vista];
    }

    /** @return array{0:?string,1:array<int,ErrorFila>,2:array<int,ErrorFila>,3:string} */
    private static function documento(CeldaCruda $celda, ?int $fila): array
    {
        $col = 'documento';
        $avisos = [];

        // Número de Excel: solo enteros exactos; se convierten a texto de dígitos con un aviso visible.
        if (! $celda->formula && (is_int($celda->valor) || is_float($celda->valor))) {
            $v = $celda->valor;
            $entero = is_int($v) ? $v : ((is_finite($v) && floor($v) === $v && abs($v) <= self::ENTERO_EXCEL_MAX) ? (int) $v : null);

            if ($entero === null) {
                return [null, [ErrorFila::error(ErrorFila::DOCUMENTO_NOTACION_CIENTIFICA, 'El documento llegó como un número con decimales o en notación científica. Escríbelo como texto en la celda.', $fila, $col, (string) $v)], [], (string) $v];
            }
            if ($entero <= 0 || $entero > self::ENTERO_EXCEL_MAX) {
                return [null, [ErrorFila::error(ErrorFila::DOCUMENTO_INVALIDO, 'El documento numérico no es válido.', $fila, $col, (string) $entero)], [], (string) $entero];
            }
            $celda = CeldaCruda::texto((string) $entero);
            $avisos[] = ErrorFila::aviso(ErrorFila::DOCUMENTO_NUMERICO_EXCEL, 'Excel entregó el documento como número: los ceros iniciales y el formato no se conservan. Revisa que el valor sea el correcto.', $fila, $col, (string) $entero);
        }

        $errores = [];
        $texto = self::textoDeCelda($celda, $col, $fila, $errores);
        if ($texto === null) {
            return [null, $errores, [], ''];
        }
        $vista = $texto;

        if ($texto === '') {
            return [null, [ErrorFila::error(ErrorFila::DOCUMENTO_VACIO, 'El documento está vacío.', $fila, $col)], [], ''];
        }
        if (Texto::iniciaConFormula($texto)) {
            return [null, [ErrorFila::error(ErrorFila::FORMULA_NO_PERMITIDA, 'El documento empieza por = + - o @: parece una fórmula y no se permite.', $fila, $col, $texto)], $avisos, $vista];
        }
        if (preg_match('/^\d+([.,]\d+)?[eE][+-]?\d+$/', $texto) === 1 || preg_match('/^\d+[.,]0+$/', $texto) === 1) {
            return [null, [ErrorFila::error(ErrorFila::DOCUMENTO_NOTACION_CIENTIFICA, 'El documento parece un número convertido por Excel (notación científica o decimales). Escríbelo como texto.', $fila, $col, $texto)], $avisos, $vista];
        }

        $max = CamposDinamicos::todos()['documento']['maxLongitud'];
        if (mb_strlen($texto) > $max) {
            return [null, [ErrorFila::error(ErrorFila::DOCUMENTO_MUY_LARGO, "El documento supera los {$max} caracteres.", $fila, $col, $texto)], $avisos, $vista];
        }
        if (Texto::alfanumericos($texto) < self::DOCUMENTO_MIN_ALFANUMERICOS) {
            return [null, [ErrorFila::error(ErrorFila::DOCUMENTO_MUY_CORTO, 'El documento debe tener al menos '.self::DOCUMENTO_MIN_ALFANUMERICOS.' letras o dígitos.', $fila, $col, $texto)], $avisos, $vista];
        }
        if (($faltantes = self::noSoportados($texto)) !== []) {
            return [null, [self::errorCaracteres($faltantes, $fila, $col, $texto)], $avisos, $vista];
        }

        return [$texto, [], $avisos, $vista];
    }

    /**
     * Texto limpio de una celda, o null si la celda ya produjo un error (fórmula, tipo, tamaño, controles).
     *
     * @param  array<int,ErrorFila>  $errores
     */
    private static function textoDeCelda(CeldaCruda $celda, string $col, ?int $fila, array &$errores): ?string
    {
        $errores = [];

        if ($celda->formula) {
            $errores[] = ErrorFila::error(ErrorFila::FORMULA_NO_PERMITIDA, 'La celda contiene una fórmula. No se ejecutan fórmulas: escribe el valor como texto.', $fila, $col);

            return null;
        }

        $valor = $celda->valor;
        if ($valor === null) {
            return '';
        }
        if (is_int($valor) || is_float($valor)) {
            $valor = (string) $valor;
        }
        if (! is_string($valor)) {
            $errores[] = ErrorFila::error(ErrorFila::VALOR_NO_TEXTO, 'La celda no contiene texto (es una fecha, un valor lógico o un error de Excel).', $fila, $col);

            return null;
        }
        if (! Texto::utf8Valido($valor)) {
            $errores[] = ErrorFila::error(ErrorFila::CARACTER_INVALIDO, 'La celda tiene caracteres que no se pueden leer (codificación inválida).', $fila, $col);

            return null;
        }
        if (mb_strlen($valor) > self::CELDA_MAX) {
            $errores[] = ErrorFila::error(ErrorFila::CELDA_MUY_LARGA, 'La celda supera los '.self::CELDA_MAX.' caracteres.', $fila, $col, $valor);

            return null;
        }

        $limpio = Texto::limpiar($valor);
        if (Texto::tieneControl($limpio)) {
            $errores[] = ErrorFila::error(ErrorFila::CARACTER_INVALIDO, 'La celda tiene caracteres de control no permitidos.', $fila, $col, $limpio);

            return null;
        }

        return $limpio;
    }

    /** Caracteres que Outfit no puede representar (mismo criterio que el generador de PDF). @return array<int,string> */
    public static function noSoportados(string $texto): array
    {
        return FuentesCredential::medirTexto($texto, FuentesCredential::OUTFIT, 700, 10)['faltantes'];
    }

    /** @param array<int,string> $faltantes */
    private static function errorCaracteres(array $faltantes, ?int $fila, string $col, string $valor): ErrorFila
    {
        $lista = implode(' ', array_map(fn (string $c) => "«{$c}»", array_slice($faltantes, 0, 5)));

        return ErrorFila::error(ErrorFila::CARACTER_NO_SOPORTADO, "La fuente Outfit no tiene estos caracteres: {$lista}. No se pueden imprimir en la credencial.", $fila, $col, $valor);
    }
}
