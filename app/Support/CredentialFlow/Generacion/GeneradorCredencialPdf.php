<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
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
    /**
     * Versión del comportamiento de dibujo. Se incrementa SOLO cuando cambia algo que puede alterar la salida
     * (matemática, línea base, autoajuste, interpretación del schema, motor PDF…); no depende de commits.
     * Cada emisión la guarda en su snapshot.
     */
    public const GENERADOR_VERSION = 2; // 2 = añade el elemento `qr` (schema 2); la salida de los diseños sin QR no cambia

    /** Devuelve los bytes del PDF generado con el diseño VIVO de la plantilla. @throws GeneracionCredencialException */
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

        // PDF de prueba/preview: si el diseño lleva QR se dibuja uno REAL con el código de ejemplo (que no existe en BD).
        $url = DisenoSchema::tieneQr($diseno) ? UrlVerificacion::ejemplo() : null;

        return self::generarDesde($diseno, $disco->get($ruta), $datos, (int) $plantilla->schema_version, ['plantilla' => $plantilla->id], $url);
    }

    /**
     * Renderiza con un diseño y un PDF base EXPLÍCITOS (sin leer el modelo vivo): es lo que usan las emisiones
     * para dibujar desde su snapshot inmutable. Misma matemática que generar(); nada se escribe en disco.
     *
     * @param  array<string,mixed>  $diseno
     * @param  array<string,mixed>  $contexto  solo para el log (ids, nunca datos personales)
     *
     * @throws GeneracionCredencialException
     */
    public static function generarDesde(array $diseno, string $pdfBase, DatosCredencial $datos, int $schemaVersion, array $contexto = [], ?string $urlVerificacion = null): string
    {
        if (! isset($diseno['page'], $diseno['elements'])) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::SIN_DISENO, 'La plantilla no tiene un diseño guardado. Guarda el diseño en el editor primero.');
        }

        FuentesTcpdf::configurar();
        $pdf = self::nuevoPdf();

        [$plantillaPdf, $tamano] = self::importar($pdf, $pdfBase, $contexto);

        $planes = PlanificadorTexto::planificar(
            $diseno,
            $datos,
            ['width' => (float) $tamano['width'], 'height' => (float) $tamano['height']],
            $schemaVersion
        );

        $qr = PlanificadorQr::planificar($diseno, ['width' => (float) $tamano['width'], 'height' => (float) $tamano['height']], $schemaVersion);
        if ($qr !== null && ($urlVerificacion === null || $urlVerificacion === '')) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::QR_SIN_URL, 'El diseño lleva un QR pero no se recibió la URL de verificación.');
        }

        // Formato [ancho, alto] en puntos; la orientación explícita evita que TCPDF los intercambie.
        $pdf->AddPage($tamano['width'] >= $tamano['height'] ? 'L' : 'P', [$tamano['width'], $tamano['height']]);
        $pdf->useTemplate($plantillaPdf, 0, 0, $tamano['width'], $tamano['height']);

        foreach ($planes as $plan) {
            self::pintar($pdf, $plan);
        }

        if ($qr !== null) {
            DibujanteQr::dibujar($pdf, $qr, $urlVerificacion);
        }

        return $pdf->Output('', 'S');
    }

    /** Tamaño visible (pt) de la primera página del PDF base. @return array{width:float, height:float} */
    public static function tamanoPagina(string $pdfBase, array $contexto = []): array
    {
        FuentesTcpdf::configurar();
        [, $tamano] = self::importar(self::nuevoPdf(), $pdfBase, $contexto);

        return ['width' => (float) $tamano['width'], 'height' => (float) $tamano['height']];
    }

    /** @return array{0:string,1:array} plantilla importada y su tamaño */
    private static function importar(Fpdi $pdf, string $pdfBase, array $contexto): array
    {
        try {
            // Desde memoria: sin manejar rutas del servidor ni dejar un archivo abierto.
            $pdf->setSourceFile(StreamReader::createByString($pdfBase));
            $plantillaPdf = $pdf->importPage(1);

            return [$plantillaPdf, $pdf->getTemplateSize($plantillaPdf)];
        } catch (Throwable $e) {
            Log::warning('Credential Flow: no se pudo importar el PDF base', $contexto + ['error' => $e::class.': '.$e->getMessage()]);

            throw GeneracionCredencialException::con(
                GeneracionCredencialException::PDF_ILEGIBLE,
                'No se pudo leer el PDF base de la plantilla. Puede estar dañado o usar un formato que el generador todavía no soporta.'
            );
        }
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
