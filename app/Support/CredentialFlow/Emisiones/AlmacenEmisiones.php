<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\LogSeguro;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Throwable;

/**
 * Almacén de los PDFs emitidos en el disco PRIVADO (nunca en public/):
 *   credential-flow/emisiones/{aa}/{uuid}.pdf      ← definitivo (aa = 2 primeros caracteres del uuid)
 *   credential-flow/emisiones/.tmp/{uuid}.tmp      ← escritura temporal (mismo filesystem)
 *   credential-flow/emisiones/.staging/{operacion} ← PDFs de una emisión masiva antes de confirmarla
 * La ruta física NUNCA contiene nombre, documento ni id secuencial.
 *
 * BACKUP: la base de datos y este directorio deben respaldarse de forma coherente. Tras restaurar, ejecuta
 * `php artisan credential-flow:verificar-emisiones`.
 */
final class AlmacenEmisiones
{
    public const RAIZ = 'credential-flow/emisiones';

    /** Solo para tests: si está definido se ejecuta antes de cada escritura (puede lanzar para simular un fallo). */
    public static ?\Closure $antesDeEscribir = null;

    public static function disco(): Filesystem
    {
        return Storage::disk(Plantilla::DISCO);
    }

    /** Ruta definitiva nueva. */
    public static function rutaNueva(): string
    {
        $uuid = (string) Str::uuid();

        return self::RAIZ.'/'.substr($uuid, 0, 2).'/'.$uuid.'.pdf';
    }

    public static function rutaStaging(string $operacion, int $indice): string
    {
        return self::RAIZ.'/.staging/'.$operacion.'/'.$indice.'.pdf';
    }

    public static function directorioStaging(string $operacion): string
    {
        return self::RAIZ.'/.staging/'.$operacion;
    }

    /** Escribe los bytes en un archivo temporal y lo mueve (rename atómico, mismo filesystem) a su ruta definitiva. */
    public static function escribir(string $bytes, string $rutaFinal): void
    {
        $tmp = self::RAIZ.'/.tmp/'.Str::uuid().'.tmp';

        try {
            if (self::$antesDeEscribir !== null) {
                (self::$antesDeEscribir)($rutaFinal);
            }
            if (self::disco()->put($tmp, $bytes) === false || self::disco()->size($tmp) !== strlen($bytes)) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar el PDF de la emisión.', 500);
            }
            self::disco()->move($tmp, $rutaFinal);
        } catch (EmisionException $e) {
            self::borrar($tmp);
            throw $e;
        } catch (Throwable $e) {
            self::borrar($tmp);
            self::borrar($rutaFinal);
            Log::error('Credential Flow: error al escribir el PDF de una emisión', ['error' => LogSeguro::resumen($e)]);

            throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar el PDF de la emisión.', 500);
        }
    }

    /** Guarda un PDF de una emisión masiva en su carpeta de staging. */
    public static function guardarStaging(string $ruta, string $bytes): void
    {
        try {
            if (self::$antesDeEscribir !== null) {
                (self::$antesDeEscribir)($ruta);
            }
            if (self::disco()->put($ruta, $bytes) === false) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar un PDF de la emisión masiva.', 500);
            }
        } catch (EmisionException $e) {
            throw $e;
        } catch (Throwable $e) {
            Log::error('Credential Flow: error al escribir en staging', ['error' => LogSeguro::resumen($e)]);

            throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar un PDF de la emisión masiva.', 500);
        }
    }

    /** Mueve un PDF ya escrito (staging) a su ruta definitiva. */
    public static function mover(string $desde, string $hasta): void
    {
        try {
            self::disco()->move($desde, $hasta);
        } catch (Throwable $e) {
            Log::error('Credential Flow: error al mover el PDF de una emisión', ['error' => LogSeguro::resumen($e)]);

            throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar el PDF de la emisión.', 500);
        }
    }

    public static function borrar(string $ruta): void
    {
        try {
            if (self::disco()->exists($ruta)) {
                self::disco()->delete($ruta);
            }
        } catch (Throwable) {
            // limpieza de mejor esfuerzo: la auditoría reporta cualquier residuo
        }
    }

    public static function borrarDirectorio(string $ruta): void
    {
        try {
            self::disco()->deleteDirectory($ruta);
        } catch (Throwable) {
        }
    }

    /**
     * Comprueba existencia, tamaño y SHA-256 del PDF de una emisión y devuelve su contenido.
     *
     * @throws EmisionException ARCHIVO_EMISION_NO_EXISTE | INTEGRIDAD_EMISION_INVALIDA
     */
    public static function leerVerificado(Emision $emision): string
    {
        $ruta = self::rutaSegura($emision);
        $disco = self::disco();

        if (! $disco->exists($ruta)) {
            Log::error('Credential Flow: falta el archivo de una emisión', ['emision' => $emision->id]);

            throw new EmisionException(EmisionException::ARCHIVO_EMISION_NO_EXISTE, 'El archivo de esta emisión no está disponible. Avisa al equipo técnico.', 409);
        }

        $bytes = $disco->get($ruta);
        if (strlen($bytes) !== (int) $emision->pdf_bytes || ! hash_equals((string) $emision->pdf_hash, hash('sha256', $bytes))) {
            Log::error('Credential Flow: la integridad de una emisión no coincide', ['emision' => $emision->id]);

            throw new EmisionException(EmisionException::INTEGRIDAD_EMISION_INVALIDA, 'El archivo de esta emisión no pasó la verificación de integridad y no se puede descargar. Avisa al equipo técnico.', 409);
        }

        return $bytes;
    }

    /**
     * Ruta física dentro del directorio de emisiones, sin enlaces simbólicos, para usar con hash_file/ZipArchive.
     * Verifica existencia, tamaño y SHA-256 sin cargar el archivo completo en memoria.
     *
     * @throws EmisionException
     */
    public static function rutaFisicaVerificada(Emision $emision): string
    {
        $relativa = self::rutaSegura($emision);
        $disco = self::disco();

        if (! $disco->exists($relativa)) {
            throw new EmisionException(EmisionException::ARCHIVO_EMISION_NO_EXISTE, 'El archivo de una emisión no está disponible. Avisa al equipo técnico.', 409);
        }

        $fisica = $disco->path($relativa);
        $raiz = realpath($disco->path(self::RAIZ));
        $real = realpath($fisica);
        if (is_link($fisica) || $raiz === false || $real === false || ! str_starts_with($real, $raiz.DIRECTORY_SEPARATOR)) {
            throw new EmisionException(EmisionException::INTEGRIDAD_EMISION_INVALIDA, 'Una emisión apunta a un archivo fuera del almacén y no se puede procesar.', 409);
        }
        if (filesize($real) !== (int) $emision->pdf_bytes || ! hash_equals((string) $emision->pdf_hash, (string) hash_file('sha256', $real))) {
            Log::error('Credential Flow: la integridad de una emisión no coincide', ['emision' => $emision->id]);

            throw new EmisionException(EmisionException::INTEGRIDAD_EMISION_INVALIDA, 'Una emisión no pasó la verificación de integridad. Avisa al equipo técnico.', 409);
        }

        return $real;
    }

    /** La ruta registrada debe ser exactamente una del almacén (nunca una ruta arbitraria). */
    private static function rutaSegura(Emision $emision): string
    {
        $ruta = (string) $emision->pdf_archivo;
        if (preg_match('#^'.preg_quote(self::RAIZ, '#').'/[0-9a-f]{2}/[0-9a-f-]{36}\.pdf$#', $ruta) !== 1) {
            throw new EmisionException(EmisionException::INTEGRIDAD_EMISION_INVALIDA, 'La ruta registrada de la emisión no es válida.', 409);
        }

        return $ruta;
    }
}
