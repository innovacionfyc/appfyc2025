<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;

/**
 * Adaptador LOCAL para QA y pruebas: resuelve la imagen desde una carpeta de material controlado (p. ej. el certImages de la
 * migración) por el NOMBRE original de la entrada del catálogo (`document/certImages/NOMBRE`). No copia nada. El renderer sigue
 * verificando el SHA-256 contra el catálogo antes de dibujar.
 */
final class ResolutorPlantillaDirectorio implements ResolutorPlantillaLegado
{
    public function __construct(private readonly string $directorio) {}

    public function resolver(CertificadoLegado $certificado): PlantillaImagen
    {
        $entrada = $certificado->plantillaLegado;
        if ($entrada === null) {
            return new PlantillaImagen('', null, null, 'faltante', false);
        }
        $nombre = substr((string) $entrada->ruta_original, (int) strrpos((string) $entrada->ruta_original, '/') + 1);

        return $entrada->comoImagen(rtrim($this->directorio, '/\\').DIRECTORY_SEPARATOR.$nombre);
    }
}
