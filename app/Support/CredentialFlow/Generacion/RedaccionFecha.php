<?php

namespace App\Support\CredentialFlow\Generacion;

/**
 * «realizado el día 16 de octubre de 2026» / «realizado los días 22 y 23 de octubre de 2026»: el singular o el plural lo decide la FECHA, no la plantilla.
 *
 * Reconoce un día, dos días, rangos y varias sesiones no consecutivas de uno o varios meses, con el mes detrás o delante del día
 * («Septiembre 2, 9, 16, Octubre 14 y 11 de noviembre de 2026»); la fecha escrita se imprime siempre tal cual.
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

    /** Cómo escribir una fecha que se pueda leer con certeza (mensaje del asistente cuando no se reconoce). */
    public const COMO_ESCRIBIRLA = 'Escríbela con el año, por ejemplo «16 de octubre de 2026», «22 y 23 de octubre de 2026», «16 al 18 de octubre de 2026» o, si son varias sesiones, «2, 9 y 16 de septiembre, 14 de octubre y 11 de noviembre de 2026». Revisa también que cada día exista y no se repita.';

    private const NUMERO_MES = ['enero' => 1, 'febrero' => 2, 'marzo' => 3, 'abril' => 4, 'mayo' => 5, 'junio' => 6, 'julio' => 7, 'agosto' => 8,
        'septiembre' => 9, 'setiembre' => 9, 'octubre' => 10, 'noviembre' => 11, 'diciembre' => 12];

    /** «el día» / «los días» al final del prefijo (grupo 1), con el espacio que lo separa de la fecha (grupo 2). */
    private const FINAL_DEL_PREFIJO = '/\b(el\s+d[ií]a|los\s+d[ií]as)(\s*)$/iu';

    /** La fecha ya empieza con su propia redacción: «el día…», «los días…» o «realizado el día…». */
    private const FECHA_CON_REDACCION = '/^\s*(realizad[oa]s?\s+)?(el\s+d[ií]a|los\s+d[ií]as)\b/iu';

    /**
     * Cuántos días nombra la fecha, o null si no se puede saber con certeza (formato no reconocido). Reconoce lo que escribe el importador y sus
     * variantes habituales: «16 de octubre de 2026», «22 y 23 de octubre de 2026», «1, 2 y 3 de octubre de 2026»,
     * «30 de septiembre de 2026 y 1 de octubre de 2026», «30 de septiembre y 1 de octubre de 2026», rangos «16 al 18 de octubre de 2026» y
     * varias sesiones no consecutivas de uno o varios meses, con el mes detrás o delante del día (ver sesiones()).
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

        $sesiones = self::sesiones($fecha);

        return $sesiones === null ? null : count($sesiones);
    }

    /**
     * Fechas reales (año, mes, día) que nombra un texto de una o varias sesiones, en orden de escritura; null si no se pueden saber con certeza.
     * Admite grupos «2, 9 y 16 de septiembre» (día delante) y «Septiembre 2, 9, 16» (mes delante), combinados con «,» o «y», con el año al final
     * de un grupo o del texto («… 14 de octubre y 11 de noviembre de 2026»). En «Octubre 14 y 11 de noviembre» el último número antes de «de
     * noviembre» es de noviembre y los anteriores, de octubre. Devuelve null (nunca adivina) si falta el año, si un grupo no tiene días, si hay
     * palabras o signos desconocidos («/», paréntesis, «por confirmar»…), si un día no existe en su mes o si una sesión se repite.
     *
     * @return list<array{0:int,1:int,2:int}>|null
     */
    public static function sesiones(string $fecha): ?array
    {
        $t = self::normalizar($fecha);
        $meses = self::NUMERO_MES;
        $numMes = fn (string $w) => $meses[$w];
        if ($t === '' || preg_match_all('/\d+|[a-z]+|,/u', $t, $m) === false || trim((string) preg_replace('/\d+|[a-z]+|,/u', '', $t)) !== '') {
            return null;   // signos que no forman parte de una fecha escrita («/», «(», «-»…)
        }
        $tok = $m[0];
        $grupos = [];        // [mes, [días], año|null]
        $pendientes = [];    // días leídos que aún no tienen mes
        $abierto = null;     // índice del grupo «Mes d, d…» que todavía admite días
        $sep = true;         // ¿se puede leer un día ahora? (al inicio, tras «,» / «y» o justo después del mes)
        for ($i = 0, $n = count($tok); $i < $n; $i++) {
            $w = $tok[$i];
            if (preg_match('/^\d{1,2}$/', $w) === 1) {
                if (! $sep) {
                    return null;
                }
                $pendientes[] = (int) $w;
                $sep = false;
            } elseif ($w === ',' || $w === 'y') {
                if ($sep) {
                    return null;   // separador sin un día, mes o año delante («, 2…», «2, , 9»)
                }
                $sep = true;
            } elseif (isset($meses[$w])) {
                // «Mes d, d…» (mes delante): los días pendientes eran del grupo abierto anterior.
                if (! $sep) {
                    return null;   // «… 16 Octubre» o «… de 2026 Octubre»: falta «,» o «y» entre los grupos
                }
                if ($pendientes !== []) {
                    if ($abierto === null) {
                        return null;
                    }
                    array_push($grupos[$abierto][1], ...$pendientes);
                    $pendientes = [];
                }
                $grupos[] = [$numMes($w), [], null];
                $abierto = count($grupos) - 1;
                $sep = true;
                if (! isset($tok[$i + 1]) || preg_match('/^\d{1,2}$/', $tok[$i + 1]) !== 1) {
                    return null;   // «Septiembre y octubre», «Octubre de 2026»: un mes sin días
                }
            } elseif ($w === 'de' || $w === 'del') {
                $sig = $tok[$i + 1] ?? '';
                if (isset($meses[$sig])) {
                    // «… d y d de mes» (mes detrás). Con un grupo «Mes d…» abierto, solo el último día pendiente es del mes que sigue.
                    if ($pendientes === [] || $w === 'del') {
                        return null;
                    }
                    if ($abierto !== null) {
                        $ultimo = array_pop($pendientes);
                        array_push($grupos[$abierto][1], ...$pendientes);
                        $pendientes = [$ultimo];
                    }
                    $grupos[] = [$numMes($sig), $pendientes, null];
                    $pendientes = [];
                    $abierto = null;
                    $i++;
                    $sep = false;
                } elseif (preg_match('/^\d{4}$/', $sig) === 1) {
                    // «… de 2026»: el año es de todos los grupos anteriores que aún no lo tienen.
                    if ($pendientes !== []) {
                        if ($abierto === null) {
                            return null;
                        }
                        array_push($grupos[$abierto][1], ...$pendientes);
                        $pendientes = [];
                    }
                    foreach ($grupos as $g => $grupo) {
                        $grupos[$g][2] ??= (int) $sig;
                    }
                    $abierto = null;
                    $i++;
                    $sep = false;
                } else {
                    return null;
                }
            } else {
                return null;   // número de 3+ cifras sin «de», o palabra que no es de una fecha
            }
        }
        if ($pendientes !== [] || $grupos === [] || preg_match('/^\d{4}$/', (string) end($tok)) !== 1) {
            return null;   // días sin mes o texto que no termina en el año
        }
        $fechas = [];
        foreach ($grupos as [$mesN, $dias, $anio]) {
            if ($dias === [] || $anio === null) {
                return null;
            }
            foreach ($dias as $d) {
                if (! checkdate($mesN, $d, $anio)) {
                    return null;
                }
                $fechas[] = [$anio, $mesN, $d];
            }
        }

        return count(array_unique(array_map('serialize', $fechas))) === count($fechas) ? $fechas : null;
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
