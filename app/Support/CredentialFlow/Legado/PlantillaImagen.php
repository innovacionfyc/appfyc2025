<?php

namespace App\Support\CredentialFlow\Legado;

/**
 * La imagen de fondo lista para renderizar: lo que el renderer necesita saber de una entrada del catálogo
 * (cf_plantillas_legado + su contenido), sin depender de Eloquent ni de dónde esté guardado el archivo.
 */
final class PlantillaImagen
{
    /**
     * @param  string  $rutaFisica  archivo en disco (el «blob» por SHA); puede no existir si la entrada es `faltante`
     * @param  string|null  $sha256  SHA-256 del contenido registrado en el catálogo (se vuelve a calcular antes de usarlo)
     * @param  string|null  $extensionOriginal  lo que sigue al último punto del NOMBRE original: es lo que decide el tipo en FPDF
     * @param  string  $estado  uno de PlantillaLegado::ESTADOS
     * @param  bool  $renderizable  veredicto del catálogo (el FPDF viejo habría podido dibujarla)
     */
    public function __construct(
        public readonly string $rutaFisica,
        public readonly ?string $sha256,
        public readonly ?string $extensionOriginal,
        public readonly string $estado,
        public readonly bool $renderizable,
    ) {}
}
