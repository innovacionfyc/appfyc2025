<?php

namespace App\Http\Controllers\CredentialFlow;

use App\Http\Controllers\Controller;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use App\Support\CredentialFlow\Legado\CongeladoNoPermitido;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\PdfHistoricoInconsistente;
use App\Support\CredentialFlow\Legado\RenderNoPermitido;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ruta ADMINISTRATIVA (autenticada, mismo permiso que el módulo) para obtener el PDF de un certificado histórico elegible. Es el
 * punto de prueba de la arquitectura de lazy freeze: NO hay botón en la interfaz del histórico (que sigue siendo de lectura) y la
 * verificación pública NO ofrece descarga. La primera llamada genera y congela el PDF (de forma idempotente); las siguientes lo
 * verifican y lo reutilizan. Siempre privado, sin caché, sin URL directa.
 */
class HistoricoPdfController extends Controller
{
    public function __construct(private readonly CongeladorLegado $congelador) {}

    public function pdf(CertificadoLegado $certificado): Response
    {
        try {
            $archivo = $this->congelador->servir($certificado);
        } catch (CongeladoNoPermitido $e) {
            // Mensaje técnico para el administrador (código del motivo), sin datos personales.
            return response($e->getMessage(), 409, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        } catch (PdfHistoricoInconsistente $e) {
            return response($e->getMessage(), 500, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        } catch (CodigoHistoricoException $e) {
            return response($e->getMessage(), 409, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        } catch (RenderNoPermitido $e) {
            return response($e->codigo, 422, ['Content-Type' => 'text/plain; charset=UTF-8', 'Cache-Control' => 'no-store']);
        }

        // BinaryFileResponse marca `public` por defecto: se fuerza privado y sin caché.
        $respuesta = new BinaryFileResponse($this->congelador->rutaFisica($archivo), 200, [
            'Content-Type' => 'application/pdf',
            'X-Content-Type-Options' => 'nosniff',
            'X-PDF-SHA256' => $archivo->sha256,
        ], false, 'inline');
        $respuesta->setContentDisposition('inline', 'certificado.pdf');
        $respuesta->headers->set('Cache-Control', 'private, no-store, max-age=0');

        return $respuesta;
    }
}
