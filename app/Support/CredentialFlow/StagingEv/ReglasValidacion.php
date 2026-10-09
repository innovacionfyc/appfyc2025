<?php

namespace App\Support\CredentialFlow\StagingEv;

/**
 * Reglas de validación del staging: a partir de lo ya derivado de una fila devuelven [validacion, motivo].
 *
 *  - validacion: `error` si hay algún código que BLOQUEA el uso automático de la fila, `advertencia` si solo hay avisos
 *    y `ok` si no hay ninguno.
 *  - motivo: los códigos, separados por coma (vacío si no hay).
 *
 * Nada aquí corrige ni descarta datos: solo clasifica. Los códigos de BLOQUEO son REVISION_DOCUMENTO,
 * DUPLICADO_CONFLICTIVO y los de imagen no utilizable.
 */
final class ReglasValidacion
{
    public const BLOQUEANTES = [
        'REVISION_DOCUMENTO',
        'DUPLICADO_CONFLICTIVO',
        'SIN_IMAGEN',
        'IMAGEN_FALTANTE',
        'IMAGEN_EXTENSION_INVALIDA',
        'IMAGEN_NO_RENDERIZABLE',
        'NO_RENDERIZABLE',
    ];

    /**
     * @param  array<string,mixed>  $f  columnas derivadas del participante en staging
     * @param  ?string  $grupo  clasificación de su grupo de duplicados: identico | conflictivo | null
     * @return array{0:string,1:?string}
     */
    public static function participante(array $f, ?string $grupo): array
    {
        $c = [];
        if (($f['documento_estado'] ?? '') === 'vacio') {
            $c[] = 'DOC_VACIO';
            $c[] = 'REVISION_DOCUMENTO';
        } elseif (($f['documento_estado'] ?? '') === 'anomalo') {
            $c[] = 'DOC_'.strtoupper((string) ($f['documento_detalle'] ?? 'OTRO'));
            $c[] = 'REVISION_DOCUMENTO';
        }
        if ($f['documento_normalizado_ws'] ?? false) {
            $c[] = 'DOCUMENTO_NORMALIZADO_WHITESPACE';
        }
        match ($f['correo_estado'] ?? '') {
            'invalido' => $c[] = 'CORREO_INVALIDO',
            'sin_correo' => $c[] = 'SIN_CORREO',
            'multiple' => $c[] = 'CORREO_MULTIPLE',
            default => null,
        };
        if (($f['correos_validos'] ?? 0) >= 1 && ($f['correos_candidatos'] ?? 0) > ($f['correos_validos'] ?? 0)) {
            $c[] = 'CORREO_FRAGMENTOS_INVALIDOS';
        }
        if (($f['tipo_documento'] ?? null) === null) {
            $c[] = 'TIPO_VACIO';
        }
        if ($grupo === 'identico') {
            $c[] = 'DUPLICADO_IDENTICO';
        } elseif ($grupo === 'conflictivo') {
            $c[] = 'DUPLICADO_CONFLICTIVO';
        }

        return self::resolver($c);
    }

    /** @return array{0:string,1:?string} */
    public static function evento(array $f): array
    {
        $c = [];
        match ($f['imagen_estado'] ?? null) {
            'sin_imagen' => $c[] = 'SIN_IMAGEN',
            'archivo_faltante' => $c[] = 'IMAGEN_FALTANTE',
            'extension_invalida' => $c[] = 'IMAGEN_EXTENSION_INVALIDA',
            'no_renderizable' => $c[] = 'IMAGEN_NO_RENDERIZABLE',
            default => null,
        };
        if (($f['anio_deducido'] ?? null) === null) {
            $c[] = ($f['anio_origen'] ?? null) === 'ambiguo' ? 'ANIO_AMBIGUO' : 'ANIO_NO_DEDUCIBLE';
        }
        if (($f['imagen_original'] ?? null) !== null && Normalizador::nombreArchivoRaro((string) $f['imagen_original'])) {
            $c[] = 'IMAGEN_NOMBRE_RARO';
        }

        return self::resolver($c);
    }

    /** @return array{0:string,1:?string} */
    public static function imagen(array $f): array
    {
        $c = [];
        if (! ($f['renderizable_fpdf'] ?? true)) {
            $c[] = 'NO_RENDERIZABLE';
        }
        if ($f['huerfana'] ?? false) {
            $c[] = 'HUERFANA';
        }
        if ($f['candidata_revision'] ?? false) {
            $c[] = 'CANDIDATA_REVISION';
        }
        if (Normalizador::nombreArchivoRaro((string) ($f['nombre_original'] ?? ''))) {
            $c[] = 'NOMBRE_RARO';
        }

        return self::resolver($c);
    }

    /** @return array{0:string,1:?string} */
    public static function encuesta(array $f): array
    {
        $c = [];
        if (! ($f['evento_existe'] ?? false)) {
            $c[] = 'SIN_EVENTO';
        }
        if (! ($f['participante_existe'] ?? false)) {
            $c[] = 'SIN_PARTICIPANTE';
        }

        return self::resolver($c);
    }

    /** @return array{0:string,1:?string} */
    public static function descarga(array $f): array
    {
        $c = [];
        if (! ($f['participante_existe'] ?? false)) {
            $c[] = 'SIN_PARTICIPANTE';
        }
        if (($f['evento_coincide'] ?? null) === false) {
            $c[] = 'EVENTO_NO_COINCIDE';
        }

        return self::resolver($c);
    }

    /** @return array{0:string,1:?string} */
    public static function token(array $f): array
    {
        $c = [];
        if (! ($f['participante_existe'] ?? false)) {
            $c[] = 'SIN_PARTICIPANTE';
        }
        if ($f['codigo_repetido'] ?? false) {
            $c[] = 'CODIGO_REPETIDO';
        }

        return self::resolver($c);
    }

    /**
     * @param  array<int,string>  $codigos
     * @return array{0:string,1:?string}
     */
    private static function resolver(array $codigos): array
    {
        if ($codigos === []) {
            return ['ok', null];
        }
        $error = array_intersect($codigos, self::BLOQUEANTES) !== [];

        return [$error ? 'error' : 'advertencia', mb_substr(implode(',', $codigos), 0, 500)];
    }
}
