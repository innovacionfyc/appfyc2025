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
 *
 * Contenido de un elemento de texto (schema_version 1):
 *  - `field` === null  → texto fijo: `text` contiene el texto.
 *  - `field` !== null  → campo dinámico (clave de CamposDinamicos): `text` NO tiene semántica y
 *    se guarda siempre como ''. El valor de ejemplo del editor no se guarda en el diseño.
 * El catálogo de campos vive en CamposDinamicos; aquí solo están las reglas de estructura y de
 * presentación.
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

    /**
     * Autoajuste de los campos dinámicos (una sola línea). Se reproduce igual en el editor y, más
     * adelante, en el generador de PDF:
     *  1. Se mide el ancho del valor con la fuente y el tamaño configurados (sin kerning).
     *  2. Si cabe en la caja, se usa el tamaño configurado.
     *  3. Si no, se reduce proporcionalmente (tamaño × ancho de la caja / ancho medido) y se
     *     redondea HACIA ABAJO en pasos de PASO_AJUSTE_PT.
     *  4. Nunca baja de ESCALA_MINIMA_TEXTO_DINAMICO × tamaño configurado (el piso se redondea
     *     hacia ARRIBA al mismo paso, para no quedar por debajo del porcentaje).
     *  5. Si aun así no cabe, NO se trunca: el elemento queda en estado «No cabe».
     * El tamaño configurado guardado en el diseño no cambia: el ajustado es solo de representación.
     */
    public const ESCALA_MINIMA_TEXTO_DINAMICO = 0.70;

    public const PASO_AJUSTE_PT = 0.25;

    /** Holgura (pt) al comparar un ancho medido con el de la caja. */
    public const TOLERANCIA_AJUSTE_PT = 0.01;

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
            'campos' => CamposDinamicos::paraEditor(),
            'escalaMinima' => self::ESCALA_MINIMA_TEXTO_DINAMICO,
            'pasoAjuste' => self::PASO_AJUSTE_PT,
            'toleranciaAjuste' => self::TOLERANCIA_AJUSTE_PT,
        ];
    }
}
