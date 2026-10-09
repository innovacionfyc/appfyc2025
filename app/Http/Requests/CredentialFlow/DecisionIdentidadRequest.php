<?php

namespace App\Http\Requests\CredentialFlow;

use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/** Crear (o revocar) una decisión de identidad (10B-3A). Solo valida la FORMA; las reglas de negocio las aplica `DecisionesIdentidad` bajo lock. */
class DecisionIdentidadRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true; // auth + rol (super-admin / admin) en el grupo de rutas
    }

    public function rules(): array
    {
        $motivo = ['required', 'string', 'min:'.ResolucionPlantillas::MOTIVO_MIN, 'max:'.ResolucionPlantillas::MOTIVO_MAX];
        if ($this->routeIs('credential-flow.historico.casos.identidad.revocar', 'credential-flow.historico.casos.identidad.revocar-aprobacion')) {
            return ['motivo' => $motivo];
        }
        if ($this->routeIs('credential-flow.historico.casos.identidad.aprobar-masiva')) {
            return ['motivo' => $motivo, 'confirmo_masiva' => ['accepted']];
        }

        return [
            'tipo' => ['required', Rule::in(DecisionIdentidad::TIPOS)],
            'motivo' => $motivo,
            'grupos' => ['nullable', 'array', 'max:50'],
            'grupos.*' => ['string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'grupo' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'correo_hmac' => ['nullable', 'string', 'size:64', 'regex:/^[0-9a-f]{64}$/'],
            'evidencia' => ['nullable', 'string', 'max:'.DecisionesIdentidad::EVIDENCIA_MAX],
            'confirmo' => ['nullable', 'boolean'],
            'reforzada' => ['nullable', 'boolean'],
            'evidencia_externa' => ['nullable', 'boolean'],
            'alcance_masivo' => ['nullable', 'boolean'],
            'fuente_evidencia' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function messages(): array
    {
        return [
            'motivo.required' => 'El motivo es obligatorio.',
            'motivo.min' => 'El motivo debe tener al menos '.ResolucionPlantillas::MOTIVO_MIN.' caracteres.',
            'motivo.max' => 'El motivo no puede superar los '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.',
            'tipo.in' => 'Elige un tipo de decisión de la lista.',
            'confirmo_masiva.accepted' => 'Confirma de forma reforzada la aprobación de la autorización masiva.',
            'evidencia.max' => 'La evidencia no puede superar los '.DecisionesIdentidad::EVIDENCIA_MAX.' caracteres.',
        ];
    }

    /** @return array<string,mixed> */
    public function parametros(): array
    {
        return [
            'grupos' => array_values((array) $this->input('grupos', [])), 'grupo' => $this->input('grupo'), 'correo_hmac' => $this->input('correo_hmac'),
            'evidencia' => $this->input('evidencia'), 'confirmo' => $this->boolean('confirmo'), 'reforzada' => $this->boolean('reforzada'),
            'evidencia_externa' => $this->boolean('evidencia_externa'), 'alcance_masivo' => $this->boolean('alcance_masivo'),
            'fuente_evidencia' => $this->input('fuente_evidencia') === null ? null : (string) $this->input('fuente_evidencia'),
        ];
    }
}
