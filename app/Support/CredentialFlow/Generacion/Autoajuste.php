<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\DisenoSchema;

/**
 * Política de autoajuste V1 de los campos dinámicos (una línea). Es el port EXACTO de
 * resources/js/Composables/CredentialFlow/autoajuste.js: mismos redondeos y mismas constantes
 * (DisenoSchema). Se comprueba con vectores compartidos con Node.
 */
final class Autoajuste
{
    /**
     * @param  float  $ancho  ancho medido del texto al tamaño configurado (pt)
     * @return array{size:float, reducido:bool, noCabe:bool, ancho:float}
     */
    public static function resolver(float $ancho, float $anchoCaja, float $fontSize): array
    {
        $tolerancia = DisenoSchema::TOLERANCIA_AJUSTE_PT;

        if ($ancho <= $anchoCaja + $tolerancia) {
            return ['size' => $fontSize, 'reducido' => false, 'noCabe' => false, 'ancho' => $ancho];
        }

        $paso = DisenoSchema::PASO_AJUSTE_PT;
        $piso = self::redondearArriba($fontSize * DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO, $paso);
        $proporcional = self::redondearAbajo(($fontSize * $anchoCaja) / $ancho, $paso);
        $size = max($proporcional, $piso);
        $anchoFinal = $ancho * ($size / $fontSize);

        return [
            'size' => self::dosDecimales(min($size, $fontSize)),
            'reducido' => $size < $fontSize,
            'noCabe' => $anchoFinal > $anchoCaja + $tolerancia,
            'ancho' => $anchoFinal,
        ];
    }

    private static function redondearAbajo(float $valor, float $paso): float
    {
        return floor($valor / $paso + 1e-9) * $paso;
    }

    private static function redondearArriba(float $valor, float $paso): float
    {
        return ceil($valor / $paso - 1e-9) * $paso;
    }

    /** Equivale a Math.round(v * 100) / 100 de JS (redondea .5 hacia +infinito). */
    private static function dosDecimales(float $v): float
    {
        return floor($v * 100 + 0.5) / 100;
    }
}
