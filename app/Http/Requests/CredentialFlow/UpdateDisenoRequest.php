<?php

namespace App\Http\Requests\CredentialFlow;

use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema as S;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el diseño completo de una plantilla. No se acepta JSON arbitrario: cada clave se
 * declara aquí y `validated()` descarta todo lo demás.
 */
class UpdateDisenoRequest extends FormRequest
{
    public function authorize(): bool
    {
        // El acceso se controla con el middleware de la ruta (auth + rol:super-admin,admin).
        return true;
    }

    public function rules(): array
    {
        return [
            'diseno' => ['required', 'array:page,elements'],

            'diseno.page' => ['required', 'array:width,height'],
            'diseno.page.width' => ['required', 'numeric', 'between:'.S::PAGINA_MIN.','.S::PAGINA_MAX],
            'diseno.page.height' => ['required', 'numeric', 'between:'.S::PAGINA_MIN.','.S::PAGINA_MAX],

            // `present` y no `required`: una plantilla sin elementos (array vacío) es válida.
            'diseno.elements' => ['present', 'array', 'max:'.S::MAX_ELEMENTOS],
            'diseno.elements.*' => ['array:id,type,field,text,x,y,width,height,fontFamily,fontSize,fontWeight,color,align'],
            'diseno.elements.*.id' => ['required', 'uuid', 'distinct:strict'],
            'diseno.elements.*.type' => ['required', Rule::in(S::TIPOS)],
            // null = texto fijo; si no, una clave EXACTA del catálogo (mayúsculas u otras variantes → 422).
            'diseno.elements.*.field' => ['nullable', 'string', Rule::in(CamposDinamicos::claves())],
            // Un texto vacío llega como null (ConvertEmptyStringsToNull); se normaliza al guardar.
            'diseno.elements.*.text' => ['nullable', 'string', 'max:'.S::TEXTO_MAX],
            'diseno.elements.*.x' => ['required', 'numeric', 'between:0,'.S::PAGINA_MAX],
            'diseno.elements.*.y' => ['required', 'numeric', 'between:0,'.S::PAGINA_MAX],
            'diseno.elements.*.width' => ['required', 'numeric', 'between:'.S::ELEMENTO_MIN.','.S::PAGINA_MAX],
            'diseno.elements.*.height' => ['required', 'numeric', 'between:'.S::ELEMENTO_MIN.','.S::PAGINA_MAX],
            'diseno.elements.*.fontFamily' => ['required', Rule::in(S::FUENTES)],
            'diseno.elements.*.fontSize' => ['required', 'numeric', 'between:'.S::FONT_SIZE_MIN.','.S::FONT_SIZE_MAX],
            'diseno.elements.*.fontWeight' => ['required', 'integer', Rule::in(S::PESOS)],
            'diseno.elements.*.color' => ['required', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'diseno.elements.*.align' => ['required', Rule::in(S::ALINEACIONES)],
        ];
    }

    public function messages(): array
    {
        return [
            'diseno.required' => 'El diseño es obligatorio.',
            'diseno.array' => 'El diseño no tiene un formato válido.',
            'diseno.page.*' => 'Las dimensiones de la página no son válidas.',
            'diseno.elements.max' => 'La plantilla no puede tener más de '.S::MAX_ELEMENTOS.' elementos.',
            'diseno.elements.*.id.distinct' => 'Hay elementos con el mismo identificador.',
            'diseno.elements.*.id.uuid' => 'Un elemento tiene un identificador no válido.',
            'diseno.elements.*.type.in' => 'Un elemento tiene un tipo no permitido.',
            'diseno.elements.*.field.in' => 'Un elemento usa un campo dinámico que no existe en el catálogo.',
            'diseno.elements.*.field.string' => 'Un elemento tiene un campo dinámico no válido.',
            'diseno.elements.*.text.max' => 'Un texto no puede superar los '.S::TEXTO_MAX.' caracteres.',
            'diseno.elements.*.fontFamily.in' => 'Un elemento usa una fuente no permitida.',
            'diseno.elements.*.fontWeight.in' => 'Un elemento usa un grosor de fuente no permitido.',
            'diseno.elements.*.color.regex' => 'Un color no es válido (usa el formato #RRGGBB).',
            'diseno.elements.*.align.in' => 'Un elemento tiene una alineación no permitida.',
            'diseno.elements.*' => 'Un elemento tiene campos que no forman parte del diseño.',
            'diseno.elements.*.*' => 'Un elemento tiene valores fuera de los límites permitidos.',
        ];
    }

    /** Cada elemento debe quedar dentro de la página (en puntos PDF). */
    public function after(): array
    {
        return [function (Validator $validator) {
            if ($validator->errors()->isNotEmpty()) {
                return;
            }

            $pagina = $this->input('diseno.page');
            $ancho = (float) $pagina['width'] + S::TOLERANCIA;
            $alto = (float) $pagina['height'] + S::TOLERANCIA;

            $vistos = [];
            foreach ((array) $this->input('diseno.elements', []) as $i => $e) {
                $id = strtolower((string) $e['id']);
                if (isset($vistos[$id])) {
                    $validator->errors()->add("diseno.elements.$i.id", 'Hay elementos con el mismo identificador.');
                }
                $vistos[$id] = true;

                if ((float) $e['x'] + (float) $e['width'] > $ancho || (float) $e['y'] + (float) $e['height'] > $alto) {
                    $validator->errors()->add("diseno.elements.$i", 'Un elemento queda fuera de los límites de la página.');
                }
            }
        }];
    }

    /**
     * Diseño listo para guardar: solo claves conocidas, números redondeados a 2 decimales y
     * texto nulo convertido en cadena vacía. Si el elemento es un campo dinámico, `text` no tiene
     * semántica y se guarda siempre como ''; si es texto fijo, se conserva.
     */
    public function diseno(): array
    {
        $diseno = $this->validated('diseno');

        return [
            'page' => [
                'width' => round((float) $diseno['page']['width'], 2),
                'height' => round((float) $diseno['page']['height'], 2),
            ],
            'elements' => array_values(array_map(fn (array $e) => [
                'id' => strtolower($e['id']),
                'type' => $e['type'],
                'field' => $e['field'] ?? null,
                'text' => ($e['field'] ?? null) === null ? ($e['text'] ?? '') : '',
                'x' => round((float) $e['x'], 2),
                'y' => round((float) $e['y'], 2),
                'width' => round((float) $e['width'], 2),
                'height' => round((float) $e['height'], 2),
                'fontFamily' => $e['fontFamily'],
                'fontSize' => round((float) $e['fontSize'], 2),
                'fontWeight' => (int) $e['fontWeight'],
                'color' => strtolower($e['color']),
                'align' => $e['align'],
            ], $diseno['elements'])),
        ];
    }
}
