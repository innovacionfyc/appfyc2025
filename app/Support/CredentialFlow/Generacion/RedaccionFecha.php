<?php

namespace App\Support\CredentialFlow\Generacion;

/**
 * «realizado el día 16 de octubre de 2026» / «realizado los días 22 y 23 de octubre de 2026»: el singular o el plural lo decide la FECHA, no la plantilla.
 *
 * Solo se ajusta un prefijo que TERMINA en «el día» o «los días» (con o sin «realizado», con o sin tilde, en mayúsculas o minúsculas). Cualquier otra
 * redacción (por ejemplo «Fecha: ») se respeta tal cual. La fecha nunca se modifica: si no se puede saber con certeza cuántos días nombra, el prefijo de la
 * plantilla queda como está. Si la fecha ya trae su propia redacción («los días 22 y 23…»), el prefijo no la repite.
 *
 * La usan el generador (PDF oficial, QA y vistas previas) y el asistente de importación, para que todos digan exactamente lo mismo.
 */
final class RedaccionFecha
{
    private const MESES = 'enero|febrero|marzo|abril|mayo|junio|julio|agosto|septiembre|setiembre|octubre|noviembre|diciembre';

    /** «el día» / «los días» al final del prefijo (grupo 1), con el espacio que lo separa de la fecha (grupo 2). */
    private const FINAL_DEL_PREFIJO = '/\b(el\s+d[ií]a|los\s+d[ií]as)(\s*)$/iu';

    /** La fecha ya empieza con su propia redacción: «el día…», «los días…» o «realizado el día…». */
    private const FECHA_CON_REDACCION = '/^\s*(realizad[oa]s?\s+)?(el\s+d[ií]a|los\s+d[ií]as)\b/iu';

    /**
     * Cuántos días nombra la fecha, o null si no se puede saber con certeza (formato no reconocido). Reconoce lo que escribe el importador y sus
     * variantes habituales: «16 de octubre de 2026», «22 y 23 de octubre de 2026», «1, 2 y 3 de octubre de 2026»,
     * «30 de septiembre de 2026 y 1 de octubre de 2026», «30 de septiembre y 1 de octubre de 2026» y rangos «16 al 18 de octubre de 2026».
     */
    public static function dias(string $fecha): ?int
    {
        $t = self::normalizar($fecha);
        $mes = self::MESES;

        // Rango: «16 al 18 de octubre de 2026» (también «del 16 al 18…»).
        if (preg_match('/^(?:del\s+)?(\d{1,2})\s+al\s+(\d{1,2})\s+de\s+(?:'.$mes.')\s+(?:de|del)\s+\d{4}$/u', $t, $m) === 1) {
            $desde = (int) $m[1];
            $hasta = (int) $m[2];

            return $desde >= 1 && $hasta <= 31 && $hasta > $desde ? $hasta - $desde + 1 : null;
        }

        // Uno o varios grupos «días de mes [de año]» separados por «,» o «y»; el último lleva el año.
        $dias = '\d{1,2}(?:\s*(?:,|y)\s*\d{1,2})*';
        $grupo = $dias.'\s+de\s+(?:'.$mes.')';
        if (preg_match('/^'.$grupo.'(?:\s+(?:de|del)\s+\d{4})?(?:\s*(?:,|y)\s*'.$grupo.'(?:\s+(?:de|del)\s+\d{4})?)*$/u', $t) !== 1
            || preg_match('/(?:de|del)\s+\d{4}$/u', $t) !== 1) {
            return null;
        }
        $sinAnios = preg_replace('/\b\d{4}\b/u', '', $t) ?? $t;
        preg_match_all('/\b\d{1,2}\b/u', $sinAnios, $numeros);
        foreach ($numeros[0] as $n) {
            if ((int) $n < 1 || (int) $n > 31) {
                return null;
            }
        }

        return count($numeros[0]) >= 1 ? count($numeros[0]) : null;
    }

    /** ¿El prefijo termina en «el día» / «los días»? Solo entonces la redacción depende de la fecha. */
    public static function prefijoAjustable(string $prefijo): bool
    {
        return preg_match(self::FINAL_DEL_PREFIJO, $prefijo) === 1;
    }

    /**
     * Prefijo que corresponde a esta fecha. Devuelve el prefijo de la plantilla con «el día» / «los días» según los días que nombra la fecha, sin repetir la
     * redacción si la fecha ya la trae. Si no se sabe cuántos días son, o el prefijo usa otra redacción, lo devuelve sin cambios.
     */
    public static function prefijo(string $prefijo, string $fecha): string
    {
        if (preg_match(self::FECHA_CON_REDACCION, $fecha, $f) === 1) {
            // La fecha trae su propia redacción: se quita del prefijo la parte que la repetiría («realizado el día » o solo «el día »).
            $cola = ($f[1] ?? '') !== '' ? '/\brealizad[oa]s?\s+(el\s+d[ií]a|los\s+d[ií]as)\s*$/iu' : self::FINAL_DEL_PREFIJO;

            return preg_replace($cola, '', $prefijo) ?? $prefijo;
        }

        $dias = self::dias($fecha);
        if ($dias === null) {
            return $prefijo;
        }

        return preg_replace_callback(self::FINAL_DEL_PREFIJO, function (array $m) use ($dias) {
            $nuevo = $dias === 1 ? 'el día' : 'los días';
            if (preg_match('/[íÍ]/u', $m[1]) !== 1) {
                $nuevo = str_replace('í', 'i', $nuevo);   // la plantilla escribe «dia» sin tilde: se respeta
            }
            if (mb_strtoupper($m[1]) === $m[1]) {
                $nuevo = mb_strtoupper($nuevo);
            } elseif (mb_substr($m[1], 0, 1) !== mb_strtolower(mb_substr($m[1], 0, 1))) {
                $nuevo = mb_strtoupper(mb_substr($nuevo, 0, 1)).mb_substr($nuevo, 1);
            }

            return $nuevo.$m[2];
        }, $prefijo) ?? $prefijo;
    }

    /** Prefijo y sufijo del (primer) campo «fecha» del diseño, o null si el diseño no imprime la fecha. @return array{0:string,1:string}|null */
    public static function elementoFecha(array $diseno): ?array
    {
        foreach ((array) ($diseno['elements'] ?? []) as $el) {
            if (($el['field'] ?? null) === 'fecha') {
                return [(string) ($el['prefix'] ?? ''), (string) ($el['suffix'] ?? '')];
            }
        }

        return null;
    }

    /** Cómo se leerá la fecha en un certificado con este diseño (prefijo ajustado + fecha + sufijo), o null si el diseño no la imprime. */
    public static function textoImpreso(array $diseno, string $fecha): ?string
    {
        $el = self::elementoFecha($diseno);

        return $el === null ? null : self::prefijo($el[0], $fecha).$fecha.$el[1];
    }

    /**
     * ¿Debe revisarla el administrador? Sí cuando el diseño dice «el día» / «los días» y no se puede saber cuántos días nombra la fecha (ni la fecha trae su
     * propia redacción): adivinar el singular o el plural podría imprimir una frase falsa.
     */
    public static function requiereRevision(array $diseno, string $fecha): bool
    {
        $el = self::elementoFecha($diseno);

        return $el !== null && trim($fecha) !== '' && self::prefijoAjustable($el[0]) && preg_match(self::FECHA_CON_REDACCION, $fecha) !== 1 && self::dias($fecha) === null;
    }

    /** Minúsculas, sin tildes, espacios simples y sin punto final: para reconocer el formato, nunca para imprimir. */
    private static function normalizar(string $t): string
    {
        $t = mb_strtolower(trim($t), 'UTF-8');
        $d = \Normalizer::normalize($t, \Normalizer::FORM_D);
        $t = preg_replace('/\p{Mn}+/u', '', $d === false ? $t : $d) ?? $t;
        $t = preg_replace('/\s+/u', ' ', $t) ?? $t;

        return rtrim($t, '. ');
    }
}
