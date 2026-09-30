<?php

namespace App\Support\CredentialFlow\Generacion;

/**
 * Plan de dibujo de un elemento de texto, ya validado y resuelto. Todo en puntos PDF desde la
 * esquina superior izquierda. El generador solo pinta esto: no decide contenido ni reglas.
 */
final readonly class TextoPlanificado
{
    /**
     * @param  array<int,array{texto:string, ancho:float, xInicio:float, baseline:float}>  $lineas
     *                                                                                              texto en NFC; xInicio = donde empieza a escribirse la línea; baseline = línea base absoluta.
     */
    public function __construct(
        public string $id,
        public ?string $campo,
        public string $fontFamily,
        public int $fontWeight,
        public float $fontSizeConfigurado,
        public float $fontSizeEfectivo,
        public bool $reducido,
        public float $x,
        public float $y,
        public float $width,
        public float $height,
        public string $align,
        public string $color,
        public array $lineas,
    ) {}
}
