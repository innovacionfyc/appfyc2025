<?php

namespace Tests\Feature\CredentialFlow\Support;

use App\Support\CredentialFlow\Generacion\FuentesTcpdf;

/** Crea PDFs base de prueba (vectoriales, xref clásico: legibles por FPDI gratuito) sin binarios en el repo. */
final class PdfBase
{
    public static function crear(float $ancho = 792, float $alto = 612, int $rotate = 0): string
    {
        FuentesTcpdf::configurar();

        $pdf = new \TCPDF($ancho >= $alto ? 'L' : 'P', 'pt', [$ancho, $alto], true, 'UTF-8', false);
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(0, 0, 0);
        $pdf->SetAutoPageBreak(false, 0);
        $pdf->AddPage($ancho >= $alto ? 'L' : 'P', [$ancho, $alto]);
        $pdf->SetFillColor(230, 230, 230);
        $pdf->Rect(10, 10, $ancho - 20, $alto - 20, 'F'); // vector de la plantilla
        // Marcas asimétricas (en el espacio sin rotar): rojo arriba a la izquierda, azul abajo a la derecha.
        $pdf->SetFillColor(255, 0, 0);
        $pdf->Rect(30, 30, 120, 60, 'F');
        $pdf->SetFillColor(0, 0, 255);
        $pdf->Rect($ancho - 90, $alto - 60, 60, 30, 'F');

        return $rotate === 0 ? $pdf->Output('', 'S') : self::conRotate($pdf->Output('', 'S'), $rotate);
    }

    /**
     * Cambia el `/Rotate 0` que TCPDF escribe en la página por `/Rotate N`, SIN mover ningún offset
     * del PDF (la tabla xref sigue siendo válida para FPDI): compensa la diferencia de longitud
     * quitando espacios de `/Annots [ 4 0 R ]`.
     */
    public static function conRotate(string $pdf, int $rotate): string
    {
        $nuevo = "/Rotate {$rotate}";
        $sobra = strlen($nuevo) - strlen('/Rotate 0');
        $pdf = str_replace('/Rotate 0', $nuevo, $pdf, $reemplazos);
        $pdf = str_replace('/Annots [ 4 0 R ]', '/Annots ['.str_repeat(' ', max(0, 2 - $sobra)).'4 0 R'.str_repeat(' ', max(0, 2 - $sobra)).']', $pdf, $anot);

        if ($reemplazos !== 1 || $anot !== 1 || $sobra > 2) {
            throw new \RuntimeException('El PDF base de prueba no tiene la forma esperada para agregar /Rotate.');
        }

        return $pdf;
    }
}
