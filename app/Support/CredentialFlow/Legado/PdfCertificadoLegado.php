<?php

namespace App\Support\CredentialFlow\Legado;

/**
 * El PDF del certificado histórico: FPDF 1.81 con la misma Header() del sistema viejo (imagen a página completa) y DOS
 * cambios solo de determinismo, que no afectan a nada visible:
 *   1. CreationDate fija (la que se le pasa) en lugar de «la hora de ahora»;
 *   2. compresión desactivada (el contenido queda en claro: más fácil de auditar y sin variabilidad entre builds de zlib).
 *
 * NO se carga con el autoload: extiende \FPDF, que solo existe tras Fpdf181::cargar() (que es quien incluye este archivo).
 */
final class PdfCertificadoLegado extends \FPDF
{
    public function __construct(private readonly string $legImagen, private readonly string $legTipoImagen, private readonly string $legFechaCreacion)
    {
        // Idéntico al sistema viejo: horizontal, milímetros, A4 (297 x 210).
        parent::__construct('L', 'mm', 'A4');
        $this->SetCompression(false);
    }

    /** Fondo: la imagen del evento a página completa (x=0, y=0, 297 x 210 mm). El tipo se pasa explícito (decidido por la extensión del nombre original). */
    public function Header(): void
    {
        $this->Image($this->legImagen, 0, 0, 297, 210, $this->legTipoImagen);
    }

    /** Igual que FPDF 1.81 salvo la fecha, que es la fijada (`D:YYYYMMDDHHMMSS`, en UTC). */
    protected function _putinfo()
    {
        $this->metadata['Producer'] = 'FPDF '.FPDF_VERSION;
        $this->metadata['CreationDate'] = $this->legFechaCreacion;
        foreach ($this->metadata as $key => $value) {
            $this->_put('/'.$key.' '.$this->_textstring($value));
        }
    }
}
