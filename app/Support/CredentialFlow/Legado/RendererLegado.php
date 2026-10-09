<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\PlantillaLegado;
use App\Support\CredentialFlow\StagingEv\Normalizador;
use Throwable;

/**
 * Renderer HISTÓRICO determinista: reproduce el certificado del sistema viejo con FPDF 1.81 exacto.
 *
 * Disposición (idéntica al sistema viejo; A4 horizontal, milímetros, una sola página):
 *   fondo ........ Image(imagen, 0, 0, 297, 210)            (Header de la página)
 *   Ln(80)
 *   nombre ....... Arial Bold 26   Cell(0, 1,  nombre,            0, 0, 'C')
 *   Ln(10)
 *   documento .... Arial Bold 14   Cell(0, 10, «TIPO: 1.234.567», 0, 0, 'C')
 *   Ln(79)
 *   código ....... Arial Bold 6    Cell(0, 10, código,            0, 0, 'R')
 * («Arial» es helvetica en FPDF: Helvetica-Bold). Los textos pasan por utf8_decode() de PHP 7.4 (TextoLegado) y el documento
 * por number_format() de PHP 7.4 (FormatoLegado).
 *
 * Es una función pura: no lee el reloj, no guarda nada, no toca la base de datos. Si algo no cuadra se NIEGA
 * (RenderNoPermitido) en lugar de corregir. Lo que no es representable en ISO-8859-1 se reproduce como «?» (igual que
 * antes) pero se REPORTA en los metadatos.
 */
final class RendererLegado
{
    /** Versión del procedimiento de render; entra en los metadatos (si cambia el diseño, cambia esto). */
    public const VERSION = 'legado-1';

    /** Advertencia (no bloquea): el certificado histórico se imprimía con el tipo de documento vacío («: 1.234.567»). */
    public const ADVERTENCIA_TIPO_DOCUMENTO_VACIO = 'TIPO_DOCUMENTO_VACIO';

    private const TIPOS_FPDF = ['jpg', 'jpeg', 'png', 'gif'];

    public function render(SolicitudRender $s): ResultadoRender
    {
        $tipoImagen = $this->validar($s);

        try {
            Fpdf181::cargar();

            $nombre = TextoLegado::utf8Decode($s->nombre);
            $linea = TextoLegado::utf8Decode(FormatoLegado::lineaDocumento($s->tipoDocumento, $s->documento));
            $codigo = TextoLegado::utf8Decode((string) $s->codigoLegado);

            $pdf = new PdfCertificadoLegado($s->plantilla->rutaFisica, $tipoImagen, 'D:'.gmdate('YmdHis', $s->fechaCreacion->getTimestamp()));
            $pdf->AddPage();
            $pdf->Ln(80);
            $pdf->SetFont('Arial', 'B', 26);
            $pdf->Cell(0, 1, $nombre['bytes'], 0, 0, 'C');
            $pdf->Ln(10);
            $pdf->SetFont('Arial', 'B', 14);
            $pdf->Cell(0, 10, $linea['bytes'], 0, 0, 'C');
            $pdf->Ln(79);
            $pdf->SetFont('Arial', 'B', 6);
            $pdf->Cell(0, 10, $codigo['bytes'], 0, 0, 'R');

            $paginas = $pdf->PageNo();
            $salida = $pdf->Output('S');
        } catch (RenderNoPermitido $e) {
            throw $e;
        } catch (Throwable $e) {
            // Los mensajes de FPDF no llevan nombres ni documentos; se conserva solo el texto técnico.
            throw new RenderNoPermitido(RenderNoPermitido::ERROR_FPDF, mb_substr($e->getMessage(), 0, 200));
        }

        if ($paginas !== 1) {
            throw new RenderNoPermitido(RenderNoPermitido::VARIAS_PAGINAS, "El certificado ocuparía {$paginas} páginas; debe ser una sola.");
        }

        $noRepresentables = array_values(array_unique(array_merge($nombre['no_representables'], $linea['no_representables'], $codigo['no_representables'])));
        sort($noRepresentables);

        return new ResultadoRender($salida, hash('sha256', $salida), strlen($salida), [
            'renderer' => self::VERSION,
            'fpdf' => Fpdf181::VERSION,
            'fpdf_sha256' => Fpdf181::SHA256,
            'php' => PHP_VERSION,
            'paginas' => $paginas,
            'pagina_mm' => [297, 210],
            'orientacion' => 'L',
            'compresion' => false,
            'creation_date' => 'D:'.gmdate('YmdHis', $s->fechaCreacion->getTimestamp()),
            'imagen_sha256' => $s->plantilla->sha256,
            'imagen_tipo' => $tipoImagen,
            // Reporte de fidelidad: lo que el sistema viejo imprimía como «?».
            'caracteres_no_representables' => array_map(fn (int $cp) => sprintf('U+%04X', $cp), $noRepresentables),
            'secuencias_utf8_invalidas' => $nombre['invalidos'] + $linea['invalidos'] + $codigo['invalidos'],
            // Advertencias técnicas: el PDF se genera igual (fidelidad histórica), pero queda constancia.
            'advertencias' => trim((string) $s->tipoDocumento) === '' ? [self::ADVERTENCIA_TIPO_DOCUMENTO_VACIO] : [],
        ]);
    }

    /**
     * Todas las negativas, ANTES de generar nada. Devuelve el tipo de imagen para FPDF (jpg|png|gif).
     *
     * @throws RenderNoPermitido
     */
    private function validar(SolicitudRender $s): string
    {
        if (trim($s->nombre) === '') {
            throw new RenderNoPermitido(RenderNoPermitido::DATOS_FALTANTES, 'Falta el nombre.');
        }
        if (trim((string) $s->documento) === '') {
            throw new RenderNoPermitido(RenderNoPermitido::DATOS_FALTANTES, 'Falta el documento.');
        }
        // El tipo de documento NO es obligatorio: el sistema viejo imprimía literalmente `tipo.': '.número`, así que un tipo vacío
        // sale «: 1.234.567». No se rellena, no se inventa y no se ocultan los dos puntos; solo se advierte en los metadatos.
        if ($s->codigoLegado === null || preg_match('/^\d{1,10}$/', $s->codigoLegado) !== 1) {
            throw new RenderNoPermitido(RenderNoPermitido::DATOS_FALTANTES, 'Falta el código del certificado o no es numérico.');
        }

        // Los documentos en REVISION_DOCUMENTO (vacío, letras, separadores, ceros a la izquierda, demasiado largo, whitespace que
        // cambia lo impreso…) NO se renderizan: no se corrige el documento.
        if (Normalizador::evaluarDocumento($s->documento)['estado'] !== 'valido') {
            throw new RenderNoPermitido(RenderNoPermitido::DOCUMENTO_REVISION, 'El documento está en revisión (REVISION_DOCUMENTO); no se genera automáticamente.');
        }

        $p = $s->plantilla;
        match ($p->estado) {
            PlantillaLegado::ESTADO_OK => null,
            PlantillaLegado::ESTADO_FALTANTE => throw new RenderNoPermitido(RenderNoPermitido::PLANTILLA_FALTANTE, 'La imagen del evento no existe en el sistema viejo.'),
            PlantillaLegado::ESTADO_EXTENSION_INVALIDA => throw new RenderNoPermitido(RenderNoPermitido::EXTENSION_INVALIDA, 'El nombre de la imagen no tiene una extensión válida.'),
            default => throw new RenderNoPermitido(RenderNoPermitido::PLANTILLA_NO_OK, "La plantilla está en estado «{$p->estado}»."),
        };

        $extension = strtolower((string) $p->extensionOriginal);
        if ($extension === '') {
            throw new RenderNoPermitido(RenderNoPermitido::EXTENSION_INVALIDA, 'La imagen no tiene extensión.');
        }
        if (! in_array($extension, self::TIPOS_FPDF, true) || ! $p->renderizable) {
            throw new RenderNoPermitido(RenderNoPermitido::IMAGEN_NO_SOPORTADA, 'El FPDF del sistema viejo no habría podido dibujar esta imagen.');
        }

        if (! is_file($p->rutaFisica)) {
            throw new RenderNoPermitido(RenderNoPermitido::ARCHIVO_AUSENTE, 'El archivo de la imagen no está en el almacenamiento.');
        }
        if ($p->sha256 === null || ! hash_equals($p->sha256, (string) hash_file('sha256', $p->rutaFisica))) {
            throw new RenderNoPermitido(RenderNoPermitido::SHA_NO_COINCIDE, 'El contenido de la imagen no coincide con el SHA-256 del catálogo.');
        }

        return $extension === 'jpeg' ? 'jpg' : $extension;
    }
}
