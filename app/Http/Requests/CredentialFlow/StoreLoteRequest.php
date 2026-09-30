<?php

namespace App\Http\Requests\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Datos del formulario «Nuevo lote» (sirve igual para validar el archivo y para confirmar la importación).
 * El archivo solo se comprueba aquí como subida; su contenido lo valida el importador.
 */
class StoreLoteRequest extends FormRequest
{
    private ?Plantilla $plantillaResuelta = null;

    /** @var array<string,string> */
    private array $comunes = [];

    public function authorize(): bool
    {
        return true; // el acceso lo controla el middleware de la ruta (auth + rol)
    }

    public function rules(): array
    {
        return [
            'plantilla_id' => ['required', 'integer'],
            'nombre' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'evento' => ['required', 'string'],
            'fecha' => ['required', 'string'],
            'intensidad_horaria' => ['required', 'string'],
            'archivo' => ['required', 'file'],
        ];
    }

    public function messages(): array
    {
        return [
            'plantilla_id.required' => 'Elige una plantilla.',
            'nombre.required' => 'El nombre del lote es obligatorio.',
            'nombre.max' => 'El nombre del lote no puede superar los 200 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
            'evento.required' => 'El evento es obligatorio.',
            'fecha.required' => 'La fecha es obligatoria.',
            'intensidad_horaria.required' => 'La intensidad horaria es obligatoria.',
            'archivo.required' => 'Selecciona un archivo .xlsx o .csv.',
            'archivo.file' => 'El archivo no se pudo subir. Inténtalo de nuevo.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $plantilla = Plantilla::find($this->integer('plantilla_id'));
            if (! $plantilla) {
                $validator->errors()->add('plantilla_id', 'La plantilla elegida no existe.');
            } elseif (empty($plantilla->diseno['elements'] ?? null)) {
                $validator->errors()->add('plantilla_id', 'La plantilla no tiene un diseño guardado. Diséñala en el editor antes de crear un lote.');
            } else {
                $this->plantillaResuelta = $plantilla;
            }

            if (Texto::limpiar((string) $this->input('nombre')) === '') {
                $validator->errors()->add('nombre', 'El nombre del lote es obligatorio.');
            }

            $comunes = ValidadorDatosComunes::validar($this->only(ValidadorDatosComunes::CAMPOS));
            foreach ($comunes['errores'] as $campo => $mensaje) {
                $validator->errors()->add($campo, $mensaje);
            }
            $this->comunes = $comunes['datos'];
        }];
    }

    public function plantilla(): Plantilla
    {
        return $this->plantillaResuelta;
    }

    public function nombreLote(): string
    {
        return Texto::limpiar((string) $this->input('nombre'));
    }

    public function descripcionLote(): ?string
    {
        $d = trim((string) $this->input('descripcion'));

        return $d === '' ? null : $d;
    }

    /** @return array<string,string> */
    public function datosComunes(): array
    {
        return $this->comunes;
    }
}
