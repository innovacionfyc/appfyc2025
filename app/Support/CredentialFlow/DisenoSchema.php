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
 *  - Solo un campo dinámico puede llevar, de forma OPCIONAL (schema_version 3), `prefix` y `suffix` (texto fijo antes y
 *    después del valor: prefijo + valor + sufijo se tratan como UN solo texto al medir, centrar y dibujar) y
 *    `multiline` (true = varias líneas: salto por palabras dentro del ancho de la caja). Las claves solo existen cuando
 *    tienen valor: un diseño sin ellas es idéntico al de siempre.
 * El catálogo de campos vive en CamposDinamicos; aquí solo están las reglas de estructura y de
 * presentación.
 */
final class DisenoSchema
{
    /** Versión base: solo elementos de texto. Las plantillas existentes se quedan aquí mientras no lleven QR. */
    public const VERSION = 1;

    /** Versión que añade el elemento `qr`. Es la MÍNIMA requerida: un diseño de solo texto sigue siendo schema 1. */
    public const VERSION_QR = 2;

    /** Versión que añade `prefix`, `suffix` y `multiline` en los campos dinámicos. Mínima requerida si algún elemento los usa. */
    public const VERSION_TEXTO_AVANZADO = 3;

    public const VERSIONES_SOPORTADAS = [self::VERSION, self::VERSION_QR, self::VERSION_TEXTO_AVANZADO];

    /** Largo máximo del prefijo y del sufijo de un campo dinámico (caracteres). */
    public const AFIJO_MAX = 100;

    public const TIPO_TEXTO = 'text';

    public const TIPO_QR = 'qr';

    public const TIPOS = [self::TIPO_TEXTO, self::TIPO_QR];

    /**
     * QR de verificación (schema 2): elemento OPCIONAL, como máximo uno por plantilla y solo en la página 1.
     * Es cuadrado; su caja INCLUYE la zona de silencio (QR_QUIET_MODULOS módulos por lado) y un fondo blanco.
     * Negro sobre blanco y corrección de errores M fijos. Tamaño mínimo 85 pt (≈3 cm): decisión tomada con la evidencia de decodificación simulada (ver el reporte de la Fase 8); pendiente de confirmar con un celular real.
     */
    public const QR_MAX = 1;

    public const QR_MIN_PT = 85;

    public const QR_MAX_PT = 240;

    public const QR_RECOMENDADO_PT = 100;

    public const QR_TOLERANCIA_CUADRADO_PT = 0.01;

    public const QR_ECC = 'M';

    public const QR_QUIET_MODULOS = 4;

    /** Familias HEREDADAS (siguen validando, sin soporte PDF). Las reproducibles viven en FuentesCredential. */
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

    /**
     * Interlineado del texto fijo con saltos manuales y de los campos dinámicos multilínea: cada línea baja
     * INTERLINEADO × tamaño y el bloque completo queda centrado verticalmente en la caja. El editor y el generador de
     * PDF usan este mismo valor. (El texto fijo no tiene wrap automático; un campo dinámico es una línea salvo que
     * tenga `multiline`, y entonces se reparte por palabras dentro del ancho de la caja.)
     */
    public const INTERLINEADO_TEXTO_FIJO = 1.2;

    /** Holgura (pt) al comparar el tamaño del PDF base con `page` del diseño. */
    public const TOLERANCIA_PAGINA_PDF_PT = 0.05;

    public const FONT_SIZE_MIN = 4;

    public const FONT_SIZE_MAX = 200;

    public const ELEMENTO_MIN = 1;

    /** Tolerancia (pt) para que un elemento pegado al borde no falle por redondeos del navegador. */
    public const TOLERANCIA = 0.5;

    public static function soportada(int $version): bool
    {
        return in_array($version, self::VERSIONES_SOPORTADAS, true);
    }

    /** @param  array<int,mixed>  $elementos */
    public static function contarQr(array $elementos): int
    {
        return count(array_filter($elementos, fn ($e) => is_array($e) && ($e['type'] ?? null) === self::TIPO_QR));
    }

    /** @param  array<string,mixed>  $diseno */
    public static function tieneQr(array $diseno): bool
    {
        return self::contarQr($diseno['elements'] ?? []) > 0;
    }

    /** ¿Algún campo dinámico usa prefijo, sufijo o varias líneas? */
    public static function usaTextoAvanzado(array $diseno): bool
    {
        foreach ($diseno['elements'] ?? [] as $e) {
            if (! is_array($e) || ($e['type'] ?? null) !== self::TIPO_TEXTO || ($e['field'] ?? null) === null) {
                continue;
            }
            if ((string) ($e['prefix'] ?? '') !== '' || (string) ($e['suffix'] ?? '') !== '' || ($e['multiline'] ?? false) === true) {
                return true;
            }
        }

        return false;
    }

    /**
     * Versión MÍNIMA que necesita un diseño: 3 si algún campo usa prefijo/sufijo/varias líneas, 2 si lleva QR, 1 si es
     * solo texto sencillo (las plantillas existentes no cambian).
     */
    public static function versionPara(array $diseno): int
    {
        if (self::usaTextoAvanzado($diseno)) {
            return self::VERSION_TEXTO_AVANZADO;
        }

        return self::tieneQr($diseno) ? self::VERSION_QR : self::VERSION;
    }

    /** Valores que el editor necesita conocer. */
    public static function paraEditor(): array
    {
        return [
            'version' => self::VERSION,
            'versionQr' => self::VERSION_QR,
            'versionTextoAvanzado' => self::VERSION_TEXTO_AVANZADO,
            'afijoMax' => self::AFIJO_MAX,
            'qr' => [
                'max' => self::QR_MAX,
                'minimo' => self::QR_MIN_PT,
                'maximo' => self::QR_MAX_PT,
                'recomendado' => self::QR_RECOMENDADO_PT,
                'quietModulos' => self::QR_QUIET_MODULOS,
                'ecc' => self::QR_ECC,
            ],
            'fuentes' => FuentesCredential::paraEditor(),
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
            'interlineadoTextoFijo' => self::INTERLINEADO_TEXTO_FIJO,
        ];
    }
}
