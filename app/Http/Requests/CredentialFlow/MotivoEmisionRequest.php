<?php

namespace App\Http\Requests\CredentialFlow;

use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use Illuminate\Foundation\Http\FormRequest;

/** Motivo obligatorio (5–500 caracteres) de una revocación o una reemisión. */
class MotivoEmisionRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // el acceso lo controla el middleware de la ruta (auth + rol)
    }

    public function rules(): array
    {
        return [
            'motivo' => ['required', 'string', 'min:'.EmisorCredencial::MOTIVO_MIN, 'max:'.EmisorCredencial::MOTIVO_MAX],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo es obligatorio.',
            'motivo.min' => 'El motivo debe tener al menos '.EmisorCredencial::MOTIVO_MIN.' caracteres.',
            'motivo.max' => 'El motivo no puede superar los '.EmisorCredencial::MOTIVO_MAX.' caracteres.',
        ];
    }

    public function motivo(): string
    {
        return trim((string) $this->input('motivo'));
    }
}
