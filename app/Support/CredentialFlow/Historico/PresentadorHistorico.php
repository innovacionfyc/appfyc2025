<?php

namespace App\Support\CredentialFlow\Historico;

/**
 * Presentación del histórico para el administrador: enmascarado de datos personales, etiquetas, tonos de las insignias y
 * mensajes humanos. Funciones puras (sin base de datos): todo lo que una pantalla muestra y no es un dato se decide aquí, no en
 * los modelos ni en el navegador.
 *
 * Privacidad: en los LISTADOS el documento va enmascarado y el correo es solo un indicador. Las direcciones completas y el
 * documento completo solo aparecen en el detalle de un certificado.
 */
final class PresentadorHistorico
{
    public const SIN_ANIO = 'Sin año identificado';

    /** Documento enmascarado: solo se ven los últimos dígitos (hasta 4; en documentos cortos, menos). `1234567890` → `******7890`. */
    public static function documentoEnmascarado(?string $documento): string
    {
        $limpio = preg_replace('/\s+/u', '', (string) $documento) ?? '';
        $largo = mb_strlen($limpio);
        if ($largo === 0) {
            return '—';
        }
        $visibles = $largo >= 8 ? 4 : max(0, $largo - 4);

        return str_repeat('*', $largo - $visibles).($visibles > 0 ? mb_substr($limpio, -$visibles) : '');
    }

    /** Indicador de correo para tablas (nunca la dirección). */
    public static function indicadorCorreo(int $validos, int $invalidos): string
    {
        return match (true) {
            $validos >= 2 => "{$validos} correos válidos",
            $validos === 1 => '1 correo válido',
            $invalidos > 0 => 'Correo inválido',
            default => 'Sin correo',
        };
    }

    public static function anio(?int $anio): string
    {
        return $anio === null ? self::SIN_ANIO : (string) $anio;
    }

    /** @return array{etiqueta:string,tono:string} */
    public static function conciliacion(string $estado): array
    {
        return match ($estado) {
            'ok' => ['etiqueta' => 'Sin novedad', 'tono' => 'emerald'],
            'duplicado_consolidado' => ['etiqueta' => 'Duplicado histórico', 'tono' => 'sky'],
            'pendiente_plantilla' => ['etiqueta' => 'Plantilla pendiente', 'tono' => 'amber'],
            'pendiente_conciliacion' => ['etiqueta' => 'Necesita conciliación', 'tono' => 'rose'],
            'revision_documento' => ['etiqueta' => 'Documento en revisión', 'tono' => 'rose'],
            default => ['etiqueta' => $estado, 'tono' => 'slate'],
        };
    }

    /** @return array{etiqueta:string,tono:string} */
    public static function plantilla(?string $estado): array
    {
        return match ($estado) {
            'ok' => ['etiqueta' => 'Disponible', 'tono' => 'emerald'],
            'faltante' => ['etiqueta' => 'Imagen faltante', 'tono' => 'rose'],
            'extension_invalida' => ['etiqueta' => 'Extensión inválida', 'tono' => 'amber'],
            'huerfana' => ['etiqueta' => 'Sin evento', 'tono' => 'slate'],
            'candidata_revision' => ['etiqueta' => 'Candidata en revisión', 'tono' => 'sky'],
            null => ['etiqueta' => 'Sin plantilla', 'tono' => 'slate'],
            default => ['etiqueta' => $estado, 'tono' => 'slate'],
        };
    }

    public static function estadoEvento(string $estado): string
    {
        return ['borrador' => 'Borrador', 'activo' => 'Activo', 'cerrado' => 'Cerrado', 'archivado' => 'Archivado'][$estado] ?? $estado;
    }

    public static function estadoCertificado(string $estado): string
    {
        return ['vigente' => 'Vigente', 'revocado' => 'Revocado', 'reemplazado' => 'Reemplazado'][$estado] ?? $estado;
    }

    /** Qué difiere en un grupo conflictivo, en lenguaje claro. */
    public static function diferencias(?string $etiquetas): array
    {
        $nombres = ['DIF_NOMBRE' => 'el nombre', 'DIF_TIPO' => 'el tipo de documento', 'DIF_DOCUMENTO' => 'el documento', 'DIF_CORREO' => 'el correo', 'DIF_VERIF' => 'el código'];

        return array_values(array_filter(array_map(fn ($e) => $nombres[trim($e)] ?? null, explode(',', (string) $etiquetas))));
    }

    /** Mensaje humano por advertencia técnica del snapshot. */
    public static function advertencia(string $codigo): ?string
    {
        return [
            'TIPO_DOCUMENTO_VACIO' => 'El registro histórico no tenía tipo de documento: el certificado original se imprimía sin él.',
        ][$codigo] ?? null;
    }

    /** Motivo técnico del documento en revisión (solo para el administrador). */
    public static function motivoDocumento(?string $estado, ?string $detalle): string
    {
        $texto = [
            'letras' => 'contiene letras', 'separadores' => 'tiene puntos o comas como separadores', 'otro' => 'tiene caracteres no numéricos',
            'muy_largo' => 'tiene demasiados dígitos', 'ceros_izquierda' => 'empieza con ceros', 'whitespace_cambia_impresion' => 'tiene espacios que cambian lo que se imprimía',
        ];
        if ($estado === 'vacio') {
            return 'el documento está vacío';
        }

        return $texto[$detalle ?? ''] ?? 'no cumple el formato esperado';
    }

    /** Nombre de archivo apto para mostrar: sin caracteres de control ni espacios raros, recortado (no rompe la pantalla). */
    public static function nombreArchivo(?string $nombre, int $maximo = 60): string
    {
        $limpio = trim(preg_replace('/[\x00-\x1F\x7F\s]+/u', ' ', (string) $nombre) ?? '');
        if ($limpio === '') {
            return '(sin nombre)';
        }

        return mb_strlen($limpio) > $maximo ? mb_substr($limpio, 0, $maximo - 1).'…' : $limpio;
    }

    public static function bytes(?int $bytes): string
    {
        if ($bytes === null) {
            return '—';
        }
        if ($bytes < 1024) {
            return $bytes.' B';
        }
        $kb = $bytes / 1024;

        return $kb < 1024 ? number_format($kb, 0, ',', '.').' KB' : number_format($kb / 1024, 1, ',', '.').' MB';
    }

    public static function shaAbreviado(?string $sha): ?string
    {
        return $sha === null ? null : substr($sha, 0, 12);
    }
}
