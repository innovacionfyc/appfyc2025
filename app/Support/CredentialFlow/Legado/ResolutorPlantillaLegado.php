<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;

/**
 * De dónde sale la imagen de fondo de un certificado histórico. La arquitectura NO depende de que las 400 MB estén ya en el
 * almacenamiento definitivo: en producción será el storage privado (ResolutorPlantillaStorage) y para QA/pruebas puede ser el
 * material controlado de migración (ResolutorPlantillaDirectorio).
 */
interface ResolutorPlantillaLegado
{
    /** La plantilla utilizable del certificado (sin ella, PlantillaImagen apunta a un archivo que no existe y el renderer se niega). */
    public function resolver(CertificadoLegado $certificado): PlantillaImagen;
}
