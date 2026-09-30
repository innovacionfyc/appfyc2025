<?php

namespace App\Http\Requests\CredentialFlow;

use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema as S;
use App\Support\CredentialFlow\FuentesCredential;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

/**
 * Valida el diseño completo de una plantilla. No se acepta JSON arbitrario: cada clave se
 * declara aquí y `validated()` descarta todo lo demás.
 *
 * Separación de responsabilidades (Fase 4): aquí se valida la pareja familia+peso, pero NO la
 * cobertura de caracteres de la fuente. Esa regla (un texto Outfit con un carácter que la fuente no
 * tiene) la aplica el editor, que bloquea «Guardar diseño»; el generador de PDF volverá a
 * comprobarla con FuentesCredential::medirTexto() antes de dibujar. Duplicarla aquí obligaría a
 * resolver también el texto de los campos dinámicos en cada guardado.
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
            // Propiedades de TEXTO: obligatorias solo en elementos `text`. Un QR no lleva ninguna (se valida en after()).
            'diseno.elements.*.fontFamily' => ['required_if:diseno.elements.*.type,text', 'nullable', Rule::in(FuentesCredential::familias())],
            'diseno.elements.*.fontSize' => ['required_if:diseno.elements.*.type,text', 'nullable', 'numeric', 'between:'.S::FONT_SIZE_MIN.','.S::FONT_SIZE_MAX],
            'diseno.elements.*.fontWeight' => ['required_if:diseno.elements.*.type,text', 'nullable', 'integer', Rule::in(S::PESOS)],
            'diseno.elements.*.color' => ['required_if:diseno.elements.*.type,text', 'nullable', 'string', 'regex:/^#[0-9a-fA-F]{6}$/'],
            'diseno.elements.*.align' => ['required_if:diseno.elements.*.type,text', 'nullable', Rule::in(S::ALINEACIONES)],
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
            $qrs = 0;
            foreach ((array) $this->input('diseno.elements', []) as $i => $e) {
                $id = strtolower((string) $e['id']);
                if (isset($vistos[$id])) {
                    $validator->errors()->add("diseno.elements.$i.id", 'Hay elementos con el mismo identificador.');
                }
                $vistos[$id] = true;

                if ($e['type'] === S::TIPO_QR) {
                    $qrs++;
                    $this->validarQr($validator, $i, $e);
                } elseif (! FuentesCredential::combinacionValida((string) $e['fontFamily'], (int) $e['fontWeight'])) {
                    $validator->errors()->add("diseno.elements.$i.fontWeight", 'La fuente elegida no está disponible en ese grosor.');
                }

                if ((float) $e['x'] + (float) $e['width'] > $ancho || (float) $e['y'] + (float) $e['height'] > $alto) {
                    $validator->errors()->add("diseno.elements.$i", 'Un elemento queda fuera de los límites de la página.');
                }
            }

            if ($qrs > S::QR_MAX) {
                $validator->errors()->add('diseno.elements', 'Solo se permite un QR de verificación por plantilla.');
            }
        }];
    }

    /** Reglas del elemento QR (schema 2): sin propiedades de texto, cuadrado, tamaño permitido y uno solo. */
    private function validarQr(Validator $validator, int $i, array $e): void
    {
        $textoPresente = ($e['field'] ?? null) !== null || ($e['text'] ?? null) !== null;
        foreach (['fontFamily', 'fontSize', 'fontWeight', 'color', 'align'] as $clave) {
            $textoPresente = $textoPresente || ($e[$clave] ?? null) !== null;
        }
        if ($textoPresente) {
            $validator->errors()->add("diseno.elements.$i", 'Un QR no admite propiedades de texto.');
        }

        $ancho = (float) $e['width'];
        $alto = (float) $e['height'];
        if (round(abs($ancho - $alto), 4) > S::QR_TOLERANCIA_CUADRADO_PT) {
            $validator->errors()->add("diseno.elements.$i.width", 'El QR debe ser cuadrado.');
        }
        if ($ancho < S::QR_MIN_PT - S::QR_TOLERANCIA_CUADRADO_PT || $ancho > S::QR_MAX_PT + S::QR_TOLERANCIA_CUADRADO_PT) {
            $validator->errors()->add("diseno.elements.$i.width", 'El QR debe medir entre '.S::QR_MIN_PT.' y '.S::QR_MAX_PT.' pt.');
        }
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
            'elements' => array_values(array_map(fn (array $e) => $e['type'] === S::TIPO_QR ? [
                'id' => strtolower($e['id']),
                'type' => S::TIPO_QR,
                'x' => round((float) $e['x'], 2),
                'y' => round((float) $e['y'], 2),
                'width' => round((float) $e['width'], 2),
                'height' => round((float) $e['height'], 2),
            ] : [
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
