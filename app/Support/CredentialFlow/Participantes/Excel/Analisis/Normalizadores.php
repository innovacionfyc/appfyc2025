<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

use App\Models\CredentialFlow\Correo;
use App\Support\CredentialFlow\Participantes\Excel\CeldaLeida;
use App\Support\CredentialFlow\Participantes\Texto;

/**
 * Convierte el contenido de una celda en una PROPUESTA (`ValorDetectado`). Reglas comunes:
 *  - nunca inventa: lo que no está en la celda queda «faltante» y lo que admite varias lecturas queda «ambiguo»;
 *  - conserva siempre el texto original y dice qué transformación se aplicó;
 *  - no interpreta equivalencias de estados desconocidos.
 */
final class Normalizadores
{
    /** Marca afirmativa de una casilla («X», ✓, sí, TRUE…). */
    public const MARCA_SI = 'si';

    public const MARCA_NO = 'no';

    public const MARCA_VACIA = 'vacia';

    public const MARCA_OTRA = 'otra';

    private const MESES = [
        'enero' => 1, 'ene' => 1, 'febrero' => 2, 'feb' => 2, 'marzo' => 3, 'mar' => 3, 'abril' => 4, 'abr' => 4, 'mayo' => 5, 'may' => 5, 'junio' => 6, 'jun' => 6,
        'julio' => 7, 'jul' => 7, 'agosto' => 8, 'ago' => 8, 'septiembre' => 9, 'setiembre' => 9, 'sep' => 9, 'sept' => 9, 'octubre' => 10, 'oct' => 10,
        'noviembre' => 11, 'nov' => 11, 'diciembre' => 12, 'dic' => 12,
    ];

    private const MESES_TEXTO = [1 => 'ENERO', 'FEBRERO', 'MARZO', 'ABRIL', 'MAYO', 'JUNIO', 'JULIO', 'AGOSTO', 'SEPTIEMBRE', 'OCTUBRE', 'NOVIEMBRE', 'DICIEMBRE'];

    private const ORIGINAL_MAX = 300;

    // ---------------------------------------------------------------- utilidades de celda

    /** Texto de la celda tal como llegó (sin interpretar), recortado. */
    public static function original(?CeldaLeida $c): ?string
    {
        if ($c === null || ! $c->tieneDato()) {
            return null;
        }
        $v = is_bool($c->valor) ? ($c->valor ? 'TRUE' : 'FALSE') : (string) $c->valor;

        return mb_strlen($v) > self::ORIGINAL_MAX ? mb_substr($v, 0, self::ORIGINAL_MAX) : $v;
    }

    /** Por qué una celda no aporta dato. */
    public static function motivoVacia(?CeldaLeida $c): string
    {
        if ($c === null) {
            return 'celda_vacia';
        }

        return match (true) {
            $c->tipo === CeldaLeida::ERROR && $c->esFormula() => 'formula_con_error',
            $c->tipo === CeldaLeida::ERROR => 'celda_con_error',
            $c->origen === CeldaLeida::FORMULA_SIN_VALOR => 'formula_sin_resultado_recuperable',
            $c->origen === CeldaLeida::FORMULA_VACIA => 'formula_con_resultado_vacio',
            $c->origen === CeldaLeida::FORMULA_VACIA_CONTEXTO => 'formula_sin_resultado_en_fila_sin_datos',
            default => 'celda_vacia',
        };
    }

    /** @param  list<string>  $motivos */
    public static function valor(string $campo, string|int|float|bool|null $valor, ?CeldaLeida $c, string $hoja, string $confianza, array $motivos = [], ?string $transformacion = null, array $extra = [], ?string $etiqueta = null, array $alternativas = []): ValorDetectado
    {
        return new ValorDetectado($campo, $valor, self::original($c), $transformacion, $confianza, $motivos, $hoja, $c?->fila, $c?->columna, $c?->referencia(), $c?->origen, ValorDetectado::esDesactualizable($c), $alternativas, $extra, $etiqueta);
    }

    public static function faltante(string $campo, ?CeldaLeida $c, string $hoja, ?string $etiqueta = null, ?string $motivo = null): ValorDetectado
    {
        return ValorDetectado::faltante($campo, [$motivo ?? self::motivoVacia($c)], $hoja, $c, $etiqueta);
    }

    /** Texto limpio de una celda con dato (números enteros sin «.0»), o null. */
    public static function textoDe(?CeldaLeida $c): ?string
    {
        if ($c === null || ! $c->tieneDato()) {
            return null;
        }
        $v = $c->valor;
        if (is_bool($v)) {
            return $v ? 'TRUE' : 'FALSE';
        }
        if (is_float($v) && floor($v) === $v && abs($v) < 1e15) {
            return (string) (int) $v;
        }

        return Texto::limpiar((string) $v);
    }

    // ---------------------------------------------------------------- participantes

    public static function nombre(?CeldaLeida $c, string $hoja): ValorDetectado
    {
        $t = self::textoDe($c);
        if ($t === null || $t === '') {
            return self::faltante('nombre_completo', $c, $hoja);
        }
        $motivos = [];
        if (Texto::iniciaConFormula($t)) {
            $motivos[] = 'empieza_por_simbolo_de_formula';
        }
        if (Texto::alfanumericos($t) < 3) {
            $motivos[] = 'nombre_demasiado_corto';
        }
        if (preg_match('/^[\p{N}\s.,\-]+$/u', $t) === 1) {
            $motivos[] = 'nombre_solo_numeros';
        }
        if (Texto::tieneControl($t) || ! Texto::utf8Valido($t)) {
            $motivos[] = 'caracteres_no_validos';
        }
        $cambio = $t !== (string) $c->valor ? 'espacios_normalizados' : null;

        return self::valor('nombre_completo', $t, $c, $hoja, $motivos === [] ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO, $motivos, $cambio);
    }

    /**
     * Documento. Se conserva como TEXTO (ceros a la izquierda incluidos). Si el valor llegó como número, se avisa: un cero inicial perdido ya no se puede
     * recuperar. Un prefijo de tipo («C.C. 123») se separa solo como propuesta del tipo, nunca como confirmación.
     *
     * @return array{0: ValorDetectado, 1: ?array{tipo: string, original: string}}
     */
    public static function documento(?CeldaLeida $c, string $hoja): array
    {
        if ($c === null || ! $c->tieneDato()) {
            return [self::faltante('documento', $c, $hoja), null];
        }
        $motivos = [];
        $extra = [];
        $transformacion = null;
        $v = $c->valor;
        if (is_int($v) || is_float($v)) {
            if (is_float($v) && (floor($v) !== $v || abs($v) >= 1e15)) {
                return [self::valor('documento', null, $c, $hoja, ValorDetectado::AMBIGUO, ['numero_no_entero_o_demasiado_largo']), null];
            }
            $texto = (string) (is_float($v) ? (int) $v : $v);
            $extra['llego_como_numero'] = true;
            $transformacion = 'numero_a_texto';
        } else {
            $texto = Texto::limpiar((string) (is_bool($v) ? '' : $v));
        }
        if ($texto === '') {
            return [self::faltante('documento', $c, $hoja), null];
        }

        $prefijo = null;
        if (preg_match('/^(c\.?\s?c\.?|c\.?\s?e\.?|t\.?\s?i\.?|pasaporte|nit|pep|ppt|cedula(?:\s+de\s+(?:ciudadania|extranjeria))?)\s*[:\-.#]?\s*(.*\d.*)$/iu', $texto, $m) === 1) {
            $tipo = self::tipoCanonico($m[1]);
            if ($tipo !== null) {
                $prefijo = ['tipo' => $tipo, 'original' => $m[1]];
                $texto = Texto::limpiar($m[2]);
                $transformacion = 'prefijo_de_tipo_separado';
            }
        }
        if (preg_match('/^\d{1,3}([.\s]\d{3})+$/', $texto) === 1) {
            $texto = str_replace(['.', ' '], '', $texto);
            $transformacion = ($transformacion ? $transformacion.'; ' : '').'separadores_de_miles_quitados';
        }

        if (Texto::iniciaConFormula($texto)) {
            $motivos[] = 'empieza_por_simbolo_de_formula';
        }
        if (Texto::alfanumericos($texto) < 3) {
            $motivos[] = 'documento_demasiado_corto';
        }
        if (Texto::tieneControl($texto) || ! Texto::utf8Valido($texto)) {
            $motivos[] = 'caracteres_no_validos';
        }

        return [self::valor('documento', $texto, $c, $hoja, $motivos === [] ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO, $motivos, $transformacion, $extra), $prefijo];
    }

    /** @param  array{tipo: string, original: string}|null  $prefijo  tipo salido del prefijo del documento */
    public static function tipoDocumento(?CeldaLeida $c, string $hoja, bool $hayColumna, ?array $prefijo, ?CeldaLeida $celdaDocumento): ValorDetectado
    {
        $texto = self::textoDe($c);
        if ($texto !== null && $texto !== '') {
            $tipo = self::tipoCanonico($texto);
            if ($tipo !== null) {
                return self::valor('tipo_documento', $tipo, $c, $hoja, ValorDetectado::CONFIABLE, [], Texto::encabezado($texto) === Texto::encabezado($tipo) ? null : 'tipo_normalizado');
            }
            if (self::esPendiente($texto)) {
                return self::faltante('tipo_documento', $c, $hoja, null, 'tipo_declarado_por_verificar');
            }

            return self::valor('tipo_documento', null, $c, $hoja, ValorDetectado::AMBIGUO, ['tipo_no_reconocido']);
        }
        if ($prefijo !== null) {
            return self::valor('tipo_documento', $prefijo['tipo'], $celdaDocumento, $hoja, ValorDetectado::AMBIGUO, ['tipo_tomado_del_prefijo_del_documento'], 'prefijo_de_tipo_separado');
        }

        return self::faltante('tipo_documento', $c, $hoja, null, $hayColumna ? null : 'sin_columna_de_tipo_de_documento');
    }

    /** Tipos que reconoce el importador actual; todo lo demás se informa tal cual, sin suponer equivalencias. */
    public static function tipoCanonico(string $texto): ?string
    {
        return match (Texto::encabezado($texto)) {
            'cc', 'cedula', 'cedulaciudadania', 'cedulasdeciudadania', 'cedulaasciudadania', 'ceduladeciudadania' => 'C.C.',
            'ce', 'cedulaextranjeria', 'cedulasdeextranjeria', 'cedulaextranjera', 'cedulaextranjeria' => 'C.E.',
            'ti', 'tarjetaidentidad', 'tarjetadeidentidad' => 'T.I.',
            'pasaporte' => 'PASAPORTE',
            'nit' => 'NIT',
            'pep' => 'PEP',
            'ppt' => 'PPT',
            default => null,
        };
    }

    private static function esPendiente(string $texto): bool
    {
        return in_array(Texto::encabezado($texto), ['pendiente', 'porverificar', 'sinconfirmar', 'noconfirmado', 'verificar', 'sintipo', 'na', 'nd'], true);
    }

    public static function correo(?CeldaLeida $c, string $hoja): ValorDetectado
    {
        $t = self::textoDe($c);
        if ($t === null || $t === '') {
            return self::faltante('correo', $c, $hoja);
        }
        $validos = [];
        $invalidos = 0;
        foreach (preg_split('/[;,\s]+/u', $t, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            if (Correo::estadoDe(Correo::normalizar($token)) === Correo::ESTADO_VALIDO) {
                $validos[] = $token;
            } else {
                $invalidos++;
            }
        }
        $validos = array_values(array_unique($validos));
        if ($validos === []) {
            return self::valor('correo', $t, $c, $hoja, ValorDetectado::AMBIGUO, ['formato_de_correo_invalido']);
        }
        $motivos = [];
        if (count($validos) > 1) {
            $motivos[] = 'varios_correos_en_la_celda';
        }
        if ($invalidos > 0) {
            $motivos[] = 'parte_del_contenido_no_es_un_correo_valido';
        }

        return self::valor('correo', $validos[0], $c, $hoja, $motivos === [] ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO, $motivos, $validos[0] === (string) $c->valor ? null : 'primer_correo_valido',
            count($validos) > 1 ? ['correos_adicionales' => count($validos) - 1] : []);
    }

    /** Texto libre sin interpretar (observaciones, sesión…). */
    public static function libre(string $campo, ?CeldaLeida $c, string $hoja): ValorDetectado
    {
        $t = self::textoDe($c);
        if ($t === null || $t === '') {
            return self::faltante($campo, $c, $hoja);
        }

        return self::valor($campo, $t, $c, $hoja, ValorDetectado::CONFIABLE, [], $t === (string) $c->valor ? null : 'espacios_normalizados');
    }

    /** @return self::MARCA_* */
    public static function marca(?CeldaLeida $c): string
    {
        if ($c === null || ! $c->tieneDato()) {
            return self::MARCA_VACIA;
        }
        if (is_bool($c->valor)) {
            return $c->valor ? self::MARCA_SI : self::MARCA_NO;
        }
        $t = mb_strtolower(Texto::limpiar((string) $c->valor), 'UTF-8');
        $n = Texto::encabezado($t);

        return match (true) {
            in_array($t, ['x', '✓', '✔', '☑', '☒', '✅'], true), in_array($n, ['si', 'true', 'yes', 'y', 'verdadero'], true), $t === '1' => self::MARCA_SI,
            in_array($t, ['-', '–', '✗', '✘', '☐', '0'], true), in_array($n, ['no', 'false', 'falso'], true) => self::MARCA_NO,
            default => self::MARCA_OTRA,
        };
    }

    // ---------------------------------------------------------------- datos del evento

    /** Nombre del evento: varias líneas se unen con « — » (la propuesta; el original queda intacto). */
    public static function evento(CeldaLeida $c, string $hoja, string $etiqueta): ValorDetectado
    {
        $bruto = str_replace(["\r\n", "\r"], "\n", (string) $c->valor);
        $lineas = array_values(array_filter(array_map(fn ($l) => Texto::limpiar($l), explode("\n", $bruto)), fn ($l) => $l !== ''));
        if ($lineas === []) {
            return self::faltante('evento', $c, $hoja, $etiqueta);
        }
        $texto = implode(' — ', $lineas);
        $motivos = [];
        if (count($lineas) > 4) {
            $motivos[] = 'demasiadas_lineas_en_el_nombre';
        }
        if (mb_strlen($texto) > 250) {
            $motivos[] = 'nombre_muy_largo';
        }

        return self::valor('evento', $texto, $c, $hoja, $motivos === [] ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO, $motivos,
            count($lineas) > 1 ? 'lineas_unidas_con_raya' : ($texto === $bruto ? null : 'espacios_normalizados'), [], $etiqueta);
    }

    /** Modalidad: siempre LITERAL (solo se normalizan los espacios); no se traduce a ningún catálogo. */
    public static function modalidad(CeldaLeida $c, string $hoja, string $etiqueta): ValorDetectado
    {
        $t = self::textoDe($c);
        if ($t === null || $t === '') {
            return self::faltante('modalidad', $c, $hoja, $etiqueta);
        }
        $motivos = [];
        if (mb_strlen($t) > 120) {
            $motivos[] = 'texto_muy_largo';
        }

        return self::valor('modalidad', $t, $c, $hoja, $motivos === [] ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO, $motivos, $t === (string) $c->valor ? null : 'espacios_normalizados', [], $etiqueta);
    }

    /** Intensidad en horas: «Módulo 5 Horas» → «5 HORAS». Dos cifras distintas o una cifra sin unidad es ambiguo. */
    public static function intensidad(string $campo, CeldaLeida $c, string $hoja, ?string $etiqueta, bool $etiquetaDiceHoras): ValorDetectado
    {
        $v = $c->valor;
        if (is_int($v) || is_float($v)) {
            if ($v <= 0 || $v > 1000) {
                return self::valor($campo, null, $c, $hoja, ValorDetectado::AMBIGUO, ['cantidad_de_horas_fuera_de_rango'], null, [], $etiqueta);
            }
            $n = self::numeroTexto((float) $v);

            return self::valor($campo, $n.' HORAS', $c, $hoja, $etiquetaDiceHoras ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO,
                $etiquetaDiceHoras ? [] : ['numero_sin_unidad'], 'numero_a_horas', ['horas' => (float) $v], $etiqueta);
        }
        $t = Texto::limpiar((string) $v);
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(horas?|hrs?|h)?\b/iu', $t, $m, PREG_SET_ORDER) < 1) {
            return self::valor($campo, null, $c, $hoja, ValorDetectado::AMBIGUO, ['sin_cantidad_de_horas'], null, [], $etiqueta);
        }
        $cifras = array_values(array_unique(array_map(fn ($x) => str_replace(',', '.', $x[1]), $m)));
        if (count($cifras) > 1) {
            return self::valor($campo, null, $c, $hoja, ValorDetectado::AMBIGUO, ['varias_cantidades_de_horas'], null, ['candidatas' => $cifras], $etiqueta);
        }
        $horas = (float) $cifras[0];
        if ($horas <= 0 || $horas > 1000) {
            return self::valor($campo, null, $c, $hoja, ValorDetectado::AMBIGUO, ['cantidad_de_horas_fuera_de_rango'], null, [], $etiqueta);
        }
        $conUnidad = collect($m)->contains(fn ($x) => ($x[2] ?? '') !== '') || $etiquetaDiceHoras;

        return self::valor($campo, self::numeroTexto($horas).' HORAS', $c, $hoja, $conUnidad ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO,
            $conUnidad ? [] : ['numero_sin_unidad'], 'horas_extraidas_del_texto', ['horas' => $horas], $etiqueta);
    }

    private static function numeroTexto(float $n): string
    {
        return floor($n) === $n ? (string) (int) $n : str_replace('.', ',', rtrim(rtrim(number_format($n, 2, '.', ''), '0'), '.'));
    }

    /**
     * Fecha(s) del evento. Propone «16 DE OCTUBRE DE 2026» y deja la fecha en ISO en `extra['iso']`.
     * Ambiguo: día/mes intercambiables (03/04/2026), varias fechas, número de serie sin formato de fecha o texto no reconocido.
     */
    public static function fecha(CeldaLeida $c, string $hoja, ?string $etiqueta): ValorDetectado
    {
        $v = $c->valor;
        $motivos = [];
        $fechas = [];
        $transformacion = null;

        if ($c->tipo === CeldaLeida::FECHA) {
            if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', (string) $v, $m) === 1) {
                $fechas[] = [(int) $m[1], (int) $m[2], (int) $m[3]];
                $transformacion = 'fecha_de_la_celda';
            }
        } elseif (is_int($v) || is_float($v)) {
            if ($v >= 20000 && $v <= 80000) {
                $dia = (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor($v).' days');
                $fechas[] = [(int) $dia->format('Y'), (int) $dia->format('n'), (int) $dia->format('j')];
                $motivos[] = 'numero_de_serie_sin_formato_de_fecha';
                $transformacion = 'serie_de_excel_a_fecha';
            }
        } else {
            [$fechas, $motivos] = self::fechasDeTexto(Texto::limpiar((string) $v));
            $transformacion = 'fecha_leida_del_texto';
        }

        if ($fechas === []) {
            return self::valor('fecha', null, $c, $hoja, ValorDetectado::AMBIGUO, [...$motivos, 'formato_de_fecha_no_reconocido'], null, [], $etiqueta);
        }
        foreach ($fechas as [$a, $mes, $d]) {
            if (! checkdate($mes, $d, $a)) {
                return self::valor('fecha', null, $c, $hoja, ValorDetectado::AMBIGUO, [...$motivos, 'fecha_inexistente'], null, [], $etiqueta);
            }
            if ($a < 2000 || $a > 2100) {
                $motivos[] = 'año_fuera_de_rango_esperado';
            }
        }
        $fechas = array_values(array_unique($fechas, SORT_REGULAR));
        $isos = array_map(fn ($f) => sprintf('%04d-%02d-%02d', $f[0], $f[1], $f[2]), $fechas);
        if (count($fechas) > 1) {
            $motivos[] = 'varias_fechas';
        }

        return self::valor('fecha', self::textoFecha($fechas), $c, $hoja, $motivos === [] ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO,
            array_values(array_unique($motivos)), $transformacion, ['iso' => $isos[0], 'isos' => $isos], $etiqueta);
    }

    /** @return array{0: list<array{0:int,1:int,2:int}>, 1: list<string>} */
    private static function fechasDeTexto(string $t): array
    {
        $n = mb_strtolower($t, 'UTF-8');
        $d = \Normalizer::normalize($n, \Normalizer::FORM_D);
        $n = preg_replace('/\p{Mn}+/u', '', $d === false ? $n : $d) ?? $n;
        $fechas = [];
        $motivos = [];
        $mes = implode('|', array_keys(self::MESES));

        // ISO: 2026-10-16
        if (preg_match_all('/\b(\d{4})-(\d{1,2})-(\d{1,2})\b/', $n, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $fechas[] = [(int) $x[1], (int) $x[2], (int) $x[3]];
            }

            return [$fechas, $motivos];
        }
        // 16 y 17 de octubre de 2026 / 16, 17 y 18 de octubre / 16 al 18 de octubre de 2026
        if (preg_match('/\b(\d{1,2}(?:\s*(?:,|y|e|al|a|-)\s*\d{1,2})+)\s+de\s+('.$mes.')\.?(?:\s+(?:de|del)?\s*(\d{4}))?/u', $n, $m) === 1) {
            preg_match_all('/\d{1,2}/', $m[1], $dias);
            $rango = preg_match('/\b(al|a|-)\b|-/u', $m[1]) === 1 && ! preg_match('/[,y]/u', $m[1]);
            if ($rango && count($dias[0]) === 2) {
                $motivos[] = 'rango_de_fechas';
            }
            if (! isset($m[3]) || $m[3] === '') {
                $motivos[] = 'sin_año';

                return [[], $motivos];
            }
            foreach ($dias[0] as $dia) {
                $fechas[] = [(int) $m[3], self::MESES[$m[2]], (int) $dia];
            }

            return [$fechas, $motivos];
        }
        // 16 de octubre de 2026 / 16-oct-2026 / 16 oct 2026
        if (preg_match_all('/\b(\d{1,2})\s*(?:de|-|\/|\.)?\s*('.$mes.')\.?\s*(?:de|del|-|\/|\.)?\s*(\d{4})\b/u', $n, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $fechas[] = [(int) $x[3], self::MESES[$x[2]], (int) $x[1]];
            }

            return [$fechas, $motivos];
        }
        // 16/10/2026, 16-10-2026, 16.10.2026 (día primero, como se escribe en Colombia)
        if (preg_match_all('/\b(\d{1,2})[\/\-.](\d{1,2})[\/\-.](\d{4})\b/', $n, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) {
                $a = (int) $x[1];
                $b = (int) $x[2];
                if ($a <= 12 && $b <= 12 && $a !== $b) {
                    $motivos[] = 'dia_y_mes_intercambiables';
                }
                if ($b > 12 && $a <= 12) {          // 10/16/2026: evidentemente mes/día
                    $fechas[] = [(int) $x[3], $a, $b];
                    $motivos[] = 'formato_mes_dia';
                } else {
                    $fechas[] = [(int) $x[3], $b, $a];
                }
            }

            return [$fechas, $motivos];
        }

        return [[], $motivos];
    }

    /** @param  list<array{0:int,1:int,2:int}>  $fechas */
    private static function textoFecha(array $fechas): string
    {
        usort($fechas, fn ($x, $y) => $x <=> $y);
        $partes = array_map(fn ($f) => $f[2].' DE '.self::MESES_TEXTO[$f[1]].' DE '.$f[0], $fechas);
        if (count($partes) === 1) {
            return $partes[0];
        }
        // Mismo mes y año: «16 Y 17 DE OCTUBRE DE 2026».
        $mismoMes = count(array_unique(array_map(fn ($f) => $f[0].'-'.$f[1], $fechas))) === 1;
        if ($mismoMes) {
            $dias = array_map(fn ($f) => (string) $f[2], $fechas);
            $ultimo = array_pop($dias);

            return implode(', ', $dias).' Y '.$ultimo.' DE '.self::MESES_TEXTO[$fechas[0][1]].' DE '.$fechas[0][0];
        }

        return implode(' Y ', $partes);
    }
}
