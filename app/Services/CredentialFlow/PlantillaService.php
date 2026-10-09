<?php

namespace App\Services\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Plantillas\ImagenAPdf;
use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Alta y baja de plantillas de Credential Flow. Toda la interacción con el disco privado
 * pasa por aquí: las rutas se calculan a partir del id de la plantilla, nunca del navegador.
 *
 * El fondo puede llegar como PDF (se guarda tal cual) o como imagen PNG/JPG: la imagen se convierte UNA vez a PDF
 * antes de tocar la base de datos o el disco, y desde entonces la plantilla es indistinguible de una creada con PDF.
 * Nunca se conserva la imagen original: solo `base.pdf`.
 */
class PlantillaService
{
    /** @throws ImagenInvalidaException si la imagen no se puede usar (no se crea nada) */
    public function crear(string $nombre, ?string $descripcion, UploadedFile $archivo): Plantilla
    {
        $ruta = $archivo->getRealPath();
        $bytes = $ruta !== false ? file_get_contents($ruta) : false;
        if ($bytes === false) {
            throw new RuntimeException('No se pudo leer el archivo subido.');
        }

        $nombreOriginal = $this->nombreOriginalSeguro($archivo->getClientOriginalName());

        if ($this->esPdf($bytes)) {
            // PDF: flujo de siempre, sin cambios (se guarda el archivo subido).
            return $this->guardar($nombre, $descripcion, $nombreOriginal, hash('sha256', $bytes), function (string $carpeta) use ($archivo) {
                return Storage::disk(Plantilla::DISCO)->putFileAs($carpeta, $archivo, Plantilla::NOMBRE_PDF);
            });
        }

        // Imagen: se convierte aquí, antes de crear nada. Si falla, no hay registro ni archivos.
        $pdf = ImagenAPdf::convertir($bytes)['pdf'];
        unset($bytes);

        return $this->guardar($nombre, $descripcion, $nombreOriginal, hash('sha256', $pdf), function (string $carpeta) use ($pdf) {
            $destino = $carpeta.'/'.Plantilla::NOMBRE_PDF;

            return Storage::disk(Plantilla::DISCO)->put($destino, $pdf) ? $destino : false;
        });
    }

    /**
     * Crea una plantilla a partir de un PDF base YA generado (p. ej. el clon moderno de una imagen histórica, Fase 10B-2B-2A), con atributos
     * adicionales (diseño inicial, SHA del contenido de origen). Misma garantía de siempre: sin registro no hay archivo, y viceversa.
     *
     * @param  array<string,mixed>  $atributos
     */
    public function crearDesdePdf(string $nombre, ?string $descripcion, string $nombreOriginal, string $pdf, array $atributos = []): Plantilla
    {
        return $this->guardar($nombre, $descripcion, $this->nombreOriginalSeguro($nombreOriginal), hash('sha256', $pdf), function (string $carpeta) use ($pdf) {
            $destino = $carpeta.'/'.Plantilla::NOMBRE_PDF;

            return Storage::disk(Plantilla::DISCO)->put($destino, $pdf) ? $destino : false;
        }, $atributos);
    }

    /**
     * Crea el registro y escribe el PDF base dentro de una transacción. Si algo falla no queda ni registro ni carpeta.
     *
     * @param  callable(string):(string|false)  $escribir  recibe la carpeta de la plantilla y devuelve la ruta escrita
     */
    private function guardar(string $nombre, ?string $descripcion, string $nombreOriginal, string $hash, callable $escribir, array $atributos = []): Plantilla
    {
        $plantilla = null;

        try {
            return DB::transaction(function () use ($nombre, $descripcion, $nombreOriginal, $hash, $escribir, $atributos, &$plantilla) {
                // La ruta depende del id, así que el registro se crea primero dentro de la transacción.
                $plantilla = Plantilla::create([
                    'nombre' => $nombre,
                    'descripcion' => $descripcion !== null && $descripcion !== '' ? $descripcion : null,
                    'archivo_pdf' => '',
                    'nombre_archivo_original' => $nombreOriginal,
                    'hash_sha256' => $hash,
                ] + $atributos);

                $guardado = $escribir($plantilla->carpeta());

                if ($guardado === false || $guardado !== $plantilla->rutaPdfEsperada()) {
                    throw new RuntimeException('No se pudo guardar el PDF en el almacenamiento privado.');
                }

                $plantilla->update(['archivo_pdf' => $guardado]);

                return $plantilla;
            });
        } catch (Throwable $e) {
            // La transacción ya revirtió el registro: no debe quedar un PDF huérfano.
            if ($plantilla?->getKey()) {
                Storage::disk(Plantilla::DISCO)->deleteDirectory($plantilla->carpeta());
            }

            throw $e;
        }
    }

    private function esPdf(string $bytes): bool
    {
        return preg_match('/%PDF-[12]\.\d/', substr($bytes, 0, 1024)) === 1;
    }

    /**
     * Elimina (soft delete) la plantilla y su carpeta privada.
     *
     * @return bool true si el archivo se borró (o no existía); false si no se pudo borrar o no
     *              correspondía a la plantilla (en ese caso NO se toca el disco y se deja registro en el log).
     */
    public function eliminar(Plantilla $plantilla): bool
    {
        $carpeta = $plantilla->carpeta();

        // Solo se borra si el PDF registrado es exactamente el que le corresponde a ESTA plantilla.
        if ($plantilla->archivo_pdf !== $plantilla->rutaPdfEsperada()) {
            Log::error('Credential Flow: la ruta registrada no corresponde a la plantilla; no se borró ningún archivo.', [
                'plantilla_id' => $plantilla->getKey(),
            ]);
            $plantilla->delete();

            return false;
        }

        $plantilla->delete();

        $disco = Storage::disk(Plantilla::DISCO);
        if (! $disco->exists($carpeta)) {
            return true;
        }

        if (! $disco->deleteDirectory($carpeta)) {
            Log::error('Credential Flow: no se pudo borrar la carpeta de la plantilla.', [
                'plantilla_id' => $plantilla->getKey(),
                'carpeta' => $carpeta,
            ]);

            return false;
        }

        return true;
    }

    /** Solo el nombre, sin rutas ni caracteres de control; es un dato informativo. */
    private function nombreOriginalSeguro(string $nombre): string
    {
        $limpio = basename(str_replace('\\', '/', $nombre));
        $limpio = preg_replace('/[\x00-\x1F\x7F]/u', '', $limpio) ?? '';
        $limpio = trim(mb_substr($limpio, 0, 255));

        return $limpio !== '' ? $limpio : 'documento';
    }
}
