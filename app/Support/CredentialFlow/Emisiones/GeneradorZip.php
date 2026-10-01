<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * ZIP temporal, bajo demanda, con las emisiones VIGENTES de un lote. No emite nada y no persiste el ZIP.
 *
 * Aquí somos escritores del ZIP: nombres saneados y planos (sin rutas), solo archivos cuya ruta real está dentro
 * del almacén de emisiones y no son enlaces simbólicos, cada PDF verificado (existencia, tamaño y SHA-256),
 * límite de tamaño y borrado del temporal siempre. Los PDFs ya están comprimidos: se guardan con CM_STORE.
 */
final class GeneradorZip
{
    public const LIMITE_BYTES = 200 * 1024 * 1024;

    public const NOMBRE_MAX = 60;

    /** @throws EmisionException */
    public function paraLote(Lote $lote): BinaryFileResponse
    {
        $emisiones = Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->orderBy('id')->get();
        if ($emisiones->isEmpty()) {
            throw new EmisionException(EmisionException::SIN_EMISIONES_VIGENTES, 'Esta base no tiene certificados vigentes que descargar.', 409);
        }

        $total = (int) $emisiones->sum('pdf_bytes');
        if ($total > self::LIMITE_BYTES) {
            throw new EmisionException(EmisionException::ZIP_MUY_GRANDE, 'El ZIP superaría los '.(self::LIMITE_BYTES / 1024 / 1024).' MB permitidos. Descarga los certificados de uno en uno.', 409);
        }

        $temporal = sys_get_temp_dir();
        EspacioDisco::exigir($total, $temporal);

        $ruta = tempnam($temporal, 'cfz_');
        if ($ruta === false) {
            throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo preparar el archivo ZIP.', 500);
        }
        register_shutdown_function(static fn () => @unlink($ruta)); // respaldo si la conexión se corta antes del borrado

        try {
            $zip = new ZipArchive;
            if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo preparar el archivo ZIP.', 500);
            }

            $usados = [];
            foreach ($emisiones as $i => $emision) {
                $fisica = AlmacenEmisiones::rutaFisicaVerificada($emision); // existencia, tamaño, SHA-256, ruta segura
                $nombre = self::nombreEntrada($i + 1, (string) ($emision->datos_snapshot['nombre_completo'] ?? ''));
                while (isset($usados[$nombre])) {
                    $nombre = self::nombreEntrada($i + 1, 'X'.Str::random(4)); // no ocurre: el consecutivo es único
                }
                $usados[$nombre] = true;

                $zip->addFile($fisica, $nombre);
                $zip->setCompressionName($nombre, ZipArchive::CM_STORE);
            }

            if (! $zip->close()) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo preparar el archivo ZIP.', 500);
            }
        } catch (Throwable $e) {
            @unlink($ruta);

            throw $e;
        }

        return response()->download($ruta, 'credenciales-lote-'.$lote->id.'.zip', [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    /** `NNN-NOMBRE-SANEADO.pdf`: consecutivo + nombre ASCII en mayúsculas con guiones (máx. 60), sin rutas. */
    public static function nombreEntrada(int $consecutivo, string $nombre): string
    {
        $ascii = strtoupper(Str::ascii($nombre));
        $limpio = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $ascii), '-');
        $limpio = trim(substr($limpio, 0, self::NOMBRE_MAX), '-');

        return sprintf('%03d-%s.pdf', $consecutivo, $limpio === '' ? 'PARTICIPANTE' : $limpio);
    }
}
