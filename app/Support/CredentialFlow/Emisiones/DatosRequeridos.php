<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;

/**
 * Datos OPCIONALES de la base (modalidad, ciudad, textos configurables) que el diseño de la plantilla imprime. Si faltan, la emisión se bloquea ANTES de generar
 * nada y con un mensaje que dice qué completar y dónde. Nunca se imprime un valor vacío ni se inventa uno.
 *
 * Solo mira los campos agregados después de la primera versión: el comportamiento de los cinco campos originales (evento, fecha, intensidad…) no cambia.
 */
final class DatosRequeridos
{
    /**
     * Etiquetas de los datos opcionales que el diseño imprime y la base no tiene.
     *
     * @param  array<string,mixed>  $diseno
     * @param  array<string,mixed>  $comunes  `cf_lotes.datos_comunes`
     * @return list<string>
     */
    public static function faltantes(array $diseno, array $comunes): array
    {
        $catalogo = CamposDinamicos::todos();
        $faltan = [];
        foreach (DisenoSchema::camposUsados($diseno) as $campo) {
            if (in_array($campo, CamposDinamicos::COMUNES_OPCIONALES, true) && trim((string) ($comunes[$campo] ?? '')) === '') {
                $faltan[] = $catalogo[$campo]['etiqueta'];
            }
        }

        return $faltan;
    }

    /** @throws EmisionException */
    public static function exigir(Lote $lote, Plantilla $plantilla): void
    {
        $diseno = $plantilla->diseno;
        if (! is_array($diseno)) {
            return; // el resto de controles ya informan una plantilla sin diseño
        }
        $faltan = self::faltantes($diseno, $lote->datos_comunes ?? []);
        if ($faltan === []) {
            return;
        }

        $lista = count($faltan) === 1 ? '«'.$faltan[0].'»' : implode(', ', array_map(fn (string $e) => "«{$e}»", $faltan));
        throw new EmisionException(
            EmisionException::DATOS_DEL_DISENO_INCOMPLETOS,
            "La plantilla imprime {$lista} y esta base no tiene ".(count($faltan) === 1 ? 'ese dato' : 'esos datos').'. Complétalo en «Editar base» antes de emitir. No se emitió ningún certificado.',
            422,
            array_map(fn (string $e) => ['campo' => $e], $faltan)
        );
    }
}
