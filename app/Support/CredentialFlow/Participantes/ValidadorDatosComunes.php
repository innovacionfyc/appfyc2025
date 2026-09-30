<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Support\CredentialFlow\CamposDinamicos;

/**
 * Datos comunes de un lote: evento, fecha e intensidad_horaria (snapshot). Se validan con los límites
 * del catálogo y se guardan ya normalizados; los campos con formato «mayusculas» se guardan en mayúsculas.
 */
final class ValidadorDatosComunes
{
    public const CAMPOS = ['evento', 'fecha', 'intensidad_horaria'];

    /**
     * @param  array<string,mixed>  $entrada
     * @return array{datos: array<string,string>, errores: array<string,string>} errores por campo
     */
    public static function validar(array $entrada): array
    {
        $catalogo = CamposDinamicos::todos();
        $datos = [];
        $errores = [];

        foreach (self::CAMPOS as $campo) {
            $etiqueta = $catalogo[$campo]['etiqueta'];
            $bruto = $entrada[$campo] ?? null;

            if (! is_string($bruto) || ! Texto::utf8Valido($bruto)) {
                $errores[$campo] = "{$etiqueta} es obligatorio.";

                continue;
            }
            $texto = Texto::limpiar($bruto);
            if ($texto === '') {
                $errores[$campo] = "{$etiqueta} es obligatorio.";

                continue;
            }
            if (Texto::tieneControl($texto)) {
                $errores[$campo] = "{$etiqueta} tiene caracteres de control no permitidos.";

                continue;
            }
            $max = $catalogo[$campo]['maxLongitud'];
            if (mb_strlen($texto) > $max) {
                $errores[$campo] = "{$etiqueta} no puede superar los {$max} caracteres.";

                continue;
            }
            if ($catalogo[$campo]['formato'] === CamposDinamicos::FORMATO_MAYUSCULAS) {
                $texto = Texto::mayusculas($texto);
            }
            if (($faltantes = ValidadorParticipante::noSoportados($texto)) !== []) {
                $errores[$campo] = "{$etiqueta} tiene caracteres que la fuente Outfit no puede imprimir: ".implode(' ', array_map(fn ($c) => "«{$c}»", array_slice($faltantes, 0, 5))).'.';

                continue;
            }
            $datos[$campo] = $texto;
        }

        return ['datos' => $datos, 'errores' => $errores];
    }
}
