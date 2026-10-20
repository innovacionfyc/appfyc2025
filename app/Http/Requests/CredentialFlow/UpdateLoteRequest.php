<?php

namespace App\Http\Requests\CredentialFlow;

use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorDatosComunes;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/** Edición de un lote: nombre, descripción y datos comunes. La plantilla NO se puede cambiar en V1. */
class UpdateLoteRequest extends FormRequest
{
    /** @var array<string,string> */
    private array $comunes = [];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre' => ['required', 'string', 'max:200'],
            'descripcion' => ['nullable', 'string', 'max:1000'],
            'evento' => ['required', 'string'],
            'fecha' => ['required', 'string'],
            'intensidad_horaria' => ['required', 'string'],
            // Opcionales: solo se guardan si vienen con contenido (ver ValidadorDatosComunes).
            'modalidad' => ['nullable', 'string', 'max:2000'],
            'ciudad' => ['nullable', 'string', 'max:2000'],
            'texto_certificacion' => ['nullable', 'string', 'max:5000'],
            'texto_complementario' => ['nullable', 'string', 'max:5000'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre.required' => 'El nombre de la base es obligatorio.',
            'nombre.max' => 'El nombre de la base no puede superar los 200 caracteres.',
            'descripcion.max' => 'La descripción no puede superar los 1000 caracteres.',
            'evento.required' => 'El evento es obligatorio.',
            'fecha.required' => 'La fecha es obligatoria.',
            'intensidad_horaria.required' => 'La intensidad horaria es obligatoria.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }
            if (Texto::limpiar((string) $this->input('nombre')) === '') {
                $validator->errors()->add('nombre', 'El nombre de la base es obligatorio.');
            }
            $comunes = ValidadorDatosComunes::validar($this->only(ValidadorDatosComunes::todos()));
            foreach ($comunes['errores'] as $campo => $mensaje) {
                $validator->errors()->add($campo, $mensaje);
            }
            $this->comunes = $comunes['datos'];

            // Una petición que ni siquiera menciona un dato opcional (cliente anterior) no debe borrarlo; vaciar el campo en el formulario sí lo quita.
            $lote = $this->route('lote');
            $actuales = $lote instanceof Lote ? ($lote->datos_comunes ?? []) : [];
            foreach (ValidadorDatosComunes::OPCIONALES as $campo) {
                if (! $this->exists($campo) && isset($actuales[$campo]) && ! isset($this->comunes[$campo])) {
                    $this->comunes[$campo] = $actuales[$campo];
                }
            }
        }];
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
