<?php

namespace App\Http\Requests\CredentialFlow;

use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\ResultadoFila;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

/**
 * Alta y edición manual de un participante. Usa EXACTAMENTE el mismo validador que la importación
 * (ValidadorParticipante) y la misma regla de duplicados (documento_clave dentro del lote, sin contar
 * los eliminados).
 */
class ParticipanteRequest extends FormRequest
{
    private ?ResultadoFila $resultado = null;

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_completo' => ['required', 'string'],
            'documento' => ['required', 'string'],
        ];
    }

    public function messages(): array
    {
        return [
            'nombre_completo.required' => 'El nombre es obligatorio.',
            'documento.required' => 'El documento es obligatorio.',
        ];
    }

    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $r = ValidadorParticipante::validar(
                CeldaCruda::texto((string) $this->input('nombre_completo')),
                CeldaCruda::texto((string) $this->input('documento')),
            );
            foreach ($r->errores as $error) {
                $validator->errors()->add($error->columna ?? 'documento', $error->mensaje);
            }
            $this->resultado = $r;

            if ($r->clave !== null && $validator->errors()->isEmpty()) {
                $propio = $this->route('participante')?->id;
                $duplicado = Participante::query()
                    ->where('lote_id', $this->route('lote')->id)
                    ->where('documento_clave', $r->clave)
                    ->when($propio, fn ($q) => $q->where('id', '!=', $propio))
                    ->exists();

                if ($duplicado) {
                    $validator->errors()->add('documento', 'Ya existe un participante con ese documento en este lote.');
                }
            }
        }];
    }

    /** @return array{nombre_completo:string, documento:string, documento_clave:string} */
    public function datos(): array
    {
        return [
            'nombre_completo' => $this->resultado->nombre,
            'documento' => $this->resultado->documento,
            'documento_clave' => $this->resultado->clave,
        ];
    }
}
