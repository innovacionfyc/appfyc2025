<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\FuentesCredential;

/**
 * Convierte el diseño guardado + los datos en un plan de dibujo validado. No sabe nada de TCPDF.
 * Es el equivalente PHP de resources/js/Composables/CredentialFlow/planTexto.js (mismo contrato,
 * comprobado con vectores compartidos).
 */
final class PlanificadorTexto
{
    /**
     * @param  array  $diseno  cf_plantillas.diseno
     * @param  array{width:float, height:float}  $paginaReal  tamaño real del PDF base (pt)
     * @return array<int,TextoPlanificado>
     */
    public static function planificar(array $diseno, DatosCredencial $datos, array $paginaReal, int $schemaVersion = DisenoSchema::VERSION): array
    {
        if (! DisenoSchema::soportada($schemaVersion)) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::SCHEMA_NO_SOPORTADO, 'La versión del diseño de esta plantilla no está soportada por el generador.');
        }

        $pagina = $diseno['page'] ?? null;
        if (! is_array($pagina) || ! isset($pagina['width'], $pagina['height'])) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::SIN_DISENO, 'La plantilla no tiene un diseño guardado.');
        }

        $tol = DisenoSchema::TOLERANCIA_PAGINA_PDF_PT;
        if (abs((float) $pagina['width'] - $paginaReal['width']) > $tol || abs((float) $pagina['height'] - $paginaReal['height']) > $tol) {
            throw GeneracionCredencialException::con(
                GeneracionCredencialException::PAGINA_DISTINTA,
                'El tamaño del PDF base no coincide con el del diseño guardado. Abre el editor y vuelve a guardar el diseño.'
            );
        }

        $planes = [];
        foreach ($diseno['elements'] ?? [] as $el) {
            $tipo = $el['type'] ?? null;
            if ($tipo === DisenoSchema::TIPO_QR && $schemaVersion >= DisenoSchema::VERSION_QR) {
                continue; // el QR lo planifica PlanificadorQr; aquí solo hay texto
            }
            if ($tipo !== DisenoSchema::TIPO_TEXTO) {
                throw GeneracionCredencialException::con(GeneracionCredencialException::SCHEMA_NO_SOPORTADO, 'El diseño contiene un elemento que la versión de su esquema no soporta.', (string) ($el['id'] ?? ''));
            }
            $planes[] = self::planificarElemento($el, $datos, (float) $pagina['width'], (float) $pagina['height']);
        }

        return $planes;
    }

    private static function planificarElemento(array $el, DatosCredencial $datos, float $anchoPagina, float $altoPagina): TextoPlanificado
    {
        $id = (string) $el['id'];
        $familia = (string) $el['fontFamily'];
        $peso = (int) $el['fontWeight'];
        $campo = $el['field'] ?? null;
        $dinamico = $campo !== null;

        if (! FuentesCredential::esReproducible($familia, $peso)) {
            throw GeneracionCredencialException::con(
                GeneracionCredencialException::FUENTE_NO_REPRODUCIBLE,
                'Un elemento usa una fuente heredada o no disponible ('.$familia.'). Cambia a Outfit y guarda el diseño para poder generar el PDF.',
                $id
            );
        }

        [$x, $y, $w, $h] = [(float) $el['x'], (float) $el['y'], (float) $el['width'], (float) $el['height']];
        $t = DisenoSchema::TOLERANCIA;
        if ($x < -$t || $y < -$t || $x + $w > $anchoPagina + $t || $y + $h > $altoPagina + $t) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::FUERA_DE_PAGINA, 'Un elemento queda fuera de los límites de la página.', $id);
        }

        // Campo dinámico: prefijo + valor + sufijo son UN solo texto (se mide, se centra y se dibuja junto).
        $multilinea = $dinamico && ($el['multiline'] ?? false) === true;
        $contenido = $dinamico
            ? (string) ($el['prefix'] ?? '').$datos->valor((string) $campo).(string) ($el['suffix'] ?? '')
            : (string) ($el['text'] ?? '');
        if ($multilinea) {
            $nfc = \Normalizer::normalize($contenido, \Normalizer::FORM_C);
            $contenido = $nfc === false ? $contenido : $nfc;
        }
        $textos = $dinamico && ! $multilinea
            ? [preg_replace('/\r?\n/', ' ', $contenido)]
            : preg_split('/\r?\n/', $contenido);

        $sizeConfigurado = (float) $el['fontSize'];

        // Cobertura de TODAS las líneas, antes de medir o ajustar: sin fallback ni «tofu».
        $medidas = [];
        foreach ($textos as $texto) {
            $m = FuentesCredential::medirTexto($texto, $familia, $peso, $sizeConfigurado);
            if (! $m['soportado']) {
                throw GeneracionCredencialException::con(
                    GeneracionCredencialException::CARACTER_NO_SOPORTADO,
                    'Un texto contiene caracteres que la fuente Outfit no tiene: '.implode(' ', array_map(fn (string $c) => "«{$c}»", $m['faltantes'])).'.',
                    $id
                );
            }
            $medidas[] = $m;
        }

        $size = $sizeConfigurado;
        $reducido = false;
        if ($multilinea) {
            // Primero se envuelve por palabras dentro del ancho; solo si el bloque no cabe en el alto se reduce (hasta el 70 %).
            $reparto = Multilinea::resolver(
                fn (string $t, float $s) => FuentesCredential::medirTexto($t, $familia, $peso, $s)['ancho'],
                $textos,
                $w,
                $h,
                $sizeConfigurado
            );
            if ($reparto['noCabe']) {
                throw GeneracionCredencialException::con(
                    GeneracionCredencialException::NO_CABE,
                    'El valor del campo no cabe en su caja ni repartido en varias líneas y reducido al '.round(DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO * 100).' %. Aumenta el alto o el ancho de la caja, o baja el tamaño.',
                    $id
                );
            }
            $size = $reparto['size'];
            $reducido = $reparto['reducido'];
            $textos = $reparto['lineas'];
        } elseif ($dinamico) {
            $ajuste = Autoajuste::resolver($medidas[0]['ancho'], $w, $sizeConfigurado);
            if ($ajuste['noCabe']) {
                throw GeneracionCredencialException::con(
                    GeneracionCredencialException::NO_CABE,
                    'El valor del campo no cabe en su caja aun reduciendo el tamaño al '.round(DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO * 100).' %. Ensancha la caja o baja el tamaño.',
                    $id
                );
            }
            $size = $ajuste['size'];
            $reducido = $ajuste['reducido'];
        }

        $align = (string) $el['align'];
        $primera = FuentesCredential::lineaBase($y, $h, $familia, $peso, $size);
        $n = count($textos);
        $lineas = [];
        foreach ($textos as $i => $texto) {
            $m = FuentesCredential::medirTexto($texto, $familia, $peso, $size);
            $xInicio = match ($align) {
                'left' => $x,
                'right' => $x + $w - $m['ancho'],
                default => $x + ($w - $m['ancho']) / 2,
            };
            $lineas[] = [
                'texto' => $m['texto'],
                'ancho' => $m['ancho'],
                'xInicio' => $xInicio,
                'baseline' => $primera + ($i - ($n - 1) / 2) * DisenoSchema::INTERLINEADO_TEXTO_FIJO * $size,
            ];
        }

        return new TextoPlanificado(
            id: $id,
            campo: $campo,
            fontFamily: $familia,
            fontWeight: $peso,
            fontSizeConfigurado: $sizeConfigurado,
            fontSizeEfectivo: $size,
            reducido: $reducido,
            x: $x,
            y: $y,
            width: $w,
            height: $h,
            align: $align,
            color: $el['color'],
            lineas: $lineas,
        );
    }
}
