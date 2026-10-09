<?php

namespace App\Support\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\Legado\FormatoLegado;
use App\Support\CredentialFlow\Participantes\Texto;

/**
 * Funciones PURAS de normalización del staging histórico (sin base de datos, sin estado).
 *
 * Reglas generales:
 *  - El staging conserva SIEMPRE el valor original; estas funciones solo producen el valor derivado.
 *  - NULL y cadena vacía (o solo espacios) significan «sin dato» y se tratan igual en las comparaciones.
 *  - Todo texto se normaliza a Unicode NFC; espacios (incluidos NBSP, tabs y saltos) se colapsan a uno y se recortan.
 *  - Si cambia alguna regla, se incrementa VERSION: las filas guardan con qué versión se derivaron.
 */
final class Normalizador
{
    public const VERSION = 2;

    /** Más de 15 dígitos el sistema viejo ya no los imprimía igual (number_format convierte a float). */
    public const MAX_DIGITOS_DOCUMENTO = 15;

    /** NULL o vacío/solo espacios → null; en otro caso, el texto limpio (NFC, espacios colapsados). */
    public static function sinDato(?string $valor): ?string
    {
        if ($valor === null) {
            return null;
        }
        $limpio = Texto::limpiar($valor);

        return $limpio === '' ? null : $limpio;
    }

    /** Nombre de persona: limpio y en MAYÚSCULAS Unicode (conserva tildes). Sin dato → cadena vacía. */
    public static function nombre(?string $valor): string
    {
        $limpio = self::sinDato($valor);

        return $limpio === null ? '' : Texto::mayusculas($limpio);
    }

    /** Tipo de documento: limpio, mayúsculas y sin puntos ni espacios (`C.C.` → `CC`). Sin dato → null. */
    public static function tipoDocumento(?string $valor): ?string
    {
        $limpio = self::sinDato($valor);
        if ($limpio === null) {
            return null;
        }
        $t = preg_replace('/[\s.]+/u', '', Texto::mayusculas($limpio)) ?? '';

        return $t === '' ? null : $t;
    }

    /** Clave para detectar duplicados: la misma regla que cf_participantes.documento_clave. Sin dato → ''. */
    public static function documentoClave(?string $valor): string
    {
        $limpio = self::sinDato($valor);

        return $limpio === null ? '' : Texto::claveDocumento($limpio);
    }

    /** Correo comparable: limpio y en minúsculas. Sin dato → null. */
    public static function correo(?string $valor): ?string
    {
        $limpio = self::sinDato($valor);

        return $limpio === null ? null : mb_strtolower($limpio, 'UTF-8');
    }

    /** valido | invalido | sin_correo, a partir del correo ya normalizado. */
    public static function correoEstado(?string $correoNormalizado): string
    {
        if ($correoNormalizado === null) {
            return 'sin_correo';
        }
        if (mb_strlen($correoNormalizado) > 254 || preg_match('/\s/u', $correoNormalizado) === 1) {
            return 'invalido';
        }
        $arroba = strrpos($correoNormalizado, '@');
        $dominio = $arroba === false ? '' : substr($correoNormalizado, $arroba + 1);

        // Exige un punto en el dominio (user@localhost no sirve para enviar correo a una persona).
        return filter_var($correoNormalizado, FILTER_VALIDATE_EMAIL) !== false && str_contains($dominio, '.') ? 'valido' : 'invalido';
    }

    /**
     * Texto para buscar: minúsculas, sin tildes y con espacios colapsados (`Gestión  Integral` → `gestion integral`).
     * Es la misma idea que cf_eventos.nombre_normalizado.
     */
    public static function paraBuscar(?string $valor): string
    {
        $limpio = self::sinDato($valor);
        if ($limpio === null) {
            return '';
        }
        $d = \Normalizer::normalize(mb_strtolower($limpio, 'UTF-8'), \Normalizer::FORM_D);
        $d = $d === false ? $limpio : $d;

        return trim(preg_replace('/\p{Mn}+/u', '', $d) ?? $d);
    }

    /**
     * Clave de emparejamiento de nombres de archivo: minúsculas, sin tildes y cualquier grupo de caracteres que no sea
     * letra o dígito pasa a «_». Sirve para reconocer `GAT 4.0` con `GAT_4_0` (el sistema viejo cambió espacios, signos y
     * comillas por «_» en algunos archivos). NO cambia nada: solo sugiere candidatas.
     */
    public static function claveArchivo(string $nombre): string
    {
        $base = self::paraBuscar($nombre);
        $base = preg_replace('/[^a-z0-9]+/', '_', $base) ?? $base;

        return trim($base, '_');
    }

    /** Extensión como la decide FPDF 1.81: lo que sigue al último punto, en minúsculas (null si no hay punto). */
    public static function extensionFpdf(string $nombre): ?string
    {
        $pos = strrpos($nombre, '.');
        if ($pos === false || $pos === 0) {
            return null;
        }

        return strtolower(substr($nombre, $pos + 1));
    }

    /**
     * Cómo imprimía el sistema viejo el número de documento: `number_format($documento, 0, ',', '.')` en PHP 7.4.
     * Se toma el prefijo numérico de la cadena (como hace PHP al convertirla) y se formatea como float; si no hay
     * prefijo numérico, PHP 7.4 devolvía NULL (no se imprimía nada). Casos de referencia (verificados en 7.4.33):
     * `73156827`→`73.156.827`, `009876543`→`9.876.543`, `73.156.827`→`73`, `ABC123`→null, `123abc`→`123`, ``→null.
     */
    public static function documentoImpresoLegado(?string $documento): ?string
    {
        // Fuente única de esta regla: el helper histórico del renderer (mismo comportamiento, verificado con PHP 7.4.33).
        return FormatoLegado::documento($documento);
    }

    /** Línea completa «TIPO: documento» tal como se imprimía (tipo vacío → `: 123`). */
    public static function lineaDocumentoLegado(?string $tipoOriginal, ?string $documentoOriginal): string
    {
        return FormatoLegado::lineaDocumento($tipoOriginal, $documentoOriginal);
    }

    /** Todo lo que cuenta como espacio en blanco: ASCII (espacio, tab, CR, LF, VT, FF), NBSP y demás separadores Unicode, y NEL. */
    private const BLANCOS = '/[\s\p{Z}\x{0085}]+/u';

    /** El texto sin NINGÚN espacio en blanco (ni en los bordes ni en medio). No toca nada más. */
    public static function quitarBlancos(?string $valor): string
    {
        return $valor === null ? '' : (preg_replace(self::BLANCOS, '', $valor) ?? $valor);
    }

    /**
     * Qué espacios en blanco tiene un valor y dónde: lista ordenada «tipo@posición» (`lf@borde`, `espacio@interno`…) o null si
     * no tiene ninguno. Tipos: espacio, tab, cr, lf, vt, ff, nbsp, unicode.
     */
    public static function describirBlancos(?string $valor): ?string
    {
        if ($valor === null || preg_match_all(self::BLANCOS, $valor, $m, PREG_OFFSET_CAPTURE) === 0) {
            return null;
        }
        $nombres = [' ' => 'espacio', "\t" => 'tab', "\r" => 'cr', "\n" => 'lf', "\v" => 'vt', "\f" => 'ff', "\u{00A0}" => 'nbsp'];
        $largo = strlen($valor);
        $r = [];
        foreach ($m[0] as [$texto, $pos]) {
            $borde = $pos === 0 || $pos + strlen($texto) === $largo;
            foreach (preg_split('//u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $c) {
                $r[($nombres[$c] ?? 'unicode').'@'.($borde ? 'borde' : 'interno')] = true;
            }
        }
        ksort($r);

        return implode(',', array_keys($r));
    }

    /**
     * Evaluación del documento con la REGLA DE WHITESPACE:
     *  - vacio: sin dato o solo espacios en blanco.
     *  - Se quitan TODOS los espacios en blanco; si lo que queda son solo dígitos (sin ceros a la izquierda ni más de 15) el
     *    documento es válido. Si para llegar ahí hubo que limpiar, se marca `normalizado_whitespace`… pero SOLO si lo que el
     *    sistema viejo imprimía es lo mismo con y sin la limpieza (p. ej. un salto de línea al final no cambia el número; un
     *    espacio en medio sí lo cambiaba). Si imprimía otra cosa: anómalo `whitespace_cambia_impresion` (REVISION_DOCUMENTO).
     *  - Si tras quitar los blancos queda cualquier carácter no numérico: anómalo (letras, separadores u otro).
     * El documento original NUNCA se modifica: esto solo clasifica.
     *
     * @return array{estado:string,detalle:?string,normalizado_whitespace:bool,blancos:?string}
     */
    public static function evaluarDocumento(?string $original): array
    {
        $resultado = fn (string $estado, ?string $detalle, bool $marca, ?string $blancos) => ['estado' => $estado, 'detalle' => $detalle, 'normalizado_whitespace' => $marca, 'blancos' => $blancos];

        $sin = self::quitarBlancos($original);
        if ($original === null || $sin === '') {
            return $resultado('vacio', null, false, self::describirBlancos($original));
        }
        $blancos = self::describirBlancos($original);

        if (! ctype_digit($sin)) {
            if (preg_match('/[\p{L}]/u', $sin) === 1) {
                return $resultado('anomalo', 'letras', false, $blancos);
            }

            return $resultado('anomalo', preg_match('/^[\d.,]+$/', $sin) === 1 ? 'separadores' : 'otro', false, $blancos);
        }
        if (strlen($sin) > self::MAX_DIGITOS_DOCUMENTO) {
            return $resultado('anomalo', 'muy_largo', false, $blancos);
        }
        if ($sin[0] === '0') {
            return $resultado('anomalo', 'ceros_izquierda', false, $blancos);
        }
        if ($blancos === null) {
            return $resultado('valido', null, false, null);
        }

        return self::documentoImpresoLegado($original) === self::documentoImpresoLegado($sin)
            ? $resultado('valido', null, true, $blancos)
            : $resultado('anomalo', 'whitespace_cambia_impresion', false, $blancos);
    }

    /**
     * Estado del documento: [estado, detalle] (ver evaluarDocumento).
     *
     * @return array{0:string,1:?string}
     */
    public static function estadoDocumento(?string $original): array
    {
        $e = self::evaluarDocumento($original);

        return [$e['estado'], $e['detalle']];
    }

    /**
     * Correos candidatos de un campo de correo, SIN elegir ninguno como principal. Si el valor completo ya es una dirección
     * válida no se toca. Solo se separa por «;» o «,» cuando quedan dos o más fragmentos y TODOS parecen direcciones (llevan
     * «@»): así no se parte nada con reglas agresivas. Un separador sobrante («a@x.com;») no crea candidatos nuevos. Cada
     * candidato se recorta, se pasa a minúsculas, se valida por separado y se deduplica sin distinguir mayúsculas.
     *
     * @return array<int,array{correo:string,estado:string}> en el orden en que aparecen
     */
    public static function candidatosCorreo(?string $original): array
    {
        $limpio = self::sinDato($original);
        if ($limpio === null) {
            return [];
        }
        $partes = [$limpio];
        if (self::correoEstado(self::correo($limpio)) !== 'valido' && preg_match('/[;,]/', $limpio) === 1) {
            $fragmentos = array_values(array_filter(array_map('trim', preg_split('/[;,]/', $limpio) ?: []), fn ($f) => $f !== ''));
            $conArroba = count(array_filter($fragmentos, fn ($f) => str_contains($f, '@')));
            if (count($fragmentos) === 1 || (count($fragmentos) >= 2 && $conArroba === count($fragmentos))) {
                $partes = $fragmentos;
            }
        }

        $r = [];
        foreach ($partes as $parte) {
            $n = self::correo($parte);
            if ($n !== null && ! isset($r[$n])) {
                $r[$n] = ['correo' => $n, 'estado' => self::correoEstado($n)];
            }
        }

        return array_values($r);
    }

    /**
     * Resumen de los correos de una fila: estado (`sin_correo`, `invalido`, `valido` si hay EXACTAMENTE un candidato válido,
     * `multiple` si hay más de uno), el correo utilizable (solo con `valido`; con `multiple` queda null a propósito: no se
     * elige uno al azar), cuántos candidatos y cuántos válidos hay, y una firma para comparar filas sin mirar el texto.
     *
     * @return array{estado:string,correo_normalizado:?string,candidatos:int,validos:int,firma:?string}
     */
    public static function resumenCorreos(?string $original): array
    {
        $c = self::candidatosCorreo($original);
        $validos = array_values(array_filter($c, fn ($x) => $x['estado'] === 'valido'));
        $estado = $c === [] ? 'sin_correo' : (count($validos) === 0 ? 'invalido' : (count($validos) === 1 ? 'valido' : 'multiple'));
        $lista = array_column($c, 'correo');
        sort($lista, SORT_STRING);

        return [
            'estado' => $estado,
            'correo_normalizado' => $estado === 'valido' ? $validos[0]['correo'] : null,
            'candidatos' => count($c),
            'validos' => count($validos),
            'firma' => $c === [] ? null : hash('sha256', implode("\n", $lista)),
        ];
    }

    /**
     * Año deducible de uno o varios textos (nombre del evento, nombre de la imagen): [año, origen] o [null, motivo].
     * Solo se acepta un año si es el ÚNICO distinto (2000–2099) encontrado; si hay varios es ambiguo y no se elige.
     *
     * @param  array<string,?string>  $textos  origen => texto, en orden de preferencia
     * @return array{0:?int,1:?string}
     */
    public static function anioDeducible(array $textos): array
    {
        foreach ($textos as $origen => $texto) {
            if ($texto === null) {
                continue;
            }
            preg_match_all('/(?<!\d)(20\d{2})(?!\d)/', $texto, $m);
            $anios = array_values(array_unique($m[1]));
            if (count($anios) === 1) {
                return [(int) $anios[0], (string) $origen];
            }
            if (count($anios) > 1) {
                return [null, 'ambiguo'];
            }
        }

        return [null, null];
    }

    /** ¿El nombre del archivo es «raro»? Espacios en los bordes, dobles o antes del punto de la extensión. */
    public static function nombreArchivoRaro(string $nombre): bool
    {
        return preg_match('/^\s|\s$|\s{2,}|\s\.[^.]*$/u', $nombre) === 1;
    }

    /**
     * Huella de una fila de ORIGEN: SHA-256 de sus columnas originales (clave ordenada, valores como texto o null,
     * sin normalizar). Es estable entre cargas y entre equipos.
     *
     * @param  array<string,mixed>  $columnas
     */
    public static function hashFila(array $columnas): string
    {
        ksort($columnas);
        $canonico = [];
        foreach ($columnas as $clave => $valor) {
            $canonico[$clave] = $valor === null ? null : (string) $valor;
        }

        return hash('sha256', 'v1|'.json_encode($canonico, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }
}
