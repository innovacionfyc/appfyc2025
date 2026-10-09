<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Support\CredentialFlow\DisenoSchema;
use Illuminate\Support\Str;

/**
 * Diseño INICIAL de un clon moderno de una plantilla histórica. Reproduce la disposición del renderer histórico (nombre, documento y un QR
 * moderno en lugar del código impreso), expresada como FRACCIONES de la página: el sistema viejo estiraba la imagen a A4 apaisado (297×210 mm)
 * y la posicionaba en milímetros, así que cada coordenada se traslada por proporción al tamaño real del PDF base. NO incluye fecha ni intensidad
 * (el legado no las imprimía). Es un punto de partida: se revisa y guarda en el editor (Fase 10B-2B-2B).
 */
final class DisenoReemplazo
{
    private const A4_ALTO_MM = 210.0;

    /** Ancho en puntos del A4 apaisado del renderer histórico (para escalar las tipografías). */
    private const A4_ANCHO_PT = 842.0;

    private const QR_PT = 96.0;

    private const MARGEN_QR_PT = 30.0;

    /** Prefijo del documento: el tipo de documento que implica se comprueba en ReemplazoHistorico::TIPO_POR_PREFIJO. */
    public const PREFIJO_DOCUMENTO = 'C.C. ';

    /** @return array{page:array{width:float,height:float},elements:list<array<string,mixed>>} */
    public static function inicial(float $ancho, float $alto): array
    {
        $escala = $ancho / self::A4_ANCHO_PT;
        $yPt = fn (float $mm) => round($mm / self::A4_ALTO_MM * $alto, 2);

        $nombreAlto = 44.0;
        $docAlto = 30.0;
        $margen = round($ancho * 0.1, 2);

        return [
            'page' => ['width' => round($ancho, 2), 'height' => round($alto, 2)],
            'elements' => [
                [
                    'id' => (string) Str::uuid(), 'type' => DisenoSchema::TIPO_TEXTO, 'field' => 'nombre_completo', 'text' => '',
                    'x' => $margen, 'y' => round($yPt(80.5) - $nombreAlto / 2, 2), 'width' => round($ancho - 2 * $margen, 2), 'height' => $nombreAlto,
                    'fontFamily' => 'outfit', 'fontSize' => round(26 * $escala, 1), 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
                ],
                [
                    'id' => (string) Str::uuid(), 'type' => DisenoSchema::TIPO_TEXTO, 'field' => 'documento', 'text' => '',
                    'x' => $margen, 'y' => round($yPt(95.0) - $docAlto / 2, 2), 'width' => round($ancho - 2 * $margen, 2), 'height' => $docAlto,
                    'fontFamily' => 'outfit', 'fontSize' => round(14 * $escala, 1), 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center',
                    'prefix' => self::PREFIJO_DOCUMENTO,
                ],
                [
                    'id' => (string) Str::uuid(), 'type' => DisenoSchema::TIPO_QR,
                    'x' => round($ancho - self::QR_PT - self::MARGEN_QR_PT, 2), 'y' => round($alto - self::QR_PT - self::MARGEN_QR_PT, 2), 'width' => self::QR_PT, 'height' => self::QR_PT,
                ],
            ],
        ];
    }
}
