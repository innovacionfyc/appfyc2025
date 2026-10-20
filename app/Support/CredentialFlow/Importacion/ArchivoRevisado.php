<?php

namespace App\Support\CredentialFlow\Importacion;

use Illuminate\Http\UploadedFile;

/**
 * CSV temporal, ya REVISADO por el administrador, que se le entrega al importador de siempre (así se reutilizan todas sus validaciones sin duplicarlas).
 * Columnas del formato oficial: nombre_completo, tipo_documento, documento, correo, elegibilidad. Un tipo de documento vacío queda «por confirmar»; nunca se supone C.C.
 * Quien lo crea debe borrarlo (`unlink`) al terminar: contiene datos personales.
 */
final class ArchivoRevisado
{
    public const ENCABEZADO = ['nombre_completo', 'tipo_documento', 'documento', 'correo', 'elegibilidad'];

    private const ELEGIBILIDAD = ['elegible' => 'elegible', 'pendiente' => 'pendiente', 'no_elegible' => 'no elegible'];

    /**
     * @param  list<array<string,mixed>>  $participantes  los incluidos, en el orden en que se importarán (la línea del CSV es posición + 2)
     */
    public static function crear(array $participantes): UploadedFile
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cfimp_');
        $h = fopen($ruta, 'wb');
        fputcsv($h, self::ENCABEZADO, ',', '"', '');
        foreach ($participantes as $p) {
            fputcsv($h, [
                (string) ($p['nombre_completo'] ?? ''),
                (string) ($p['tipo_documento'] ?? ''),
                (string) ($p['documento'] ?? ''),
                (string) ($p['correo'] ?? ''),
                self::ELEGIBILIDAD[$p['elegibilidad'] ?? 'pendiente'] ?? 'pendiente',
            ], ',', '"', '');
        }
        fclose($h);

        return new UploadedFile($ruta, 'participantes-revisados.csv', 'text/csv', null, true);
    }
}
