<?php

namespace App\Support\CredentialFlow\Importacion;

use App\Support\CredentialFlow\Participantes\Excel\Analisis\Normalizadores;
use App\Support\CredentialFlow\Participantes\Texto;

/**
 * Lógica pura del borrador (sin base de datos): cómo se combinan lo que DETECTÓ el analizador y lo que CORRIGE o CONFIRMA el administrador.
 *
 * Reglas que esta clase hace cumplir:
 *  - Un valor del Excel nunca se vuelve definitivo por sí solo si es ambiguo o puede estar desactualizado: el administrador lo confirma o lo corrige.
 *  - La elegibilidad de cada persona es «pendiente» hasta que el administrador la decida (ni «Completo» ni «Cupos» la presumen).
 *  - El tipo de documento solo se toma del Excel cuando se detectó sin dudas; si no, queda por confirmar. Nunca se supone C.C.
 *  - La columna Diploma es solo informativa.
 *
 * `$analisis` es `AnalisisExcel::toArray()`; `$corr` son las correcciones del administrador (ver `vacias()`).
 */
final class Borrador
{
    public const COMUNES_REQUERIDOS = ['evento', 'fecha', 'intensidad_horaria'];

    public const COMUNES = ['evento', 'fecha', 'intensidad_horaria', 'modalidad', 'ciudad', 'texto_certificacion', 'texto_complementario'];

    public const ELEGIBILIDADES = ['elegible', 'pendiente', 'no_elegible'];

    public const SENALES = ['completo', 'cupo', 'conflicto', 'desconocida', 'sin_senal'];

    /** Campos que una corrección individual puede tocar. */
    private const CAMPOS_FILA = ['nombre_completo', 'documento', 'tipo_documento', 'correo', 'elegibilidad', 'intensidad_horaria', 'excluir'];

    /** Campos que una corrección masiva puede tocar (nombres, documentos y correos se corrigen uno a uno). */
    private const CAMPOS_MASIVOS = ['tipo_documento', 'elegibilidad', 'intensidad_horaria', 'excluir'];

    private const RAIZ = ['plantilla_id', 'nombre_base', 'datos_comunes', 'confirmados', 'evento', 'participantes', 'masivas'];

    private const MASIVAS_MAX = 50;

    /** @return array<string,mixed> */
    public static function vacias(): array
    {
        return [
            'plantilla_id' => null, 'nombre_base' => null, 'datos_comunes' => [],
            'confirmados' => ['datos_comunes' => [], 'valores_desactualizados' => false, 'valores_ambiguos' => false],
            'evento' => null, 'participantes' => [],
        ];
    }

    // ================================================================= corrección

    /**
     * Combina una petición de corrección con las correcciones ya guardadas. Valida todo antes de devolver nada.
     *
     * @param  array<string,mixed>  $actual
     * @param  array<string,mixed>  $entrada
     * @param  array<string,mixed>  $analisis
     * @return array<string,mixed>
     *
     * @throws ImportacionException
     */
    public static function fusionar(array $actual, array $entrada, array $analisis): array
    {
        $actual = array_replace_recursive(self::vacias(), $actual);
        $filas = array_flip(array_map(fn ($p) => (int) $p['fila'], $analisis['tabla']['participantes'] ?? []));

        foreach (array_keys($entrada) as $clave) {
            if (! in_array($clave, self::RAIZ, true)) {
                throw self::invalida("«{$clave}» no es una corrección permitida.");
            }
        }

        if (array_key_exists('plantilla_id', $entrada)) {
            $v = $entrada['plantilla_id'];
            if ($v !== null && (! is_int($v) || $v < 1)) {
                throw self::invalida('La plantilla debe ser un número válido.', 'plantilla_id');
            }
            $actual['plantilla_id'] = $v;
        }
        if (array_key_exists('nombre_base', $entrada)) {
            $actual['nombre_base'] = self::texto($entrada['nombre_base'], 'nombre_base', 200);
        }

        foreach ((array) ($entrada['datos_comunes'] ?? []) as $campo => $valor) {
            if (! in_array($campo, self::COMUNES, true)) {
                throw self::invalida("«{$campo}» no es un dato común de la base.", "datos_comunes.{$campo}");
            }
            $t = self::texto($valor, "datos_comunes.{$campo}", 2000, true);
            if ($t === null) {
                unset($actual['datos_comunes'][$campo]);
            } else {
                $actual['datos_comunes'][$campo] = $t;
                $actual['confirmados']['datos_comunes'] = array_values(array_diff($actual['confirmados']['datos_comunes'], [$campo]));
            }
        }

        if (isset($entrada['confirmados'])) {
            $c = $entrada['confirmados'];
            if (! is_array($c)) {
                throw self::invalida('Las confirmaciones deben ser un objeto.', 'confirmados');
            }
            if (array_key_exists('datos_comunes', $c)) {
                $lista = $c['datos_comunes'];
                if (! is_array($lista) || array_diff($lista, self::COMUNES) !== []) {
                    throw self::invalida('La lista de datos confirmados tiene un dato desconocido.', 'confirmados.datos_comunes');
                }
                $actual['confirmados']['datos_comunes'] = array_values(array_unique($lista));
            }
            foreach (['valores_desactualizados', 'valores_ambiguos'] as $f) {
                if (array_key_exists($f, $c)) {
                    if (! is_bool($c[$f])) {
                        throw self::invalida("«{$f}» debe ser verdadero o falso.", "confirmados.{$f}");
                    }
                    $actual['confirmados'][$f] = $c[$f];
                }
            }
        }

        if (array_key_exists('evento', $entrada)) {
            $actual['evento'] = self::fusionarEvento($actual['evento'], $entrada['evento']);
        }

        foreach ((array) ($entrada['participantes'] ?? []) as $fila => $campos) {
            $fila = (string) (int) $fila;
            if (! isset($filas[(int) $fila])) {
                throw self::invalida("La fila {$fila} no es un participante de este borrador.", "participantes.{$fila}");
            }
            if ($campos === null) {
                unset($actual['participantes'][$fila]);

                continue;
            }
            $actual['participantes'][$fila] = self::fusionarFila($actual['participantes'][$fila] ?? [], (array) $campos, self::CAMPOS_FILA, "participantes.{$fila}");
            if ($actual['participantes'][$fila] === []) {
                unset($actual['participantes'][$fila]);
            }
        }

        $masivas = $entrada['masivas'] ?? [];
        if (! is_array($masivas) || count($masivas) > self::MASIVAS_MAX) {
            throw self::invalida('Las correcciones masivas no son válidas.', 'masivas');
        }
        foreach ($masivas as $i => $m) {
            $destino = self::filasDeMasiva($m, $i, $analisis, $filas);
            foreach ($destino as $fila) {
                $actual['participantes'][(string) $fila] = self::fusionarFila($actual['participantes'][(string) $fila] ?? [], (array) $m['campos'], self::CAMPOS_MASIVOS, "masivas.{$i}.campos");
                if ($actual['participantes'][(string) $fila] === []) {
                    unset($actual['participantes'][(string) $fila]);
                }
            }
        }

        return $actual;
    }

    /** @return array<string,mixed>|null */
    private static function fusionarEvento(mixed $actual, mixed $nuevo): ?array
    {
        if ($nuevo === null) {
            return null;
        }
        if (! is_array($nuevo)) {
            throw self::invalida('La decisión del evento debe ser un objeto.', 'evento');
        }
        $base = is_array($actual) ? $actual : ['decision' => null, 'evento_id' => null, 'nombre' => null, 'confirmar_distinto' => false];
        foreach (array_keys($nuevo) as $k) {
            if (! in_array($k, ['decision', 'evento_id', 'nombre', 'confirmar_distinto'], true)) {
                throw self::invalida("«{$k}» no es parte de la decisión del evento.", 'evento');
            }
        }
        if (array_key_exists('decision', $nuevo)) {
            if ($nuevo['decision'] !== null && ! in_array($nuevo['decision'], ['asociar', 'crear'], true)) {
                throw self::invalida('La decisión del evento debe ser «asociar» o «crear».', 'evento.decision');
            }
            $base['decision'] = $nuevo['decision'];
        }
        if (array_key_exists('evento_id', $nuevo)) {
            if ($nuevo['evento_id'] !== null && (! is_int($nuevo['evento_id']) || $nuevo['evento_id'] < 1)) {
                throw self::invalida('El evento elegido no es válido.', 'evento.evento_id');
            }
            $base['evento_id'] = $nuevo['evento_id'];
        }
        if (array_key_exists('nombre', $nuevo)) {
            $base['nombre'] = self::texto($nuevo['nombre'], 'evento.nombre', 255, true);
        }
        if (array_key_exists('confirmar_distinto', $nuevo)) {
            if (! is_bool($nuevo['confirmar_distinto'])) {
                throw self::invalida('La confirmación de evento distinto debe ser verdadero o falso.', 'evento.confirmar_distinto');
            }
            $base['confirmar_distinto'] = $nuevo['confirmar_distinto'];
        }

        return $base;
    }

    /**
     * @param  array<string,mixed>  $actual
     * @param  array<string,mixed>  $nuevos
     * @param  list<string>  $permitidos
     * @return array<string,mixed>
     */
    private static function fusionarFila(array $actual, array $nuevos, array $permitidos, string $ruta): array
    {
        foreach ($nuevos as $campo => $valor) {
            if (! in_array($campo, $permitidos, true)) {
                throw self::invalida("«{$campo}» no se puede corregir aquí.", "{$ruta}.{$campo}");
            }
            if ($campo === 'excluir') {
                if (! is_bool($valor)) {
                    throw self::invalida('«excluir» debe ser verdadero o falso.', "{$ruta}.excluir");
                }
                if ($valor === true) {
                    $actual['excluir'] = true;
                } else {
                    unset($actual['excluir']);   // «no excluir» es el valor por defecto: no se guarda
                }

                continue;
            }
            $t = self::texto($valor, "{$ruta}.{$campo}", $campo === 'correo' ? 320 : ($campo === 'documento' ? 60 : 200), true);
            if ($t === null) {
                unset($actual[$campo]);

                continue;
            }
            if ($campo === 'elegibilidad' && ! in_array($t, self::ELEGIBILIDADES, true)) {
                throw self::invalida('La elegibilidad debe ser «elegible», «pendiente» o «no_elegible».', "{$ruta}.elegibilidad");
            }
            if ($campo === 'tipo_documento') {
                $canon = Normalizadores::tipoCanonico($t);
                if ($canon === null) {
                    throw self::invalida('Tipo de documento no reconocido. Usa C.C., C.E., T.I., Pasaporte, NIT, PEP o PPT; o déjalo vacío para confirmarlo después.', "{$ruta}.tipo_documento");
                }
                $t = $canon;
            }
            $actual[$campo] = $t;
        }

        return $actual;
    }

    /** @return list<int> */
    private static function filasDeMasiva(mixed $m, int|string $i, array $analisis, array $filas): array
    {
        if (! is_array($m) || ! isset($m['campos']) || ! is_array($m['campos']) || $m['campos'] === []) {
            throw self::invalida('Cada corrección masiva necesita «filas» y «campos».', "masivas.{$i}");
        }
        $objetivo = $m['filas'] ?? null;
        if ($objetivo === 'todas') {
            $destino = array_keys($filas);
        } elseif (is_array($objetivo) && $objetivo !== []) {
            $destino = array_map('intval', $objetivo);
            foreach ($destino as $f) {
                if (! isset($filas[$f])) {
                    throw self::invalida("La fila {$f} no es un participante de este borrador.", "masivas.{$i}.filas");
                }
            }
        } else {
            throw self::invalida('«filas» debe ser «todas» o una lista de filas.', "masivas.{$i}.filas");
        }
        if (isset($m['senal'])) {
            if (! in_array($m['senal'], self::SENALES, true)) {
                throw self::invalida('Señal de asistencia desconocida.', "masivas.{$i}.senal");
            }
            $porSenal = [];
            foreach ($analisis['tabla']['participantes'] ?? [] as $p) {
                $porSenal[(int) $p['fila']] = $p['senal'];
            }
            $destino = array_values(array_filter($destino, fn ($f) => ($porSenal[$f] ?? null) === $m['senal']));
        }

        return array_values(array_unique($destino));
    }

    private static function texto(mixed $v, string $campo, int $max, bool $unaLinea = false): ?string
    {
        if ($v === null) {
            return null;
        }
        if (! is_string($v)) {
            throw self::invalida('Debe ser texto.', $campo);
        }
        $t = $unaLinea && ! in_array($campo, ['datos_comunes.texto_certificacion', 'datos_comunes.texto_complementario'], true) ? Texto::limpiar($v) : trim($v);
        if ($t === '') {
            return null;
        }
        if (mb_strlen($t) > $max) {
            throw self::invalida("No puede superar los {$max} caracteres.", $campo);
        }

        return $t;
    }

    private static function invalida(string $mensaje, ?string $campo = null): ImportacionException
    {
        return new ImportacionException(ImportacionException::CORRECCION_INVALIDA, $mensaje, 422, $campo === null ? [] : [['campo' => $campo, 'mensaje' => $mensaje]]);
    }

    // ================================================================= datos comunes

    /**
     * @param  array<string,mixed>  $analisis
     * @param  array<string,mixed>  $corr
     * @return array<string, array<string,mixed>> por dato común
     */
    public static function datosComunes(array $analisis, array $corr): array
    {
        $corr = array_replace_recursive(self::vacias(), $corr);
        $salida = [];
        foreach (self::COMUNES as $campo) {
            $p = $analisis['evento'][$campo] ?? null;
            $propuesta = is_array($p) && ($p['valor'] ?? null) !== null && $p['valor'] !== '' ? (string) $p['valor'] : null;
            $admin = $corr['datos_comunes'][$campo] ?? null;
            $origen = $admin !== null ? 'admin' : ($propuesta !== null ? 'excel' : null);
            $valor = $admin ?? $propuesta;
            $confianza = $origen === 'excel' ? ($p['confianza'] ?? null) : null;
            $stale = $origen === 'excel' && ($p['desactualizable'] ?? false) === true;
            $confirmado = $valor !== null && ($origen === 'admin' || in_array($campo, $corr['confirmados']['datos_comunes'], true));
            $salida[$campo] = [
                'valor' => $valor, 'propuesta' => $propuesta, 'origen' => $origen, 'confianza' => $confianza, 'desactualizable' => $stale,
                'motivos' => $origen === 'excel' ? ($p['motivos'] ?? []) : [], 'referencia' => $origen === 'excel' ? ($p['referencia'] ?? null) : null,
                'original' => $origen === 'excel' ? ($p['original'] ?? null) : null, 'etiqueta' => $origen === 'excel' ? ($p['etiqueta'] ?? null) : null,
                'confirmado' => $confirmado, 'requerido' => in_array($campo, self::COMUNES_REQUERIDOS, true),
                'requiere_confirmacion' => $campo !== 'evento' && $origen === 'excel' && ! $confirmado && ($confianza !== 'confiable' || $stale),
            ];
        }

        return $salida;
    }

    // ================================================================= participantes

    /**
     * @param  array<string,mixed>  $analisis
     * @param  array<string,mixed>  $corr
     * @return list<array<string,mixed>>
     */
    public static function participantes(array $analisis, array $corr): array
    {
        $corr = array_replace_recursive(self::vacias(), $corr);
        $salida = [];
        foreach ($analisis['tabla']['participantes'] ?? [] as $p) {
            $fila = (int) $p['fila'];
            $c = $corr['participantes'][(string) $fila] ?? [];
            $campo = fn (string $k) => $p['campos'][$k] ?? null;
            $valor = fn (string $k) => is_array($campo($k)) ? ($campo($k)['valor'] ?? null) : null;

            $tipoAnalizado = $campo('tipo_documento');
            $tipoSeguro = is_array($tipoAnalizado) && ($tipoAnalizado['confianza'] ?? null) === 'confiable' ? ($tipoAnalizado['valor'] ?? null) : null;
            $tipo = array_key_exists('tipo_documento', $c) ? $c['tipo_documento'] : $tipoSeguro;
            $correoDetectado = $campo('correo');
            $correoBase = $valor('correo') ?? (is_array($correoDetectado) ? ($correoDetectado['original'] ?? null) : null);
            $intensidadAnalizada = $campo('intensidad_individual');
            $intensidadSegura = is_array($intensidadAnalizada) && ($intensidadAnalizada['confianza'] ?? null) === 'confiable' ? ($intensidadAnalizada['valor'] ?? null) : null;

            $corregidos = array_values(array_intersect(array_keys($c), ['nombre_completo', 'documento', 'tipo_documento', 'correo']));   // la asistencia (sesión, casillas) no se corrige aquí: se decide con la elegibilidad
            $dudosos = [];
            $desactualizados = [];
            foreach (['nombre_completo', 'documento', 'correo', 'tipo_documento', 'sesion', 'certificado_completo', 'seleccion_cupo'] as $k) {
                $v = $campo($k);
                if (! is_array($v) || in_array($k, $corregidos, true) || ($v['confianza'] ?? null) === 'faltante') {
                    continue;
                }
                if ($k === 'tipo_documento' && ($v['confianza'] ?? null) === 'ambiguo') {
                    continue;     // un tipo dudoso no se usa: queda por confirmar (se avisa aparte)
                }
                if (($v['confianza'] ?? null) === 'ambiguo') {
                    $dudosos[] = $k;
                }
                if (($v['desactualizable'] ?? false) === true) {
                    $desactualizados[] = $k;
                }
            }

            $avisos = [];
            if ($tipo === null) {
                $avisos[] = 'tipo_documento_por_confirmar';
            }
            if (is_array($tipoAnalizado) && ($tipoAnalizado['confianza'] ?? null) === 'ambiguo' && $tipoAnalizado['valor'] !== null && ! array_key_exists('tipo_documento', $c)) {
                $avisos[] = 'tipo_documento_propuesto_sin_confirmar';
            }
            if ($dudosos !== []) {
                $avisos[] = 'valores_ambiguos';
            }
            if ($desactualizados !== []) {
                $avisos[] = 'valores_posiblemente_desactualizados';
            }
            if (in_array($p['senal'], ['cupo', 'conflicto', 'desconocida'], true)) {
                $avisos[] = 'asistencia_'.$p['senal'];
            }
            if (is_array($campo('documento')) && ! empty($campo('documento')['extra']['llego_como_numero']) && ! in_array('documento', $corregidos, true)) {
                $avisos[] = 'documento_llego_como_numero';
            }
            if (($p['posible_qa'] ?? null) !== null) {
                $avisos[] = 'posible_contacto_qa';
            }

            $salida[] = [
                'fila' => $fila,
                'nombre_completo' => $c['nombre_completo'] ?? $valor('nombre_completo'),
                'documento' => $c['documento'] ?? $valor('documento'),
                'tipo_documento' => $tipo,
                'tipo_documento_propuesto' => $tipo === null && is_array($tipoAnalizado) ? ($tipoAnalizado['valor'] ?? null) : null,
                'correo' => $c['correo'] ?? $correoBase,
                'elegibilidad' => $c['elegibilidad'] ?? 'pendiente',
                'intensidad_horaria' => $c['intensidad_horaria'] ?? $intensidadSegura,
                'excluir' => ($c['excluir'] ?? false) === true,
                'senal' => $p['senal'],
                'diploma_marcado' => $p['diploma'] ?? null,          // solo informativo: nunca autoriza ni impide emitir
                'sesion' => $valor('sesion'),
                'observaciones' => $valor('observaciones'),
                'posible_qa' => $p['posible_qa'] ?? null,
                'corregidos' => $corregidos,
                'valores_ambiguos' => $dudosos,
                'valores_desactualizados' => $desactualizados,
                'avisos' => $avisos,
            ];
        }

        return $salida;
    }
}
