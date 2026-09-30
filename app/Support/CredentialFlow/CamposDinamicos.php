<?php

namespace App\Support\CredentialFlow;

/**
 * Catálogo de campos dinámicos de Credential Flow (V1). Es la ÚNICA fuente de verdad: el
 * validador del diseño, el editor (por props) y, más adelante, el importador de participantes y
 * el generador de PDF leen de aquí.
 *
 * Un elemento de texto con `field` = una de estas claves se reemplaza, al generar, por el dato
 * correspondiente de cada participante; con `field` = null es texto fijo. El preview es global y
 * fijo: existe solo para ver el diseño en el editor y nunca se guarda en el diseño.
 *
 * Reglas del catálogo:
 *  - Las claves son estables y NO se renombran (hay diseños guardados que las referencian). Solo
 *    se pueden agregar.
 *  - Todos los valores son texto ya listo para imprimir. `documento` incluye el tipo ("C.C. 1.023…")
 *    y `fecha` no es un tipo date: puede ser "29 DE SEPTIEMBRE DE 2026" o
 *    "LOS DÍAS 17, 18 Y 19 DE SEPTIEMBRE DE 2026". La composición de esos textos es de la fase de
 *    participantes, no de esta clase.
 *  - En V1 ningún campo admite varias líneas: se dibujan en una sola línea con autoajuste
 *    (ver DisenoSchema::ESCALA_MINIMA_TEXTO_DINAMICO).
 */
final class CamposDinamicos
{
    /** Formato esperado del valor final (para el generador futuro). */
    public const FORMATO_MAYUSCULAS = 'mayusculas';

    public const FORMATO_LITERAL = 'literal';

    private const CAMPOS = [
        'nombre_completo' => [
            'etiqueta' => 'Nombre completo',
            'tipo' => 'string',
            'preview' => 'JUAN CARLOS PÉREZ GÓMEZ',
            'maxLongitud' => 100,
            'multilinea' => false,
            'formato' => self::FORMATO_MAYUSCULAS,
        ],
        'documento' => [
            'etiqueta' => 'Documento',
            'tipo' => 'string',
            'preview' => 'C.C. 1.023.456.789',
            'maxLongitud' => 40,
            'multilinea' => false,
            'formato' => self::FORMATO_LITERAL,
        ],
        'evento' => [
            'etiqueta' => 'Evento',
            'tipo' => 'string',
            'preview' => 'GESTIÓN INTEGRAL DE PROPIEDAD HORIZONTAL',
            'maxLongitud' => 200,
            'multilinea' => false,
            'formato' => self::FORMATO_MAYUSCULAS,
        ],
        'fecha' => [
            'etiqueta' => 'Fecha',
            'tipo' => 'string',
            'preview' => '29 DE SEPTIEMBRE DE 2026',
            'maxLongitud' => 80,
            'multilinea' => false,
            'formato' => self::FORMATO_LITERAL,
        ],
        'intensidad_horaria' => [
            'etiqueta' => 'Intensidad horaria',
            'tipo' => 'string',
            'preview' => '16 HORAS',
            'maxLongitud' => 30,
            'multilinea' => false,
            'formato' => self::FORMATO_LITERAL,
        ],
    ];

    /** Claves válidas, en el orden del catálogo. */
    public static function claves(): array
    {
        return array_keys(self::CAMPOS);
    }

    /** Solo una clave exacta existe: `NOMBRE_COMPLETO` o `nombre completo` no. */
    public static function existe(mixed $key): bool
    {
        return is_string($key) && array_key_exists($key, self::CAMPOS);
    }

    /** Catálogo completo indexado por clave, con la clave también dentro de cada campo. */
    public static function todos(): array
    {
        $todos = [];
        foreach (self::CAMPOS as $key => $campo) {
            $todos[$key] = ['key' => $key] + $campo;
        }

        return $todos;
    }

    /** Lo que necesita el editor (lista ordenada, sin metadatos internos del generador). */
    public static function paraEditor(): array
    {
        return array_values(array_map(fn (array $c) => [
            'key' => $c['key'],
            'etiqueta' => $c['etiqueta'],
            'preview' => $c['preview'],
            'maxLongitud' => $c['maxLongitud'],
            'tipo' => $c['tipo'],
        ], self::todos()));
    }
}
