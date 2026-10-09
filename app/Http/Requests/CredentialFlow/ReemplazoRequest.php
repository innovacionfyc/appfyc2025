<?php

namespace App\Http\Requests\CredentialFlow;

use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use App\Support\CredentialFlow\Reemplazo\ReglasValorAprobado;
use App\Support\CredentialFlow\Reemplazo\SolicitudReemplazo;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Entrada de la vista previa y de la emisión de un reemplazo (Fase 10B-2B-2B). Validación estricta; el certificado NO viaja: sale del caso.
 * La emisión exige además el motivo, las dos confirmaciones y la huella de la vista previa.
 */
class ReemplazoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso se controla con el middleware de la ruta (auth + rol:super-admin,admin).
        return true;
    }

    public function rules(): array
    {
        $reglas = [
            'plantilla_id' => ['required', 'integer', 'min:1'],
            'regla_documento' => ['required', 'string', Rule::in([ReglasValorAprobado::DOC_SIN_NBSP, ReglasValorAprobado::DOC_CON_SEPARADORES, ReglasValorAprobado::DOC_SIN_SIGNO, ReglasValorAprobado::DOC_MANUAL, ReglasValorAprobado::DOC_SIN_CAMBIO])],
            'regla_nombre' => ['nullable', 'string', Rule::in([ReglasValorAprobado::NOMBRE_HISTORICO, ReglasValorAprobado::NOMBRE_CONFIRMADO])],
            'valor_nombre' => ['nullable', 'string', 'max:255'],
            'valor_documento' => ['nullable', 'string', 'max:40'],
            'confirmado_valor' => ['nullable', 'boolean'],
            'tengo_evidencia' => ['nullable', 'boolean'],
            'evidencia' => ['nullable', 'string', 'max:'.ReglasValorAprobado::EVIDENCIA_NOMBRE_MAX],
            'fecha' => ['nullable', 'string', 'max:80'],
            'intensidad_horaria' => ['nullable', 'string', 'max:255'],
        ];
        if ($this->routeIs('credential-flow.historico.casos.reemplazo.emitir')) {
            $reglas += [
                'motivo' => ['required', 'string', 'min:'.ResolucionPlantillas::MOTIVO_MIN, 'max:'.ResolucionPlantillas::MOTIVO_MAX],
                'confirmo_revision' => ['accepted'],
                'confirmo_final' => ['accepted'],
                'huella' => ['required', 'string', 'size:64'],
            ];
        }

        return $reglas;
    }

    public function messages(): array
    {
        return [
            'plantilla_id.required' => 'Elige la plantilla moderna.',
            'regla_documento.*' => 'Elige cómo se corregirá el documento.',
            'valor_documento.max' => 'El documento no puede superar los 40 caracteres.',
            'evidencia.max' => 'La evidencia no puede superar los '.ReglasValorAprobado::EVIDENCIA_NOMBRE_MAX.' caracteres.',
            'motivo.required' => 'Explica el motivo del reemplazo.',
            'motivo.min' => 'El motivo debe tener al menos '.ResolucionPlantillas::MOTIVO_MIN.' caracteres.',
            'motivo.max' => 'El motivo no puede superar los '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.',
            'confirmo_revision.accepted' => 'Confirma que revisaste el dato corregido y la vista previa.',
            'confirmo_final.accepted' => 'Confirma la emisión en el resumen final.',
            'huella.*' => 'Genera la vista previa antes de emitir.',
        ];
    }

    /** La solicitud para el servicio: lo aprobado por el administrador (los valores en claro no salen de aquí). */
    public function solicitud(): SolicitudReemplazo
    {
        $manual = $this->input('regla_documento') === ReglasValorAprobado::DOC_MANUAL;
        // El nombre aprobado solo viaja en DIF_NOMBRE (documento sin cambio): los casos documentales siguen imprimiendo el nombre histórico.
        $nombreConfirmado = $this->input('regla_documento') === ReglasValorAprobado::DOC_SIN_CAMBIO && $this->input('regla_nombre') === ReglasValorAprobado::NOMBRE_CONFIRMADO;

        return new SolicitudReemplazo(
            plantillaId: (int) $this->input('plantilla_id'),
            reglaDocumento: (string) $this->input('regla_documento'),
            valorDocumento: $this->input('valor_documento') === null ? null : (string) $this->input('valor_documento'),
            reglaNombre: $nombreConfirmado ? ReglasValorAprobado::NOMBRE_CONFIRMADO : ReglasValorAprobado::NOMBRE_HISTORICO,
            valorNombre: $nombreConfirmado && $this->input('valor_nombre') !== null ? (string) $this->input('valor_nombre') : null,
            confirmado: $this->boolean('confirmado_valor'),
            // La evidencia solo cuenta si el administrador declaró que la tiene (regla manual).
            evidencia: ($manual || $nombreConfirmado) && $this->boolean('tengo_evidencia') ? ($this->input('evidencia') === null ? null : (string) $this->input('evidencia')) : null,
            fecha: $this->input('fecha') === null ? null : (string) $this->input('fecha'),
            intensidadHoraria: $this->input('intensidad_horaria') === null ? null : (string) $this->input('intensidad_horaria'),
        );
    }
}
