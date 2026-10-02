<?php

namespace App\Support\CredentialFlow\Eliminacion;

use App\Models\CredentialFlow\Plantilla;
use Illuminate\Support\Facades\Storage;

/**
 * Única puerta para decidir qué archivos puede tocar la eliminación definitiva. Nunca se acepta una ruta tal cual
 * viene de la base de datos o de la petición: se comprueba su forma exacta, que viva dentro de la zona de
 * Credential Flow, que no pase por enlaces simbólicos y que no contenga `..`.
 */
final class RutasSeguras
{
    public const RAIZ = 'credential-flow';

    public const EMISIONES = 'credential-flow/emisiones';

    public const PLANTILLAS = 'credential-flow/plantillas';

    public const PAPELERA = 'credential-flow/papelera';

    private const PDF_EMISION = '#^credential-flow/emisiones/([0-9a-f]{2})/\1[0-9a-f]{6}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}\.pdf$#';

    private const UUID = '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/';

    /** Ruta de un PDF de emisión tal como la genera AlmacenEmisiones. @throws EliminacionException */
    public static function emision(string $ruta): string
    {
        if (preg_match(self::PDF_EMISION, $ruta) !== 1) {
            throw EliminacionException::rutaInvalida();
        }

        return $ruta;
    }

    /** Carpeta de staging de una emisión masiva (`.staging/{uuid}`). @throws EliminacionException */
    public static function stagingOperacion(string $operacion): string
    {
        if (preg_match(self::UUID, $operacion) !== 1) {
            throw EliminacionException::rutaInvalida();
        }

        return self::EMISIONES.'/.staging/'.$operacion;
    }

    /** Carpeta privada de una plantilla, calculada solo desde su id numérico. */
    public static function carpetaPlantilla(int $id): string
    {
        return self::PLANTILLAS.'/'.$id;
    }

    /**
     * Ruta física REAL de un archivo ya comprobado, o null si no existe. Lanza si es un enlace simbólico, si
     * alguna carpeta intermedia lo es, o si por cualquier motivo la ruta real queda fuera de `$raiz`.
     *
     * @param  string  $relativa  ruta relativa al disco privado (con `/`)
     * @param  string  $raiz  carpeta relativa que debe contenerla (p. ej. credential-flow/emisiones)
     *
     * @throws EliminacionException
     */
    public static function fisica(string $relativa, string $raiz): ?string
    {
        $partes = explode('/', $relativa);
        if ($relativa === '' || str_contains($relativa, "\0") || str_contains($relativa, '\\')
            || in_array('..', $partes, true) || in_array('.', $partes, true) || in_array('', $partes, true)
            || ! str_starts_with($relativa, $raiz.'/')) {
            throw EliminacionException::rutaInvalida();
        }

        $disco = Storage::disk(Plantilla::DISCO);
        $fisica = $disco->path($relativa);
        if (! file_exists($fisica) && ! is_link($fisica)) {
            return null;
        }

        $real = realpath($fisica);
        $raizReal = realpath($disco->path($raiz));
        if (is_link($fisica) || $real === false || $raizReal === false || ! is_file($real)) {
            throw EliminacionException::rutaInvalida();
        }

        // La ruta real debe ser exactamente raíz real + el resto relativo: si una carpeta intermedia fuera un enlace,
        // la ruta real sería otra aunque siga empezando por la raíz.
        $esperada = $raizReal.DIRECTORY_SEPARATOR.str_replace('/', DIRECTORY_SEPARATOR, substr($relativa, strlen($raiz) + 1));
        if ($real !== $esperada) {
            throw EliminacionException::rutaInvalida();
        }

        return $real;
    }
}
