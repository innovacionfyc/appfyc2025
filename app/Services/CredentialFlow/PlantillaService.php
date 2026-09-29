<?php

namespace App\Services\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Throwable;

/**
 * Alta y baja de plantillas de Credential Flow. Toda la interacción con el disco privado
 * pasa por aquí: las rutas se calculan a partir del id de la plantilla, nunca del navegador.
 */
class PlantillaService
{
    public function crear(string $nombre, ?string $descripcion, UploadedFile $pdf): Plantilla
    {
        $ruta = $pdf->getRealPath();
        $hash = $ruta !== false ? hash_file('sha256', $ruta) : false;
        if ($hash === false) {
            throw new RuntimeException('No se pudo leer el PDF subido.');
        }

        $plantilla = null;

        try {
            return DB::transaction(function () use ($nombre, $descripcion, $pdf, $hash, &$plantilla) {
                // La ruta depende del id, así que el registro se crea primero dentro de la transacción.
                $plantilla = Plantilla::create([
                    'nombre' => $nombre,
                    'descripcion' => $descripcion !== null && $descripcion !== '' ? $descripcion : null,
                    'archivo_pdf' => '',
                    'nombre_archivo_original' => $this->nombreOriginalSeguro($pdf->getClientOriginalName()),
                    'hash_sha256' => $hash,
                ]);

                $guardado = Storage::disk(Plantilla::DISCO)
                    ->putFileAs($plantilla->carpeta(), $pdf, Plantilla::NOMBRE_PDF);

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

        return $limpio !== '' ? $limpio : 'documento.pdf';
    }
}
