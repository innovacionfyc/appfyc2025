<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;

/** Textos, tonos y enmascarado de datos personales de los casos de conciliación. Funciones puras (sin base de datos). */
final class PresentadorConciliaciones
{
    public const TIPOS = [
        Conciliacion::TIPO_CONFLICTO_VARIANTES => ['etiqueta' => 'Conflicto entre variantes', 'ayuda' => 'Registros históricos de la misma persona y evento que no coinciden en algún dato.'],
        Conciliacion::TIPO_REVISION_DOCUMENTO => ['etiqueta' => 'Documento en revisión', 'ayuda' => 'El documento histórico tiene un formato que cambia lo que se imprimía.'],
        Conciliacion::TIPO_IDENTIDAD_AMBIGUA => ['etiqueta' => 'Identidad ambigua', 'ayuda' => 'Un mismo documento con varios nombres que el portal no puede distinguir con seguridad.'],
        Conciliacion::TIPO_PLANTILLA_CANDIDATA => ['etiqueta' => 'Plantilla con candidata', 'ayuda' => 'La imagen del evento no está, pero hay una imagen candidata por revisar.'],
        Conciliacion::TIPO_PLANTILLA_FALTANTE => ['etiqueta' => 'Plantilla no localizada', 'ayuda' => 'La imagen histórica del evento no se encontró y no hay candidata.'],
        Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO => ['etiqueta' => 'Plantilla de tipo no compatible', 'ayuda' => 'El contenido existe pero la referencia histórica no tiene un tipo compatible.'],
    ];

    public const ESTADOS = [
        Conciliacion::ABIERTO => ['etiqueta' => 'Abierto', 'tono' => 'amber'],
        Conciliacion::RESUELTO => ['etiqueta' => 'Resuelto', 'tono' => 'emerald'],
        Conciliacion::DESCARTADO => ['etiqueta' => 'Descartado', 'tono' => 'slate'],
        Conciliacion::REQUIERE_SOPORTE => ['etiqueta' => 'Requiere soporte', 'tono' => 'rose'],
    ];

    /** Motivos técnicos del migrador, en lenguaje claro. */
    public const MOTIVOS = [
        'DIF_VERIF' => 'Difiere el código', 'DIF_CORREO' => 'Difiere el correo', 'DIF_NOMBRE' => 'Difiere el nombre', 'DIF_TIPO' => 'Difiere el tipo de documento', 'DIF_DOCUMENTO' => 'Difiere el documento',
        'DOC_VACIO' => 'Documento vacío', 'DOC_LETRAS' => 'El documento contiene letras', 'DOC_SEPARADORES' => 'El documento tiene separadores', 'DOC_WHITESPACE_CAMBIA_IMPRESION' => 'Espacios que cambian la impresión', 'DOC_OTRO' => 'Documento con caracteres no numéricos',
        'CORREO_CRUZA_GRUPOS' => 'Un correo aparece en más de un nombre', 'GRUPO_SIN_VIA_CORREO_COMPARTIDO' => 'Grupo sin vía: su correo está compartido con otro registro', 'GRUPO_SIN_VIA_EVIDENCIA_EXTERNA' => 'Grupo sin vía: requiere evidencia externa', 'GRUPO_SIN_VIA_SIN_CORREO' => 'Grupo sin vía: no tiene correo propio', 'SIN_CORREOS_VALIDOS' => 'Sin correos válidos', 'SIN_ALCANCE_HABILITANTE' => 'Ningún nombre puede habilitarse', 'DOCUMENTO_INVALIDO' => 'Documento vacío o inválido',
        'ARCHIVO_FALTANTE_CON_CANDIDATA' => 'Imagen faltante con candidata', 'ARCHIVO_FALTANTE' => 'Imagen no localizada', 'EVENTO_SIN_PLANTILLA_UTILIZABLE' => 'Evento sin plantilla utilizable', 'TIPO_NO_SOPORTADO' => 'Tipo de imagen no compatible', 'EXTENSION_INVALIDA' => 'Extensión no válida',
    ];

    public static function tipo(string $tipo): array
    {
        return self::TIPOS[$tipo] ?? ['etiqueta' => $tipo, 'ayuda' => ''];
    }

    public static function estado(string $estado): array
    {
        return self::ESTADOS[$estado] ?? ['etiqueta' => $estado, 'tono' => 'slate'];
    }

    /** `DIF_VERIF,DIF_CORREO` → «Difiere el código · Difiere el correo». */
    public static function motivo(?string $motivo): ?string
    {
        if ($motivo === null || $motivo === '') {
            return null;
        }

        return implode(' · ', array_map(fn ($m) => self::MOTIVOS[trim($m)] ?? trim($m), explode(',', $motivo)));
    }

    /** `María Pérez` → `M*** P****` (solo iniciales): suficiente para distinguir variantes sin exponer el nombre. */
    public static function nombreEnmascarado(?string $nombre): string
    {
        $palabras = preg_split('/\s+/u', trim((string) $nombre), -1, PREG_SPLIT_NO_EMPTY) ?: [];
        if ($palabras === []) {
            return '—';
        }

        return implode(' ', array_map(fn ($p) => mb_substr($p, 0, 1).str_repeat('*', max(0, min(mb_strlen($p) - 1, 4))), $palabras));
    }

    /** `ana.perez@dominio.com` → `a***@dominio.com`. */
    public static function correoEnmascarado(?string $correo): string
    {
        $correo = (string) $correo;
        $arroba = strrpos($correo, '@');
        if ($arroba === false || $arroba === 0) {
            return '***';
        }

        return mb_substr($correo, 0, 1).'***@'.substr($correo, $arroba + 1);
    }
}
