<?php

namespace App\Support\CredentialFlow;

/**
 * Esquema (versión 1) del diseño de una plantilla de Credential Flow.
 *
 * Es la única fuente de verdad de los límites: el FormRequest valida con estas constantes y el
 * editor las recibe como props, así que ambos lados no pueden desincronizarse.
 *
 * Todas las medidas están en PUNTOS PDF (1 pt = 1/72 in), con origen en la esquina superior
 * izquierda de la página. Nunca se guardan píxeles del navegador.
 */
final class DisenoSchema
{
    public const VERSION = 1;

    public const TIPO_TEXTO = 'text';

    public const TIPOS = [self::TIPO_TEXTO];

    public const FUENTES = ['Figtree', 'Arial', 'sans-serif'];

    public const PESOS = [300, 400, 500, 600, 700, 800];

    public const ALINEACIONES = ['left', 'center', 'right'];

    /** Dimensiones razonables de una página (una carta es 612 × 792; un A0 ronda 2384 × 3370). */
    public const PAGINA_MIN = 100;

    public const PAGINA_MAX = 4000;

    public const MAX_ELEMENTOS = 100;

    public const TEXTO_MAX = 500;

    public const FONT_SIZE_MIN = 4;

    public const FONT_SIZE_MAX = 200;

    public const ELEMENTO_MIN = 1;

    /** Tolerancia (pt) para que un elemento pegado al borde no falle por redondeos del navegador. */
    public const TOLERANCIA = 0.5;

    /** Valores que el editor necesita conocer. */
    public static function paraEditor(): array
    {
        return [
            'version' => self::VERSION,
            'fuentes' => self::FUENTES,
            'pesos' => self::PESOS,
            'alineaciones' => self::ALINEACIONES,
            'maxElementos' => self::MAX_ELEMENTOS,
            'textoMax' => self::TEXTO_MAX,
            'fontSizeMin' => self::FONT_SIZE_MIN,
            'fontSizeMax' => self::FONT_SIZE_MAX,
            'elementoMin' => self::ELEMENTO_MIN,
        ];
    }
}
