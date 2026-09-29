<?php

namespace App\Http\Requests\CredentialFlow;

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
            'pdf' => [
                'required',
                'file',
                'extensions:pdf',
                'mimetypes:application/pdf',
                'max:'.self::MAX_KB,
                function (string $atributo, mixed $valor, \Closure $fallo) {
                    if (! $valor instanceof UploadedFile || ! $valor->isValid() || ! self::tieneCabeceraPdf($valor)) {
                        $fallo('El archivo no es un PDF válido.');
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
            'pdf.required' => 'Debes seleccionar el PDF base.',
            'pdf.file' => 'El PDF base no se pudo cargar correctamente.',
            'pdf.uploaded' => 'El PDF no se pudo subir. Comprueba que no supere el límite del servidor.',
            'pdf.extensions' => 'El archivo debe tener extensión .pdf.',
            'pdf.mimetypes' => 'El archivo debe ser un PDF.',
            'pdf.max' => 'El PDF no puede superar los 20 MB.',
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
