<?php

namespace App\Http\Requests\CredentialFlow;

use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use Illuminate\Foundation\Http\FormRequest;

/** Motivo obligatorio y confirmación explícita de una acción de resolución de plantilla (10B-1). Con `archivo` para aportar una plantilla. */
class AccionPlantillaRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso lo controla el middleware de la ruta (auth + rol)
    }

    public function rules(): array
    {
        $reglas = [
            'motivo' => ['required', 'string', 'min:'.ResolucionPlantillas::MOTIVO_MIN, 'max:'.ResolucionPlantillas::MOTIVO_MAX],
            'confirmo' => ['accepted'],
        ];
        if ($this->routeIs('credential-flow.historico.casos.aportar-plantilla')) {
            // El tipo real y las dimensiones los valida el servicio (por contenido, no por extensión). Aquí solo que llegue un archivo.
            $reglas['archivo'] = ['required', 'file'];
        }

        // Variación de nombre (10B-2B-1): el administrador elige la variante canónica.
        if ($this->routeIs('credential-flow.historico.casos.consolidar-nombre')) {
            $reglas['canonico_id'] = ['required', 'integer', 'min:1'];
        }

        return $reglas;
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo es obligatorio.',
            'motivo.min' => 'El motivo debe tener al menos '.ResolucionPlantillas::MOTIVO_MIN.' caracteres.',
            'motivo.max' => 'El motivo no puede superar los '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.',
            'confirmo.accepted' => 'Debes confirmar que entiendes lo que va a pasar.',
            'archivo.required' => 'Elige el archivo de la plantilla.',
            'archivo.file' => 'El archivo no se pudo cargar. Inténtalo de nuevo.',
            'canonico_id.required' => 'Elige cuál variante queda como canónica.',
            'canonico_id.integer' => 'Elige cuál variante queda como canónica.',
        ];
    }

    public function motivo(): string
    {
        return trim((string) $this->input('motivo'));
    }
}
