<?php

namespace App\Http\Requests\CredentialFlow;

use App\Support\CredentialFlow\Plantillas\ImagenAPdf;
use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Http\UploadedFile;

class StorePlantillaRequest extends FormRequest
{
    /** Tamaño máximo del PDF base, en KB (20 MB). */
    public const MAX_KB = 20480;

    /** Un PDF válido debe llevar la cabecera %PDF-x.y dentro de sus primeros 1024 bytes. */
    private const PATRON_CABECERA = '/%PDF-[12]\.\d/';

    public function authorize(): bool
    {
        // El acceso se controla con el middleware de la ruta (auth + rol:super-admin,admin).
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'nombre' => is_string($this->input('nombre')) ? trim($this->input('nombre')) : $this->input('nombre'),
            'descripcion' => is_string($this->input('descripcion')) ? trim($this->input('descripcion')) : $this->input('descripcion'),
        ]);
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            // El campo se sigue llamando `pdf` por compatibilidad: acepta el PDF de siempre o una imagen PNG/JPG, que se
            // convierte UNA vez a PDF al crear la plantilla. El tipo se decide por el CONTENIDO, no por la extensión.
            'pdf' => [
                'required',
                'file',
                'extensions:pdf,png,jpg,jpeg',
                'mimetypes:application/pdf,image/png,image/jpeg',
                'max:'.self::MAX_KB,
                function (string $atributo, mixed $valor, \Closure $fallo) {
                    if (! $valor instanceof UploadedFile || ! $valor->isValid()) {
                        $fallo('Este archivo no es un PDF, PNG o JPG válido.');

                        return;
                    }

                    if (strtolower($valor->getClientOriginalExtension()) === 'pdf') {
                        if (! self::tieneCabeceraPdf($valor)) {
                            $fallo('Este archivo no es un PDF, PNG o JPG válido.');
                        }

                        return;
                    }

                    // Imagen: tipo real, dimensiones y límites (la decodificación completa ocurre al convertirla).
                    try {
                        ImagenAPdf::analizar((string) file_get_contents($valor->getRealPath()));
                    } catch (ImagenInvalidaException $e) {
                        $fallo($e->getMessage());
                    }
                },
            ],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la plantilla es obligatorio.',
            'nombre.max' => 'El nombre no puede superar los 200 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
            'pdf.required' => 'Debes seleccionar un archivo: PDF, PNG o JPG.',
            'pdf.file' => 'El archivo no se pudo cargar correctamente.',
            'pdf.uploaded' => 'El archivo no se pudo subir. Comprueba que no supere el límite del servidor.',
            'pdf.extensions' => 'Este archivo no es un PDF, PNG o JPG válido.',
            'pdf.mimetypes' => 'Este archivo no es un PDF, PNG o JPG válido.',
            'pdf.max' => 'El archivo no puede superar los 20 MB.',
        ];
    }

    private static function tieneCabeceraPdf(UploadedFile $archivo): bool
    {
        $ruta = $archivo->getRealPath();
        if ($ruta === false || ! is_readable($ruta)) {
            return false;
        }

        $manejador = fopen($ruta, 'rb');
        if ($manejador === false) {
            return false;
        }

        $inicio = fread($manejador, 1024);
        fclose($manejador);

        return is_string($inicio) && preg_match(self::PATRON_CABECERA, $inicio) === 1;
    }
}
