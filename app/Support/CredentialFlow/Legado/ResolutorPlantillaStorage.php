<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;
use Illuminate\Support\Facades\Storage;

/** Plantilla desde el storage privado definitivo (`cf_plantillas_legado_contenidos.ruta_almacenada`, p. ej. credential-flow/legado/plantillas/{sha}/original.png). */
final class ResolutorPlantillaStorage implements ResolutorPlantillaLegado
{
    public function __construct(private readonly string $disco = 'local') {}

    public function resolver(CertificadoLegado $certificado): PlantillaImagen
    {
        $entrada = $certificado->plantillaLegado;
        if ($entrada === null) {
            return new PlantillaImagen('', null, null, 'faltante', false);
        }
        $ruta = $entrada->contenido?->ruta_almacenada;

        return $entrada->comoImagen($ruta === null ? '' : Storage::disk($this->disco)->path($ruta));
    }
}
