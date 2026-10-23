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

    /**
     * Prefijo de cédula de ciudadanía o tarjeta de identidad («C.C. 123», «CC:123», «T.I. 123»): su número es SOLO dígitos. Mismas variantes que
     * reconoce el importador al unir el tipo con el número. Los demás tipos (C.E., pasaporte, PEP, PPT, NIT…) pueden llevar letras y no se tocan.
     * Riesgo residual conocido: un documento SIN tipo cuyo texto empiece por «CC» o «TI» seguido de un separador se trata como cédula o T.I.
     */
    private const PREFIJO_NUMERICO = '/^(c\.?\s?c\.?|t\.?\s?i\.?)(?![\p{L}])\s*[:#-]?\s*(.*)$/iu';

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
        if (($error = self::numeroDeCedula($texto, $fila, $col)) !== null) {
            return [null, [$error], $avisos, $vista];
        }

        return [$texto, [], $avisos, $vista];
    }

    /**
     * Con prefijo de C.C. o T.I. se valida el NÚMERO, sin contar las letras del prefijo: solo dígitos (se admiten puntos o espacios de miles bien
     * agrupados) y al menos DOCUMENTO_MIN_ALFANUMERICOS dígitos. No cambia el valor: los ceros iniciales y los puntos se conservan tal como vienen.
     * Va AL FINAL: solo rechaza lo que antes se aceptaba; lo que ya se rechazaba (prefijo sin número, fórmula, carácter no imprimible) conserva su mensaje.
     * El mínimo es el heredado del sistema (4). Solo se revisa la FORMA del número: no se verifica que el documento exista oficialmente.
     */
    private static function numeroDeCedula(string $texto, ?int $fila, string $col): ?ErrorFila
    {
        if (preg_match(self::PREFIJO_NUMERICO, $texto, $m) !== 1) {
            return null;
        }
        $tipo = str_starts_with(mb_strtolower(ltrim($m[1])), 'c') ? 'C.C.' : 'T.I.';
        $numero = trim($m[2]);

        if (preg_match('/^\d+$/', $numero) !== 1 && preg_match('/^\d{1,3}([.\s]\d{3})+$/', $numero) !== 1) {
            return ErrorFila::error(ErrorFila::DOCUMENTO_INVALIDO, "El número de la {$tipo} solo puede tener dígitos (y, si acaso, puntos de miles): «{$numero}» no es válido.", $fila, $col, $texto);
        }
        if (strlen(preg_replace('/\D/', '', $numero) ?? '') < self::DOCUMENTO_MIN_ALFANUMERICOS) {
            return ErrorFila::error(ErrorFila::DOCUMENTO_MUY_CORTO, "El número de la {$tipo} debe tener al menos ".self::DOCUMENTO_MIN_ALFANUMERICOS." dígitos (sin contar «{$tipo}»).", $fila, $col, $texto);
        }

        return null;
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

        return ErrorFila::error(ErrorFila::CARACTER_NO_SOPORTADO, "La fuente Outfit no tiene estos caracteres: {$lista}. No se pueden imprimir en el certificado.", $fila, $col, $valor);
    }
}
