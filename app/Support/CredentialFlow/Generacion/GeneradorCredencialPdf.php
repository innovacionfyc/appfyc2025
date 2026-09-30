<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\FuentesCredential;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use setasign\Fpdi\PdfParser\StreamReader;
use setasign\Fpdi\Tcpdf\Fpdi;
use Throwable;

/**
 * Genera el PDF de una credencial: importa la primera página del PDF base con FPDI (sin
 * rasterizar: queda como XObject) y escribe encima los textos del plan con TCPDF.
 *
 * Este generador NO decide contenido ni reglas: valida la plantilla, obtiene el tamaño real del PDF
 * base y pinta el plan que produce PlanificadorTexto. El resultado se devuelve en memoria; no se
 * escribe ningún archivo.
 */
final class GeneradorCredencialPdf
{
    /** Devuelve los bytes del PDF generado. @throws GeneracionCredencialException */
    public static function generar(Plantilla $plantilla, DatosCredencial $datos): string
    {
        $disco = Storage::disk(Plantilla::DISCO);
        $ruta = $plantilla->rutaPdfEsperada();

        // La ruta física sale solo del id de la plantilla y debe coincidir con la registrada.
        if ($plantilla->archivo_pdf !== $ruta || ! $disco->exists($ruta)) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::PLANTILLA_SIN_PDF, 'No se encontró el PDF base de la plantilla.');
        }

        $diseno = $plantilla->diseno;
        if (! is_array($diseno) || ! isset($diseno['page'], $diseno['elements'])) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::SIN_DISENO, 'La plantilla no tiene un diseño guardado. Guarda el diseño en el editor primero.');
        }

        FuentesTcpdf::configurar();
        $pdf = self::nuevoPdf();

        try {
            // Desde memoria: sin manejar rutas del servidor ni dejar un archivo abierto.
            $pdf->setSourceFile(StreamReader::createByString($disco->get($ruta)));
            $plantillaPdf = $pdf->importPage(1);
            $tamano = $pdf->getTemplateSize($plantillaPdf);
        } catch (Throwable $e) {
            Log::warning('Credential Flow: no se pudo importar el PDF base', ['plantilla' => $plantilla->id, 'error' => $e::class.': '.$e->getMessage()]);

            throw GeneracionCredencialException::con(
                GeneracionCredencialException::PDF_ILEGIBLE,
                'No se pudo leer el PDF base de la plantilla. Puede estar dañado o usar un formato que el generador todavía no soporta.'
            );
        }

        $planes = PlanificadorTexto::planificar(
            $diseno,
            $datos,
            ['width' => (float) $tamano['width'], 'height' => (float) $tamano['height']],
            (int) $plantilla->schema_version
        );

        // Formato [ancho, alto] en puntos; la orientación explícita evita que TCPDF los intercambie.
        $pdf->AddPage($tamano['width'] >= $tamano['height'] ? 'L' : 'P', [$tamano['width'], $tamano['height']]);
        $pdf->useTemplate($plantillaPdf, 0, 0, $tamano['width'], $tamano['height']);

        foreach ($planes as $plan) {
            self::pintar($pdf, $plan);
        }

        return $pdf->Output('', 'S');
    }

    private static function nuevoPdf(): Fpdi
    {
        $pdf = new class('P', 'pt', 'A4', true, 'UTF-8', false) extends Fpdi
        {
            public function __construct(...$args)
            {
                parent::__construct(...$args);
                $this->tcpdflink = false; // sin el «Powered by TCPDF» del pie
            }
        };

        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->setCellPaddings(0, 0, 0, 0);
        $pdf->setCreator('F&C Credential Flow');
        $pdf->setTitle('Credencial de prueba');

        return $pdf;
    }

    /**
     * Una Cell por línea, con la altura explícita, valign M y sin altura mínima automática: TCPDF
     * coloca la línea base en y + h/2 + (ascent + descent)/2/unitsPerEm × tamaño, que es la misma
     * fórmula del plan. La celda se coloca para que esa línea base caiga en la del plan.
     */
    private static function pintar(Fpdi $pdf, TextoPlanificado $plan): void
    {
        $size = $plan->fontSizeEfectivo;
        $pdf->SetFont(FuentesTcpdf::nombre($plan->fontFamily, $plan->fontWeight), '', $size);
        [$r, $g, $b] = sscanf($plan->color, '#%02x%02x%02x');
        $pdf->SetTextColor($r, $g, $b);

        $altoCelda = $size * DisenoSchema::INTERLINEADO_TEXTO_FIJO;
        $alineacion = ['left' => 'L', 'center' => 'C', 'right' => 'R'][$plan->align];

        foreach ($plan->lineas as $linea) {
            if ($linea['texto'] === '') {
                continue;
            }
            $yCelda = $linea['baseline'] - FuentesCredential::lineaBase(0, $altoCelda, $plan->fontFamily, $plan->fontWeight, $size);
            $pdf->SetXY($plan->x, $yCelda);
            $pdf->Cell($plan->width, $altoCelda, $linea['texto'], 0, 0, $alineacion, false, '', 0, true, 'T', 'M');
        }
    }
}
