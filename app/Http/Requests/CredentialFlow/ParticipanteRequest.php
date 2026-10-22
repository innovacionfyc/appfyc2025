<?php

namespace App\Http\Requests\CredentialFlow;

use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Participantes\CeldaCruda;
use App\Support\CredentialFlow\Participantes\CorreosDeParticipante;
use App\Support\CredentialFlow\Participantes\ResultadoFila;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Schema;

/**
 * Alta y edición manual de un participante. Usa EXACTAMENTE el mismo validador que la importación
 * (ValidadorParticipante) y la misma regla de duplicados (documento_clave dentro del lote, sin contar
 * los eliminados).
 */
class ParticipanteRequest extends FormRequest
{
    private ?ResultadoFila $resultado = null;

    /** @var list<string>|null correos ya validados; null = el formulario no trajo el campo (no se toca el correo) */
    private ?array $correos = null;

    /** @var list<string> */
    private array $avisosCorreo = [];

    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'nombre_completo' => ['required', 'string'],
            'documento' => ['required', 'string'],
            'correo' => ['nullable', 'string', 'max:600'],
            'elegibilidad' => ['nullable', 'in:'.implode(',', Participante::ELEGIBILIDADES)],
            'intensidad_horaria' => ['nullable', 'string', 'max:60', 'not_regex:/^[=+\-@]/'],
            'modalidad' => ['nullable', 'string', 'max:120', 'not_regex:/^[=+\-@]/'],
            'elegibilidad_motivo' => ['nullable', 'string', 'max:255'],
            'tipo_documento_pendiente' => ['nullable', 'boolean'],
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
                    // Un contacto QA aparece una vez por modalidad de la base: sus otras filas QA no son un duplicado.
                    ->when($this->route('participante')?->es_control_qa, fn ($q) => $q->where('es_control_qa', false))
                    ->exists();

                if ($duplicado) {
                    $validator->errors()->add('documento', 'Ya existe un participante con ese documento en esta base.');
                }
            }

            if (in_array($this->input('elegibilidad'), [Participante::PENDIENTE, Participante::NO_ELEGIBLE], true) && trim((string) $this->input('elegibilidad_motivo')) === '') {
                $validator->errors()->add('elegibilidad_motivo', 'Indica por qué queda pendiente o no elegible (queda en el historial).');
            }

            // Correo (opcional): mismo validador que la importación + conflictos de identidad. Si el campo no viene, el correo no se toca.
            if ($this->has('correo') && $r->clave !== null && $validator->errors()->isEmpty()) {
                $e = app(CorreosDeParticipante::class)->evaluar($this->route('participante'), (int) $this->route('lote')->id, $r->clave, $this->input('correo'));
                foreach ($e['errores'] as $mensaje) {
                    $validator->errors()->add('correo', $mensaje);
                }
                $this->correos = $e['correos'];
                $this->avisosCorreo = $e['avisos'];
            }
        }];
    }

    /** @return list<string>|null correos validados (vacío = sin correo); null = no se envió el campo */
    public function correos(): ?array
    {
        return $this->correos;
    }

    /** @return list<string> */
    public function avisosCorreo(): array
    {
        return $this->avisosCorreo;
    }

    /** @return array{nombre_completo:string, documento:string, documento_clave:string} */
    public function datos(): array
    {
        $datos = [
            'nombre_completo' => $this->resultado->nombre,
            'documento' => $this->resultado->documento,
            'documento_clave' => $this->resultado->clave,
        ];
        // Solo si el formulario los envía: una petición sin estos campos no toca la elegibilidad ni la intensidad.
        if ($this->has('elegibilidad')) {
            $e = $this->input('elegibilidad') ?: Participante::ELEGIBLE;
            $datos['elegibilidad'] = $e;
            $datos['elegibilidad_motivo'] = $e === Participante::ELEGIBLE ? null : trim((string) $this->input('elegibilidad_motivo'));
        }
        if ($this->has('intensidad_horaria')) {
            $i = trim((string) $this->input('intensidad_horaria'));
            $datos['intensidad_horaria'] = $i === '' ? null : mb_substr($i, 0, 60);
        }
        // Modalidad propia (opcional): vacía = la general de la base. Se guarda literal (solo se limpian los espacios).
        if ($this->has('modalidad') && Schema::hasColumn('cf_participantes', 'modalidad')) {
            $m = Texto::limpiar((string) $this->input('modalidad'));
            $datos['modalidad'] = $m === '' ? null : mb_substr($m, 0, 120);
        }

        // El tipo de documento se confirma eligiéndolo; sin tipo la persona queda «por confirmar» y no se emite. Solo si el formulario lo envía.
        if ($this->has('tipo_documento_pendiente')) {
            $datos['tipo_documento_confirmado'] = ! $this->boolean('tipo_documento_pendiente');
        }

        return $datos;
    }
}
