<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

use App\Models\CredentialFlow\Correo;
use App\Support\CredentialFlow\Participantes\Excel\CeldaLeida;
use App\Support\CredentialFlow\Participantes\Excel\HojaLeida;
use App\Support\CredentialFlow\Participantes\Excel\LibroLeido;
use App\Support\CredentialFlow\Participantes\Texto;

/**
 * Analizador inteligente de un libro ya leído (F0). Solo LEE la estructura en memoria: no abre archivos, no escribe en la base de datos, no ejecuta fórmulas
 * y no decide nada por la persona. Devuelve propuestas con trazabilidad y un nivel de confianza (confiable / ambiguo / faltante).
 *
 * Qué hace:
 *  - busca la tabla de participantes en cualquier fila de cualquier hoja (por sus encabezados, con alias normalizados);
 *  - la separa de los bloques de datos del evento (rótulo → valor, antes o después de la tabla);
 *  - propone nombre del evento, fecha, modalidad (literal), intensidad y demás datos administrativos que encuentre;
 *  - extrae nombres, documentos, correos, observaciones y lo que el archivo dice de la asistencia (sin decidir elegibilidad);
 *  - nunca inventa ciudad, tipo de documento, intensidad individual ni equivalencias de estados desconocidos.
 */
final class AnalizadorExcel
{
    public const PARTICIPANTES_MAX = 1000;

    /** Filas seguidas sin participante que cierran la tabla. */
    public const HUECO_MAX = 3;

    /** Texto máximo de un encabezado o rótulo. */
    private const ROTULO_MAX = 80;

    /** Columnas a la derecha de un rótulo donde se busca su valor. */
    private const ALCANCE_VALOR = 8;

    /** Palabras que abren un pie de página y cierran la tabla (texto sin tildes y en minúsculas). */
    private const PIE = '/^(total(?:es)?|firma|elabor[oa]|revis[oa]|aprob[oa]|notas?|fecha de|gestora)\b/u';

    /**
     * @param  list<array{correo?: ?string, documento?: ?string}>  $contactosQa  contactos QA permanentes (opcional); solo sirven para marcar coincidencias
     */
    public function analizar(LibroLeido $libro, array $contactosQa = []): AnalisisExcel
    {
        $inicio = microtime(true);
        memory_reset_peak_usage();
        $base = memory_get_usage();
        $advertencias = [];
        $mas = function (string $codigo, int $n = 1) use (&$advertencias): void {
            if ($n > 0) {
                $advertencias[$codigo] = ($advertencias[$codigo] ?? 0) + $n;
            }
        };
        if ($libro->truncado()) {
            $mas('lectura_truncada');
        }

        // 1) Tablas candidatas en todas las hojas.
        $tablas = [];       // list<TablaDetectada>
        $regiones = [];     // hoja => list<array{0:int,1:int}> filas de encabezado y cuerpo de las tablas candidatas
        $qa = $this->indiceQa($contactosQa);
        foreach ($libro->hojas as $hoja) {
            foreach ($this->tablasDe($hoja, $qa) as $t) {
                $tablas[] = $t;
                $regiones[$hoja->nombre][] = [$t->filaEncabezado, max($t->filaFin, $t->filaEncabezado)];
            }
        }

        // 2) Elegir la principal: más participantes; a igualdad, más columnas reconocidas; las hojas ocultas pasan después.
        $visibles = [];
        foreach ($libro->hojas as $h) {
            $visibles[$h->nombre] = $h->visible;
        }
        usort($tablas, function (TablaDetectada $a, TablaDetectada $b) use ($visibles) {
            return [(int) ($visibles[$b->hoja] ?? false), count($b->participantes), count($b->columnas), $a->filaEncabezado]
                <=> [(int) ($visibles[$a->hoja] ?? false), count($a->participantes), count($a->columnas), $b->filaEncabezado];
        });
        $tabla = $tablas[0] ?? null;
        $alternativas = [];
        foreach (array_slice($tablas, 1) as $t) {
            if (count($t->participantes) > 0) {
                $alternativas[] = ['hoja' => $t->hoja, 'fila_encabezado' => $t->filaEncabezado, 'participantes' => count($t->participantes), 'columnas' => count($t->columnas)];
            }
        }
        if ($tabla !== null && $alternativas !== []) {
            $mas('tablas_competidoras', count($alternativas));
            $mayor = max(array_column($alternativas, 'participantes'));
            if ($mayor * 2 >= count($tabla->participantes)) {
                $tabla = $this->conConfianza($tabla, ValorDetectado::AMBIGUO);
            }
        }
        if ($tabla === null) {
            $mas('tabla_de_participantes_no_encontrada');
        } else {
            $this->advertenciasDeTabla($tabla, $mas);
        }

        // 3) Datos del evento (fuera de las tablas), primero en la hoja de la tabla y luego en el resto.
        $evento = $this->datosDelEvento($libro, $tabla?->hoja, $regiones);
        foreach ($evento as $v) {
            if ($v->desactualizable) {
                $mas('valores_del_evento_desactualizables');
            }
        }

        // 4) Hojas y métricas.
        $hojas = [];
        $conEvento = [];
        foreach ($evento as $v) {
            if ($v->hoja !== null) {
                $conEvento[$v->hoja] = true;
            }
        }
        foreach ($libro->hojas as $h) {
            $rol = match (true) {
                $tabla !== null && $tabla->hoja === $h->nombre => 'tabla_participantes',
                isset($conEvento[$h->nombre]) => 'datos_evento',
                isset($regiones[$h->nombre]) => 'tabla_alternativa',
                default => 'sin_datos_relevantes',
            };
            $hojas[] = ['nombre' => $h->nombre, 'visible' => $h->visible, 'rol' => $rol, 'celdas' => $h->cantidad()];
        }
        $lista = [];
        foreach ($advertencias as $codigo => $n) {
            $lista[] = ['codigo' => $codigo, 'cantidad' => $n];
        }

        return new AnalisisExcel($tabla, $evento, $alternativas, $hojas, $lista, [
            'segundos' => round(microtime(true) - $inicio, 3),
            'memoria_analisis_mb' => round(max(0, memory_get_peak_usage() - $base) / 1048576, 1),
            'memoria_lector_mb' => round($libro->memoriaPicoBytes / 1048576, 1),
        ]);
    }

    // ================================================================= tablas

    /** @return list<TablaDetectada> */
    private function tablasDe(HojaLeida $hoja, array $qa): array
    {
        $tablas = [];
        $hasta = 0;
        foreach ($hoja->filas() as $fila) {
            if ($fila <= $hasta) {
                continue;
            }
            $enc = $this->encabezadoEn($hoja, $fila);
            if ($enc === null) {
                continue;
            }
            $tabla = $this->construirTabla($hoja, $fila, $enc, $qa);
            $tablas[] = $tabla;
            $hasta = max($tabla->filaFin, $fila);
        }

        return $tablas;
    }

    /**
     * ¿La fila es un encabezado de participantes? Necesita al menos dos campos distintos (sin contar «item») y que uno sea el nombre o el documento.
     *
     * @return array{columnas: array<string, ColumnaDetectada>, ignorados: list<string>}|null
     */
    private function encabezadoEn(HojaLeida $hoja, int $fila): ?array
    {
        $reconocidas = [];
        $ignorados = [];
        $textos = 0;
        foreach ($hoja->fila($fila) as $col => $c) {
            if ($c->tipo !== CeldaLeida::TEXTO || ! $c->tieneDato()) {
                continue;
            }
            $texto = Texto::limpiar((string) $c->valor);
            $textos++;
            if ($texto === '' || mb_strlen($texto) > self::ROTULO_MAX || str_contains($texto, '@')) {
                $ignorados[] = $col;

                continue;
            }
            $r = Aliases::campoDeColumna($texto);
            if ($r === null) {
                $ignorados[] = $col;

                continue;
            }
            $reconocidas[] = ['col' => $col, 'texto' => $texto, 'campo' => $r[0], 'metodo' => $r[1]];
        }
        $campos = array_unique(array_column($reconocidas, 'campo'));
        $sinItem = array_diff($campos, ['item']);
        if (count($sinItem) < 2 || (! in_array('nombre_completo', $campos, true) && ! in_array('documento', $campos, true))) {
            return null;
        }

        $porCampo = [];
        foreach ($reconocidas as $r) {
            $porCampo[$r['campo']][] = $r;
        }
        $columnas = [];
        foreach ($porCampo as $campo => $lista) {
            $exactas = array_values(array_filter($lista, fn ($r) => $r['metodo'] === 'exacto'));
            $elegida = ($exactas[0] ?? $lista[0]);
            $otras = array_values(array_filter($lista, fn ($r) => $r['col'] !== $elegida['col']));
            $confiable = $exactas !== [] && count($exactas) === 1 && count($lista) === 1;
            $columnas[$campo] = new ColumnaDetectada(
                $campo, $elegida['col'], self::letra($elegida['col']), $elegida['texto'], $elegida['metodo'],
                $confiable ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO,
                array_map(fn ($r) => ['letra' => self::letra($r['col']), 'encabezado' => $r['texto']], $otras),
            );
        }
        $ign = array_map(fn ($col) => self::letra($col), $ignorados);

        return ['columnas' => $columnas, 'ignorados' => array_slice($ign, 0, 50)];
    }

    /** @param  array{columnas: array<string, ColumnaDetectada>, ignorados: list<string>}  $enc */
    private function construirTabla(HojaLeida $hoja, int $filaEnc, array $enc, array $qa): TablaDetectada
    {
        $columnas = $enc['columnas'];
        $identidad = array_values(array_filter([$columnas['nombre_completo'] ?? null, $columnas['documento'] ?? null, $columnas['correo'] ?? null]));
        $participantes = [];
        $omitidas = 0;
        $hueco = 0;
        $fin = $filaEnc;
        $truncada = false;
        $ultima = max($hoja->filas() ?: [$filaEnc]);

        for ($f = $filaEnc + 1; $f <= $ultima; $f++) {
            if (! $hoja->filaConDatos($f)) {
                if (++$hueco > self::HUECO_MAX) {
                    break;
                }

                continue;
            }
            if ($this->pareceEncabezado($hoja, $f)) {
                break;
            }
            $conIdentidad = false;
            foreach ($identidad as $col) {
                $c = $hoja->celda($f, $col->indice);
                if ($c !== null && $c->tieneDato() && Texto::limpiar((string) $c->valor) !== '') {
                    $conIdentidad = true;
                    break;
                }
            }
            if (! $conIdentidad) {
                if ($this->esPie($hoja, $f, $columnas)) {
                    break;
                }
                $hay = false;
                foreach ($columnas as $col) {
                    if ($hoja->celda($f, $col->indice)?->tieneDato()) {
                        $hay = true;
                        break;
                    }
                }
                if ($hay) {
                    $omitidas++;
                }
                if (++$hueco > self::HUECO_MAX) {
                    break;
                }

                continue;
            }
            if ($this->esPie($hoja, $f, $columnas)) {
                break;
            }
            $hueco = 0;
            if (count($participantes) >= self::PARTICIPANTES_MAX) {
                $truncada = true;
                break;
            }
            $participantes[] = $this->participante($hoja, $f, $columnas, $qa);
            $fin = $f;
        }

        $criticas = array_filter($columnas, fn (ColumnaDetectada $c) => in_array($c->campo, ['nombre_completo', 'documento'], true));
        $confianza = (count($criticas) === 2 && ! in_array(ValorDetectado::AMBIGUO, array_map(fn ($c) => $c->confianza, $criticas), true)) ? ValorDetectado::CONFIABLE : ValorDetectado::AMBIGUO;
        if ($participantes === []) {
            $confianza = ValorDetectado::AMBIGUO;
        }

        return new TablaDetectada($hoja->nombre, $filaEnc, $columnas, $participantes, $omitidas, $truncada, $confianza, $enc['ignorados'], $fin);
    }

    /** Una fila con tres o más encabezados EXACTOS es el comienzo de otra tabla. */
    private function pareceEncabezado(HojaLeida $hoja, int $fila): bool
    {
        $n = [];
        foreach ($hoja->fila($fila) as $c) {
            if ($c->tipo !== CeldaLeida::TEXTO || ! $c->tieneDato() || mb_strlen((string) $c->valor) > self::ROTULO_MAX) {
                continue;
            }
            $r = Aliases::campoDeColumna((string) $c->valor);
            if ($r !== null && $r[1] === 'exacto' && $r[0] !== 'item') {
                $n[$r[0]] = true;
            }
        }

        return count($n) >= 3;
    }

    /** Pie de tabla («Total», «Firma», «Elaboró»…): primera celda con dato de la fila, sin documento ni correo. */
    private function esPie(HojaLeida $hoja, int $fila, array $columnas): bool
    {
        foreach ([$columnas['documento'] ?? null, $columnas['correo'] ?? null] as $col) {
            if ($col !== null && $hoja->celda($fila, $col->indice)?->tieneDato()) {
                return false;
            }
        }
        foreach ($hoja->fila($fila) as $c) {
            if (! $c->tieneDato() || $c->tipo !== CeldaLeida::TEXTO) {
                continue;
            }
            $t = Texto::limpiar((string) $c->valor);

            return Aliases::esRotuloEvento($t) || preg_match(self::PIE, self::sinTildes($t)) === 1;
        }

        return false;
    }

    // ================================================================= participante

    /** @param  array<string, ColumnaDetectada>  $columnas */
    private function participante(HojaLeida $hoja, int $fila, array $columnas, array $qa): ParticipanteDetectado
    {
        $h = $hoja->nombre;
        $celda = fn (string $campo) => isset($columnas[$campo]) ? $hoja->celda($fila, $columnas[$campo]->indice) : null;
        $campos = [];

        $campos['nombre_completo'] = Normalizadores::nombre($celda('nombre_completo'), $h);
        if (! isset($columnas['nombre_completo'])) {
            $campos['nombre_completo'] = Normalizadores::faltante('nombre_completo', null, $h, null, 'sin_columna_de_nombre');
        }

        [$doc, $prefijo] = isset($columnas['documento'])
            ? Normalizadores::documento($celda('documento'), $h)
            : [Normalizadores::faltante('documento', null, $h, null, 'sin_columna_de_documento'), null];
        $campos['documento'] = $doc;
        $campos['tipo_documento'] = Normalizadores::tipoDocumento($celda('tipo_documento'), $h, isset($columnas['tipo_documento']), $prefijo, $celda('documento'));

        $campos['correo'] = isset($columnas['correo']) ? Normalizadores::correo($celda('correo'), $h) : Normalizadores::faltante('correo', null, $h, null, 'sin_columna_de_correo');

        if (isset($columnas['observaciones'])) {
            $campos['observaciones'] = Normalizadores::libre('observaciones', $celda('observaciones'), $h);
        }
        if (isset($columnas['intensidad_individual']) && $celda('intensidad_individual')?->tieneDato()) {
            $campos['intensidad_individual'] = Normalizadores::intensidad('intensidad_individual', $celda('intensidad_individual'), $h, $columnas['intensidad_individual']->encabezado,
                str_contains(Texto::encabezado($columnas['intensidad_individual']->encabezado), 'hora'));
        } elseif (isset($columnas['intensidad_individual'])) {
            $campos['intensidad_individual'] = Normalizadores::faltante('intensidad_individual', $celda('intensidad_individual'), $h);
        }
        if (isset($columnas['modalidad'])) {
            $c = $celda('modalidad');
            $campos['modalidad'] = $c !== null && $c->tieneDato() ? Normalizadores::modalidad($c, $h, $columnas['modalidad']->encabezado) : Normalizadores::faltante('modalidad', $c, $h);
        }
        if (isset($columnas['elegibilidad'])) {
            $campos['elegibilidad'] = $this->elegibilidadDeclarada($celda('elegibilidad'), $h);
        }

        // Asistencia: lo que dice el archivo, sin decidir elegibilidad.
        $senales = [];
        $desconocida = false;
        if (isset($columnas['sesion'])) {
            $c = $celda('sesion');
            $v = Normalizadores::libre('sesion', $c, $h);
            $t = Normalizadores::textoDe($c);
            if ($t !== null && $t !== '') {
                $n = Texto::encabezado($t);
                if (in_array($n, ['completo', 'completa'], true)) {
                    $senales[] = ParticipanteDetectado::SENAL_COMPLETO;
                } elseif (in_array($n, ['cupo', 'cupos'], true)) {
                    $senales[] = ParticipanteDetectado::SENAL_CUPO;
                } else {
                    $desconocida = true;
                    $v = $v->conDuda('estado_de_sesion_desconocido');
                }
            }
            $campos['sesion'] = $v;
        }
        foreach (['certificado_completo' => ParticipanteDetectado::SENAL_COMPLETO, 'seleccion_cupo' => ParticipanteDetectado::SENAL_CUPO] as $campo => $senal) {
            if (! isset($columnas[$campo])) {
                continue;
            }
            $c = $celda($campo);
            $marca = Normalizadores::marca($c);
            $campos[$campo] = $this->valorMarca($campo, $c, $marca, $h);
            if ($marca === Normalizadores::MARCA_SI) {
                $senales[] = $senal;
            } elseif ($marca === Normalizadores::MARCA_OTRA) {
                $desconocida = true;
            }
        }
        $senales = array_values(array_unique($senales));
        $senal = match (true) {
            count($senales) > 1 => ParticipanteDetectado::SENAL_CONFLICTO,
            count($senales) === 1 => $senales[0],
            $desconocida => ParticipanteDetectado::SENAL_DESCONOCIDA,
            default => ParticipanteDetectado::SENAL_NINGUNA,
        };

        $diploma = null;
        if (isset($columnas['diploma'])) {
            $c = $celda('diploma');
            $marca = Normalizadores::marca($c);
            $campos['diploma'] = $this->valorMarca('diploma', $c, $marca, $h);
            $diploma = match ($marca) {
                Normalizadores::MARCA_SI => true,
                Normalizadores::MARCA_NO, Normalizadores::MARCA_VACIA => false,
                default => null,
            };
        }

        // Una columna aproximada o repetida vuelve ambiguos todos sus valores.
        foreach ($columnas as $campo => $col) {
            if ($col->confianza === ValorDetectado::AMBIGUO && isset($campos[$campo])) {
                $campos[$campo] = $campos[$campo]->conDuda($col->alternativas !== [] ? 'columna_repetida_o_dudosa' : 'encabezado_aproximado');
            }
        }
        if (isset($columnas['documento']) && isset($campos['tipo_documento']) && ($columnas['documento']->confianza === ValorDetectado::AMBIGUO)) {
            $campos['tipo_documento'] = $campos['tipo_documento']->conDuda('columna_de_documento_dudosa');
        }

        return new ParticipanteDetectado($fila, $campos, $senal, $diploma, $this->posibleQa($campos, $qa));
    }

    private function valorMarca(string $campo, ?CeldaLeida $c, string $marca, string $hoja): ValorDetectado
    {
        if ($c !== null && in_array($c->origen, [CeldaLeida::FORMULA_SIN_VALOR, CeldaLeida::FORMULA_ERROR], true) || ($c !== null && $c->tipo === CeldaLeida::ERROR)) {
            return Normalizadores::faltante($campo, $c, $hoja);
        }

        return match ($marca) {
            Normalizadores::MARCA_SI => Normalizadores::valor($campo, true, $c, $hoja, ValorDetectado::CONFIABLE),
            Normalizadores::MARCA_NO => Normalizadores::valor($campo, false, $c, $hoja, ValorDetectado::CONFIABLE),
            Normalizadores::MARCA_VACIA => Normalizadores::valor($campo, false, $c, $hoja, ValorDetectado::CONFIABLE, [], 'celda_vacia_como_no_marcada'),
            default => Normalizadores::valor($campo, null, $c, $hoja, ValorDetectado::AMBIGUO, ['marca_no_reconocida']),
        };
    }

    private function elegibilidadDeclarada(?CeldaLeida $c, string $hoja): ValorDetectado
    {
        $t = Normalizadores::textoDe($c);
        if ($t === null || $t === '') {
            return Normalizadores::faltante('elegibilidad', $c, $hoja);
        }
        $canon = match (Texto::encabezado($t)) {
            'elegible' => 'elegible',
            'pendiente' => 'pendiente',
            'noelegible' => 'no_elegible',
            default => null,
        };

        return $canon !== null
            ? Normalizadores::valor('elegibilidad', $canon, $c, $hoja, ValorDetectado::CONFIABLE)
            : Normalizadores::valor('elegibilidad', null, $c, $hoja, ValorDetectado::AMBIGUO, ['estado_desconocido_sin_equivalencia']);
    }

    // ================================================================= QA

    /** @return array{correos: array<string,true>, documentos: array<string,true>} */
    private function indiceQa(array $contactos): array
    {
        $correos = [];
        $docs = [];
        foreach ($contactos as $c) {
            if (! empty($c['correo'])) {
                $correos[Correo::normalizar((string) $c['correo'])] = true;
            }
            if (! empty($c['documento'])) {
                $docs[Texto::claveDocumento((string) $c['documento'])] = true;
            }
        }

        return ['correos' => $correos, 'documentos' => $docs];
    }

    /** @param  array<string, ValorDetectado>  $campos */
    private function posibleQa(array $campos, array $qa): ?string
    {
        $correo = $campos['correo'] ?? null;
        if ($correo !== null && is_string($correo->valor) && isset($qa['correos'][Correo::normalizar($correo->valor)])) {
            return 'correo';
        }
        $doc = $campos['documento'] ?? null;
        if ($doc !== null && is_string($doc->valor) && $doc->valor !== '' && isset($qa['documentos'][Texto::claveDocumento($doc->valor)])) {
            return 'documento';
        }

        return null;
    }

    // ================================================================= advertencias de tabla

    private function advertenciasDeTabla(TablaDetectada $t, callable $mas): void
    {
        $docs = [];
        $correos = [];
        $stale = 0;
        $n = ['numero' => 0, 'sin_doc' => 0, 'sin_correo' => 0, 'tipo' => 0, 'conflicto' => 0, 'desconocida' => 0, 'qa' => 0, 'dd' => 0, 'dc' => 0];
        foreach ($t->participantes as $p) {
            $doc = $p->campos['documento'] ?? null;
            if ($doc !== null) {
                if ($doc->valor === null || $doc->valor === '') {
                    $n['sin_doc']++;
                } else {
                    $k = Texto::claveDocumento((string) $doc->valor);
                    $docs[$k] = ($docs[$k] ?? 0) + 1;
                    if ($docs[$k] === 2) {
                        $n['dd']++;
                    }
                }
                if (! empty($doc->extra['llego_como_numero'])) {
                    $n['numero']++;
                }
            }
            $cor = $p->campos['correo'] ?? null;
            if ($cor !== null) {
                if ($cor->confianza === ValorDetectado::FALTANTE) {
                    $n['sin_correo']++;
                } elseif (is_string($cor->valor)) {
                    $k = Correo::normalizar($cor->valor);
                    $correos[$k] = ($correos[$k] ?? 0) + 1;
                    if ($correos[$k] === 2) {
                        $n['dc']++;
                    }
                }
            }
            if (($p->campos['tipo_documento']->confianza ?? null) !== ValorDetectado::CONFIABLE) {
                $n['tipo']++;
            }
            if ($p->senal === ParticipanteDetectado::SENAL_CONFLICTO) {
                $n['conflicto']++;
            }
            if ($p->senal === ParticipanteDetectado::SENAL_DESCONOCIDA) {
                $n['desconocida']++;
            }
            if ($p->posibleQa !== null) {
                $n['qa']++;
            }
            foreach ($p->campos as $v) {
                if ($v->desactualizable) {
                    $stale++;
                }
            }
        }
        $mas('valores_de_participantes_desactualizables', $stale);
        $mas('documentos_llegaron_como_numero', $n['numero']);
        $mas('participantes_sin_documento', $n['sin_doc']);
        $mas('participantes_sin_correo', $n['sin_correo']);
        $mas('tipo_de_documento_sin_confirmar', $n['tipo']);
        $mas('asistencia_en_conflicto', $n['conflicto']);
        $mas('asistencia_desconocida', $n['desconocida']);
        $mas('posibles_contactos_qa', $n['qa']);
        $mas('documentos_repetidos', $n['dd']);
        $mas('correos_repetidos', $n['dc']);
        $mas('columnas_ambiguas', count(array_filter($t->columnas, fn (ColumnaDetectada $c) => $c->confianza === ValorDetectado::AMBIGUO)));
        if ($t->truncada) {
            $mas('participantes_truncados');
        }
        if (! isset($t->columnas['tipo_documento'])) {
            $mas('sin_columna_de_tipo_de_documento');
        }
    }

    private function conConfianza(TablaDetectada $t, string $confianza): TablaDetectada
    {
        return new TablaDetectada($t->hoja, $t->filaEncabezado, $t->columnas, $t->participantes, $t->filasOmitidas, $t->truncada, $confianza, $t->encabezadosNoReconocidos, $t->filaFin);
    }

    // ================================================================= datos del evento

    /**
     * Rótulo → valor fuera de las tablas. Se busca primero en la hoja de la tabla; el resto de hojas solo si ahí no aparece el dato.
     *
     * @param  array<string, list<array{0:int,1:int}>>  $regiones
     * @return array<string, ValorDetectado>
     */
    private function datosDelEvento(LibroLeido $libro, ?string $hojaTabla, array $regiones): array
    {
        $orden = [];
        foreach ($libro->hojas as $h) {
            $orden[] = $h;
        }
        usort($orden, fn (HojaLeida $a, HojaLeida $b) => [(int) ($b->nombre === $hojaTabla), (int) $b->visible] <=> [(int) ($a->nombre === $hojaTabla), (int) $a->visible]);

        $porCampo = [];     // campo => list<ValorDetectado>
        foreach ($orden as $hoja) {
            $encontrados = [];
            foreach ($hoja->filas() as $fila) {
                if ($this->enRegion($regiones[$hoja->nombre] ?? [], $fila)) {
                    continue;
                }
                foreach ($hoja->fila($fila) as $col => $c) {
                    if ($c->tipo !== CeldaLeida::TEXTO || ! $c->tieneDato()) {
                        continue;
                    }
                    $v = $this->valorDeRotulo($hoja, $c);
                    if ($v !== null) {
                        $encontrados[$v->campo][] = $v;
                    }
                }
            }
            foreach ($encontrados as $campo => $lista) {
                if (! isset($porCampo[$campo]) || ! $this->hayDato($porCampo[$campo])) {
                    $esOtraHoja = $hojaTabla !== null && $hoja->nombre !== $hojaTabla;
                    foreach ($lista as $v) {
                        $porCampo[$campo][] = $esOtraHoja && $v->confianza !== ValorDetectado::FALTANTE ? $v->conDuda('dato_de_otra_hoja') : $v;
                    }
                }
            }
        }

        $resultado = [];
        foreach (array_keys(Aliases::EVENTO) as $campo) {
            $resultado[$campo] = $this->resolver($campo, $porCampo[$campo] ?? []);
        }

        return $resultado;
    }

    /** @param  list<ValorDetectado>  $lista */
    private function hayDato(array $lista): bool
    {
        foreach ($lista as $v) {
            if ($v->confianza !== ValorDetectado::FALTANTE) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<array{0:int,1:int}>  $regiones */
    private function enRegion(array $regiones, int $fila): bool
    {
        foreach ($regiones as [$a, $b]) {
            if ($fila >= $a && $fila <= $b) {
                return true;
            }
        }

        return false;
    }

    /** @param  list<ValorDetectado>  $lista */
    private function resolver(string $campo, array $lista): ValorDetectado
    {
        if ($lista === []) {
            return ValorDetectado::faltante($campo, ['rotulo_no_encontrado']);
        }
        $conDato = array_values(array_filter($lista, fn (ValorDetectado $v) => $v->confianza !== ValorDetectado::FALTANTE));
        if ($conDato === []) {
            return $lista[0];
        }
        $primero = $conDato[0];
        $distintos = [];
        foreach ($conDato as $v) {
            $distintos[mb_strtolower((string) $v->valor, 'UTF-8')] ??= $v;
        }
        if (count($distintos) <= 1) {
            return $primero;
        }
        $alternativas = [];
        foreach (array_slice(array_values($distintos), 1) as $v) {
            $alternativas[] = ['valor' => (string) $v->valor, 'referencia' => ($v->hoja !== null ? $v->hoja.'!' : '').$v->referencia];
        }

        return new ValorDetectado($primero->campo, $primero->valor, $primero->original, $primero->transformacion, ValorDetectado::AMBIGUO, [...$primero->motivos, 'varios_candidatos_con_valores_distintos'],
            $primero->hoja, $primero->fila, $primero->columna, $primero->referencia, $primero->origenCelda, $primero->desactualizable, $alternativas, $primero->extra, $primero->etiqueta);
    }

    /** Si la celda es un rótulo del evento, devuelve el valor propuesto (o faltante); si no, null. */
    private function valorDeRotulo(HojaLeida $hoja, CeldaLeida $c): ?ValorDetectado
    {
        $texto = Texto::limpiar((string) $c->valor);
        if ($texto === '') {
            return null;
        }
        $inline = null;
        $pos = mb_strpos($texto, ':');
        if ($pos !== false && $pos > 0) {
            $rot = trim(mb_substr($texto, 0, $pos));
            $resto = trim(mb_substr($texto, $pos + 1));
            if ($resto !== '' && mb_strlen($rot) <= self::ROTULO_MAX) {
                $r = Aliases::campoDeEvento($rot);
                if ($r !== null && $r[1] === 'exacto') {
                    $inline = [$r, $rot, $resto];
                }
            }
        }
        if ($inline !== null) {
            [$r, $rotulo, $resto] = $inline;
            $celdaValor = new CeldaLeida($c->fila, $c->columna, $resto, CeldaLeida::TEXTO, $c->origen, $c->funcion, null, $c->importrange, $c->discrepancia);

            return $this->normalizarEvento($r[0], $celdaValor, $hoja->nombre, $rotulo, $r[1]);
        }
        if (mb_strlen($texto) > self::ROTULO_MAX) {
            return null;
        }
        $r = Aliases::campoDeEvento($texto);
        if ($r === null) {
            return null;
        }
        [$campo, $metodo] = $r;

        // Valor: la primera celda con dato a la derecha; si hay otro rótulo antes, no hay valor y se mira debajo.
        $vacia = null;
        for ($k = $c->columna + 1; $k <= $c->columna + self::ALCANCE_VALOR; $k++) {
            $d = $hoja->celda($c->fila, $k);
            if ($d === null) {
                continue;
            }
            if ($d->tipo === CeldaLeida::TEXTO && $d->tieneDato() && Aliases::esRotuloEvento((string) $d->valor)) {
                break;
            }
            if ($d->tieneDato()) {
                return $this->normalizarEvento($campo, $d, $hoja->nombre, $texto, $metodo);
            }
            $vacia ??= $d;
        }
        $abajo = $hoja->celda($c->fila + 1, $c->columna);
        if ($abajo !== null && $abajo->tieneDato() && ! ($abajo->tipo === CeldaLeida::TEXTO && Aliases::esRotuloEvento((string) $abajo->valor))) {
            return $this->normalizarEvento($campo, $abajo, $hoja->nombre, $texto, $metodo)->conDuda('valor_debajo_del_rotulo');
        }

        return Normalizadores::faltante($campo, $vacia, $hoja->nombre, $texto, $vacia === null ? 'rotulo_sin_valor' : null);
    }

    private function normalizarEvento(string $campo, CeldaLeida $d, string $hoja, string $rotulo, string $metodo): ValorDetectado
    {
        $v = match ($campo) {
            'evento' => Normalizadores::evento($d, $hoja, $rotulo),
            'fecha' => Normalizadores::fecha($d, $hoja, $rotulo),
            'modalidad' => Normalizadores::modalidad($d, $hoja, $rotulo),
            'intensidad_horaria' => Normalizadores::intensidad('intensidad_horaria', $d, $hoja, $rotulo, str_contains(Texto::encabezado($rotulo), 'hora')),
            default => $this->textoEvento($campo, $d, $hoja, $rotulo),
        };

        return $metodo === 'exacto' ? $v : $v->conDuda('rotulo_aproximado');
    }

    private function textoEvento(string $campo, CeldaLeida $d, string $hoja, string $rotulo): ValorDetectado
    {
        $t = Normalizadores::textoDe($d);
        if ($t === null || $t === '') {
            return Normalizadores::faltante($campo, $d, $hoja, $rotulo);
        }

        return Normalizadores::valor($campo, $t, $d, $hoja, mb_strlen($t) > 200 ? ValorDetectado::AMBIGUO : ValorDetectado::CONFIABLE, mb_strlen($t) > 200 ? ['texto_muy_largo'] : [],
            $t === (string) $d->valor ? null : 'espacios_normalizados', [], $rotulo);
    }

    private static function sinTildes(string $t): string
    {
        $d = \Normalizer::normalize(mb_strtolower($t, 'UTF-8'), \Normalizer::FORM_D);

        return preg_replace('/\p{Mn}+/u', '', $d === false ? $t : $d) ?? $t;
    }

    private static function letra(int $col): string
    {
        $letras = '';
        do {
            $letras = chr(65 + $col % 26).$letras;
            $col = intdiv($col, 26) - 1;
        } while ($col >= 0);

        return $letras;
    }
}
