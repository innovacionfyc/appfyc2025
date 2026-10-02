<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\DisenoSchema;
use Closure;

/**
 * Reparto en varias líneas de un campo dinámico con «Permitir varias líneas». Es el port EXACTO de
 * resources/js/Composables/CredentialFlow/multilinea.js (mismas reglas y mismos redondeos; se comprueba con vectores
 * compartidos con Node).
 *
 * Política:
 *  1. Primero se ENVUELVE a su tamaño configurado: salto por palabras (nunca se parte una palabra) respetando el
 *     ancho de la caja y los saltos manuales.
 *  2. Si cabe (ancho de cada línea y alto total del bloque dentro de la caja), se usa el tamaño configurado.
 *  3. Si no, se reduce en pasos de PASO_AJUSTE_PT (como mucho hasta ESCALA_MINIMA_TEXTO_DINAMICO) y se vuelve a envolver
 *     en cada paso: se elige el tamaño más grande con el que el bloque cabe.
 *  4. Si ni al mínimo cabe, NO se trunca ni se baja más: el elemento queda «No cabe» (el generador se niega a imprimir).
 * El tamaño configurado guardado en el diseño no cambia: el ajustado es solo de representación.
 */
final class Multilinea
{
    /**
     * @param  Closure(string,float):float  $medir  ancho (pt) de un texto a un tamaño
     * @param  array<int,string>  $parrafos  texto ya en NFC, partido por saltos manuales
     * @return array{size:float, reducido:bool, noCabe:bool, lineas:array<int,string>}
     */
    public static function resolver(Closure $medir, array $parrafos, float $anchoCaja, float $altoCaja, float $fontSize): array
    {
        $tolerancia = DisenoSchema::TOLERANCIA_AJUSTE_PT;
        $paso = DisenoSchema::PASO_AJUSTE_PT;
        $piso = self::redondearArriba($fontSize * DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO, $paso);

        $i = 0;
        while (true) {
            $size = $fontSize - $i * $paso;
            $lineas = self::envolver(fn (string $t) => $medir($t, $size), $parrafos, $anchoCaja, $tolerancia);

            $anchoMax = 0.0;
            foreach ($lineas as $linea) {
                $anchoMax = max($anchoMax, $medir($linea, $size));
            }
            $altoTotal = count($lineas) * DisenoSchema::INTERLINEADO_TEXTO_FIJO * $size;
            $cabe = $anchoMax <= $anchoCaja + $tolerancia && $altoTotal <= $altoCaja + $tolerancia;

            $siguiente = $fontSize - ($i + 1) * $paso;
            if ($cabe || $siguiente < $piso - 1e-9) {
                return [
                    'size' => self::dosDecimales($size),
                    'reducido' => $size < $fontSize,
                    'noCabe' => ! $cabe,
                    'lineas' => $lineas,
                ];
            }
            $i++;
        }
    }

    /**
     * Salto por palabras (separadas por espacios). Una palabra más ancha que la caja queda sola en su línea (no se
     * parte): el llamador la detecta porque esa línea excede el ancho. Un párrafo vacío es una línea en blanco.
     *
     * @param  Closure(string):float  $medirLinea
     * @param  array<int,string>  $parrafos
     * @return array<int,string>
     */
    public static function envolver(Closure $medirLinea, array $parrafos, float $anchoCaja, float $tolerancia): array
    {
        $lineas = [];
        foreach ($parrafos as $parrafo) {
            $palabras = array_values(array_filter(explode(' ', $parrafo), fn (string $p) => $p !== ''));
            if ($palabras === []) {
                $lineas[] = '';

                continue;
            }

            $actual = $palabras[0];
            foreach (array_slice($palabras, 1) as $palabra) {
                $candidata = $actual.' '.$palabra;
                if ($medirLinea($candidata) <= $anchoCaja + $tolerancia) {
                    $actual = $candidata;
                } else {
                    $lineas[] = $actual;
                    $actual = $palabra;
                }
            }
            $lineas[] = $actual;
        }

        return $lineas;
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
