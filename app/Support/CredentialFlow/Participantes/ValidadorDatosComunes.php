<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Support\CredentialFlow\CamposDinamicos;

/**
 * Datos comunes de un lote: evento, fecha e intensidad_horaria (obligatorios) y, de forma OPCIONAL, modalidad, ciudad y los textos configurables
 * (texto_certificacion, texto_complementario). Se validan con los límites del catálogo y se guardan ya normalizados; los campos con formato «mayusculas» se
 * guardan en mayúsculas.
 *
 * Un dato opcional ausente o vacío NO se guarda (la clave no existe): así las bases anteriores conservan exactamente los mismos datos y nunca hay valores
 * vacíos ni inventados. Los textos largos admiten varias líneas; el resto de campos se reduce a una sola.
 */
final class ValidadorDatosComunes
{
    public const CAMPOS = ['evento', 'fecha', 'intensidad_horaria'];

    public const OPCIONALES = CamposDinamicos::COMUNES_OPCIONALES;

    /** Máximo de líneas de un texto largo (un párrafo de certificación no necesita más). */
    public const LINEAS_MAX = 8;

    /** Todas las claves que el formulario puede enviar. @return list<string> */
    public static function todos(): array
    {
        return [...self::CAMPOS, ...self::OPCIONALES];
    }

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
            if (($mensaje = self::problema($campo, $texto, $catalogo)) !== null) {
                $errores[$campo] = $mensaje;

                continue;
            }
            $datos[$campo] = $catalogo[$campo]['formato'] === CamposDinamicos::FORMATO_MAYUSCULAS ? Texto::mayusculas($texto) : $texto;
        }

        foreach (self::OPCIONALES as $campo) {
            $etiqueta = $catalogo[$campo]['etiqueta'];
            $bruto = $entrada[$campo] ?? null;
            if ($bruto === null || (is_string($bruto) && trim($bruto) === '')) {
                continue; // no se configuró: no se guarda
            }
            if (! is_string($bruto) || ! Texto::utf8Valido($bruto)) {
                $errores[$campo] = "{$etiqueta} debe ser texto.";

                continue;
            }
            $texto = self::limpiarOpcional($campo, $bruto, $catalogo[$campo]['multilinea']);
            if ($texto === '') {
                continue;
            }
            if (($mensaje = self::problema($campo, $texto, $catalogo)) !== null) {
                $errores[$campo] = $mensaje;

                continue;
            }
            if ($catalogo[$campo]['multilinea'] && count(explode("\n", $texto)) > self::LINEAS_MAX) {
                $errores[$campo] = "{$etiqueta} no puede tener más de ".self::LINEAS_MAX.' líneas.';

                continue;
            }
            $datos[$campo] = $catalogo[$campo]['formato'] === CamposDinamicos::FORMATO_MAYUSCULAS ? Texto::mayusculas($texto) : $texto;
        }

        return ['datos' => $datos, 'errores' => $errores];
    }

    /** Textos largos: cada línea se limpia por separado y se descartan las vacías; el resto: una sola línea. */
    private static function limpiarOpcional(string $campo, string $bruto, bool $multilinea): string
    {
        if (! $multilinea) {
            return Texto::limpiar($bruto);
        }
        $lineas = array_map(fn (string $l) => Texto::limpiar($l), preg_split('/\r\n|\r|\n/', $bruto) ?: []);

        return implode("\n", array_values(array_filter($lineas, fn (string $l) => $l !== '')));
    }

    /**
     * Largo, caracteres de control y caracteres que la fuente Outfit no puede imprimir (línea por línea).
     *
     * @param  array<string,array<string,mixed>>  $catalogo
     */
    private static function problema(string $campo, string $texto, array $catalogo): ?string
    {
        $etiqueta = $catalogo[$campo]['etiqueta'];
        $lineas = explode("\n", $texto);

        foreach ($lineas as $linea) {
            if (Texto::tieneControl($linea)) {
                return "{$etiqueta} tiene caracteres de control no permitidos.";
            }
        }
        $max = $catalogo[$campo]['maxLongitud'];
        if (mb_strlen($texto) > $max) {
            return "{$etiqueta} no puede superar los {$max} caracteres.";
        }
        $faltantes = [];
        // La cobertura de la fuente se comprueba sobre el texto FINAL (en mayúsculas si el campo las exige), como siempre.
        $final = $catalogo[$campo]['formato'] === CamposDinamicos::FORMATO_MAYUSCULAS ? Texto::mayusculas($texto) : $texto;
        foreach (explode("\n", $final) as $linea) {
            $faltantes = [...$faltantes, ...ValidadorParticipante::noSoportados($linea)];
        }
        if ($faltantes !== []) {
            return "{$etiqueta} tiene caracteres que la fuente Outfit no puede imprimir: ".implode(' ', array_map(fn ($c) => "«{$c}»", array_slice(array_values(array_unique($faltantes)), 0, 5))).'.';
        }

        return null;
    }
}
