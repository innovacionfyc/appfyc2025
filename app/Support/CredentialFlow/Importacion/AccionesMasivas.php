<?php

namespace App\Support\CredentialFlow\Importacion;

use App\Support\CredentialFlow\Participantes\Excel\Analisis\Normalizadores;

/**
 * Cambios en bloque sobre un borrador (F5): lógica pura, sin base de datos. Solo cambia dos cosas, y siempre por decisión explícita del administrador:
 *  - la ELEGIBILIDAD («elegible», «pendiente» o «no_elegible»), y
 *  - el TIPO DE DOCUMENTO (uno del catálogo).
 *
 * Reglas que esta clase hace cumplir en el servidor (la pantalla solo las muestra):
 *  - Las personas del Excel ya nacen elegibles (regla de negocio, ver `Borrador`); la asistencia del archivo («Completo», «Cupos»…) no cuenta. Estas acciones
 *    sirven para cambiar esa elegibilidad a propósito (por ejemplo, decidir a quienes el Excel declara «No elegible»): el administrador elige a las personas
 *    y el estado, y al marcar «elegible» confirma que lo verificó.
 *  - Los excluidos y las personas que se parecen a un contacto de control (QA) no se tocan; se informan.
 *  - El tipo de documento no tiene valor por defecto ni se deduce del número: viene siempre de lo que el administrador elige.
 *  - Cambiar algo que ya estaba decidido (otro tipo, otra elegibilidad) exige una confirmación adicional.
 *  - Cada acción se puede revisar antes (vista previa con huella) y deshacer después. No crea bases, participantes, emisiones ni correos.
 */
final class AccionesMasivas
{
    public const CAMPOS = ['elegibilidad', 'tipo_documento'];

    public const HISTORIAL_MAX = 20;

    /**
     * Valida la forma de la petición (nada de esto toca el borrador).
     *
     * @param  array<string,mixed>  $entrada
     * @param  array<string,mixed>  $analisis
     * @return array{campo: string, valor: string, filas: list<int>, verificado: bool, confirmar_sobrescritura: bool}
     *
     * @throws ImportacionException
     */
    public static function normalizar(array $entrada, array $analisis): array
    {
        $campo = $entrada['campo'] ?? null;
        if (! is_string($campo) || ! in_array($campo, self::CAMPOS, true)) {
            throw self::invalida('Elige qué quieres cambiar: la elegibilidad o el tipo de documento.', 'campo');
        }

        $valor = $entrada['valor'] ?? null;
        if (! is_string($valor) || trim($valor) === '') {
            throw self::invalida($campo === 'tipo_documento' ? 'Elige el tipo de documento: no se asigna uno por defecto.' : 'Elige la elegibilidad que quieres proponer.', 'valor');
        }
        $valor = trim($valor);
        if ($campo === 'elegibilidad' && ! in_array($valor, Borrador::ELEGIBILIDADES, true)) {
            throw self::invalida('La elegibilidad debe ser «elegible», «pendiente» o «no_elegible».', 'valor');
        }
        if ($campo === 'tipo_documento') {
            $canon = Normalizadores::tipoCanonico($valor);
            if ($canon === null) {
                throw self::invalida('Tipo de documento no reconocido. Usa C.C., C.E., T.I., Pasaporte, NIT, PEP o PPT.', 'valor');
            }
            $valor = $canon;
        }

        $existentes = array_flip(array_map(fn ($p) => (int) $p['fila'], $analisis['tabla']['participantes'] ?? []));
        $filas = $entrada['filas'] ?? null;
        if (! is_array($filas) || $filas === [] || ! array_is_list($filas)) {
            throw self::invalida('Selecciona al menos una persona.', 'filas');
        }
        if (count($filas) > count($existentes)) {
            throw self::invalida('La selección es más grande que el borrador.', 'filas');
        }
        $limpias = [];
        foreach ($filas as $f) {
            if (! is_int($f) && ! (is_string($f) && ctype_digit($f))) {
                throw self::invalida('La selección tiene un valor que no es una fila.', 'filas');
            }
            $f = (int) $f;
            if (! isset($existentes[$f])) {
                throw self::invalida("La fila {$f} no es un participante de este borrador.", 'filas');
            }
            $limpias[$f] = $f;
        }
        ksort($limpias);

        foreach (['verificado', 'confirmar_sobrescritura'] as $bandera) {
            if (array_key_exists($bandera, $entrada) && ! is_bool($entrada[$bandera])) {
                throw self::invalida("«{$bandera}» debe ser verdadero o falso.", $bandera);
            }
        }

        return [
            'campo' => $campo, 'valor' => $valor, 'filas' => array_values($limpias),
            'verificado' => ($entrada['verificado'] ?? false) === true, 'confirmar_sobrescritura' => ($entrada['confirmar_sobrescritura'] ?? false) === true,
        ];
    }

    /**
     * Qué pasaría con cada persona seleccionada. Solo calcula: no guarda nada.
     *
     * @param  array<string,mixed>  $analisis
     * @param  array<string,mixed>  $corr
     * @param  array{campo: string, valor: string, filas: list<int>}  $accion
     * @return array<string,mixed>
     */
    public static function planear(array $analisis, array $corr, array $accion, int $version): array
    {
        $porFila = [];
        foreach (Borrador::participantes($analisis, $corr) as $p) {
            $porFila[$p['fila']] = $p;
        }
        $campo = $accion['campo'];
        $valor = $accion['valor'];

        $cambios = [];
        $sinCambio = [];
        $omitidos = [];
        foreach ($accion['filas'] as $fila) {
            $p = $porFila[$fila];
            $motivo = self::motivoDeOmision($p, $campo, $valor);
            if ($motivo !== null) {
                $omitidos[] = ['fila' => $fila, 'nombre' => $p['nombre_completo'], 'motivo' => $motivo];

                continue;
            }
            $anterior = $campo === 'elegibilidad' ? $p['elegibilidad'] : $p['tipo_documento'];
            if ($anterior === $valor) {
                $sinCambio[] = $fila;

                continue;
            }
            $cambios[] = [
                'fila' => $fila, 'nombre' => $p['nombre_completo'], 'anterior' => $anterior, 'propuesto' => $valor,
                // «Sobrescribe» = ya había una decisión (otro tipo, o una elegibilidad que decidió el administrador o declara el Excel; la automática no cuenta).
                'sobrescribe' => $campo === 'elegibilidad' ? ($p['elegibilidad_origen'] ?? 'automatica') !== 'automatica' && $anterior !== 'pendiente' : $anterior !== null,
                'tipo_documento_pendiente' => $p['tipo_documento'] === null,
            ];
        }

        $sobrescriben = count(array_filter($cambios, fn ($c) => $c['sobrescribe']));
        $porMotivo = array_count_values(array_column($omitidos, 'motivo'));

        return [
            'campo' => $campo, 'valor' => $valor, 'version' => $version,
            'seleccionados' => count($accion['filas']),
            'se_modifican' => count($cambios), 'sin_cambio' => count($sinCambio), 'omitidos_total' => count($omitidos), 'sobrescriben' => $sobrescriben,
            'omitidos_por_motivo' => $porMotivo,
            'cambios' => $cambios, 'omitidos' => $omitidos,
            'requiere_verificacion' => $campo === 'elegibilidad' && $valor === 'elegible',
            'requiere_confirmar_sobrescritura' => $sobrescriben > 0,
            // Personas que quedarían elegibles pero aún no podrían emitirse (falta confirmar su tipo de documento).
            'sin_tipo_de_documento' => $campo === 'elegibilidad' && $valor === 'elegible' ? count(array_filter($cambios, fn ($c) => $c['tipo_documento_pendiente'])) : 0,
            'huella' => self::huella($version, $campo, $valor, $cambios, $omitidos, $sinCambio),
        ];
    }

    /** @param  array<string,mixed>  $p */
    private static function motivoDeOmision(array $p, string $campo, string $valor): ?string
    {
        if ($p['excluir']) {
            return 'excluida';
        }
        if ($p['posible_qa'] !== null) {
            return 'posible_qa';
        }

        return null;
    }

    /**
     * Huella de lo que el administrador VIO: si al aplicar el resultado es otro (el borrador cambió, o la petición no es la misma), no se aplica.
     *
     * @param  list<array<string,mixed>>  $cambios
     * @param  list<array<string,mixed>>  $omitidos
     * @param  list<int>  $sinCambio
     */
    private static function huella(int $version, string $campo, string $valor, array $cambios, array $omitidos, array $sinCambio): string
    {
        $cuerpo = json_encode([
            $version, $campo, $valor,
            array_map(fn ($c) => [$c['fila'], $c['anterior'], $c['propuesto']], $cambios),
            array_map(fn ($o) => [$o['fila'], $o['motivo']], $omitidos),
            $sinCambio,
        ], JSON_THROW_ON_ERROR);

        return hash_hmac('sha256', $cuerpo, (string) config('app.key'));
    }

    /**
     * Aplica el plan ya validado a las correcciones y deja constancia (sin datos personales: solo filas y estados) para poder deshacerlo.
     *
     * @param  array<string,mixed>  $corr
     * @param  array<string,mixed>  $plan
     * @return array<string,mixed> correcciones nuevas
     */
    public static function aplicar(array $corr, array $plan, string $operacionId, bool $verificado): array
    {
        $campo = $plan['campo'];
        $anteriores = [];
        foreach ($plan['cambios'] as $c) {
            $clave = (string) $c['fila'];
            $anteriores[$clave] = $corr['participantes'][$clave][$campo] ?? null;
            $corr['participantes'][$clave][$campo] = $plan['valor'];
        }
        $historial = $corr['historial'] ?? [];
        $historial[] = [
            'id' => $operacionId, 'creado_at' => now()->toIso8601String(), 'campo' => $campo, 'valor' => $plan['valor'],
            'aplicados' => $plan['se_modifican'], 'omitidos' => $plan['omitidos_total'], 'sin_cambio' => $plan['sin_cambio'], 'sobrescritos' => $plan['sobrescriben'],
            'verificado' => $verificado, 'estado' => 'aplicada', 'anteriores' => $anteriores,
        ];
        $corr['historial'] = array_slice($historial, -self::HISTORIAL_MAX);

        return $corr;
    }

    /**
     * Revierte la ÚLTIMA acción aplicada. Solo si nadie volvió a tocar a esas personas: si no, no se deshace nada (nunca se pisa un cambio posterior).
     *
     * @param  array<string,mixed>  $corr
     * @return array<string,mixed> correcciones nuevas
     *
     * @throws ImportacionException
     */
    public static function deshacer(array $corr, string $operacionId): array
    {
        $historial = $corr['historial'] ?? [];
        $i = null;
        foreach ($historial as $k => $h) {
            if (($h['id'] ?? null) === $operacionId) {
                $i = $k;
            }
        }
        if ($i === null || ($historial[$i]['estado'] ?? null) !== 'aplicada') {
            throw new ImportacionException(ImportacionException::DESHACER_NO_POSIBLE, 'Ese cambio masivo no existe o ya se deshizo.', 409);
        }
        foreach (array_slice($historial, $i + 1, null, true) as $despues) {
            if (($despues['estado'] ?? null) === 'aplicada') {
                throw new ImportacionException(ImportacionException::DESHACER_NO_POSIBLE, 'Solo se puede deshacer el último cambio masivo. Deshaz primero el más reciente.', 409);
            }
        }

        $h = $historial[$i];
        $tocadas = 0;
        foreach ($h['anteriores'] as $fila => $previo) {
            if (($corr['participantes'][(string) $fila][$h['campo']] ?? null) !== $h['valor']) {
                $tocadas++;
            }
        }
        if ($tocadas > 0) {
            throw new ImportacionException(ImportacionException::DESHACER_NO_POSIBLE, $tocadas === 1
                ? 'Una de las personas se modificó después de este cambio, así que no se deshizo nada. Corrígela a mano.'
                : "{$tocadas} personas se modificaron después de este cambio, así que no se deshizo nada. Corrígelas a mano.", 409);
        }

        foreach ($h['anteriores'] as $fila => $previo) {
            $clave = (string) $fila;
            if ($previo === null) {
                unset($corr['participantes'][$clave][$h['campo']]);
                if (($corr['participantes'][$clave] ?? null) === []) {
                    unset($corr['participantes'][$clave]);
                }
            } else {
                $corr['participantes'][$clave][$h['campo']] = $previo;
            }
        }
        $historial[$i]['estado'] = 'deshecha';
        $historial[$i]['anteriores'] = [];
        $historial[$i]['deshecha_at'] = now()->toIso8601String();
        $corr['historial'] = $historial;

        return $corr;
    }

    /**
     * Historial para la pantalla: nunca incluye a las personas ni sus valores anteriores.
     *
     * @param  array<string,mixed>  $corr
     * @return list<array<string,mixed>>
     */
    public static function historialPublico(array $corr): array
    {
        $historial = array_values($corr['historial'] ?? []);
        $ultima = null;
        foreach ($historial as $k => $h) {
            if (($h['estado'] ?? null) === 'aplicada') {
                $ultima = $k;
            }
        }

        return array_map(fn ($h, $k) => [
            'id' => $h['id'], 'creado_at' => $h['creado_at'], 'campo' => $h['campo'], 'valor' => $h['valor'], 'aplicados' => $h['aplicados'], 'omitidos' => $h['omitidos'],
            'sobrescritos' => $h['sobrescritos'], 'verificado' => $h['verificado'], 'estado' => $h['estado'], 'deshacible' => $k === $ultima,
        ], $historial, array_keys($historial));
    }

    private static function invalida(string $mensaje, string $campo): ImportacionException
    {
        return new ImportacionException(ImportacionException::ACCION_INVALIDA, $mensaje, 422, [['campo' => $campo, 'mensaje' => $mensaje]]);
    }
}
