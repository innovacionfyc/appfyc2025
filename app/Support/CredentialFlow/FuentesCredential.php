<?php

namespace App\Support\CredentialFlow;

use RuntimeException;

/**
 * Catálogo de fuentes de Credential Flow.
 *
 * Solo las fuentes «reproducibles» (archivos TTF locales con licencia que permite embeberlas)
 * pueden usarse en la generación del PDF. Hoy: Outfit (SIL OFL 1.1). Figtree, Arial y sans-serif
 * son valores HEREDADOS: siguen siendo válidos en diseños ya guardados, pero no están preparados
 * para generación PDF y el editor lo advierte.
 *
 * Las métricas (ancho de avance por carácter, ascent/descent) se extraen de los TTF con
 * `php artisan credential-flow:fuentes-metricas` y se guardan en metricas.json. Editor (JS) y
 * generador (PHP) leen esa MISMA tabla.
 */
final class FuentesCredential
{
    public const OUTFIT = 'outfit';

    /** Valores heredados: siguen validando, pero sin soporte de generación PDF. */
    public const HEREDADAS = ['Figtree', 'Arial', 'sans-serif'];

    /** @var array<int,string> peso => nombre del archivo */
    private const ARCHIVOS_OUTFIT = [
        300 => 'Outfit-Light.ttf',
        400 => 'Outfit-Regular.ttf',
        500 => 'Outfit-Medium.ttf',
        600 => 'Outfit-SemiBold.ttf',
        700 => 'Outfit-Bold.ttf',
        800 => 'Outfit-ExtraBold.ttf',
    ];

    public static function directorio(): string
    {
        return resource_path('fonts/credential-flow');
    }

    public static function rutaMetricas(): string
    {
        return self::directorio().'/metricas.json';
    }

    public static function rutaArchivo(string $familia, int $peso): string
    {
        $archivo = self::ARCHIVOS_OUTFIT[$peso] ?? null;
        if ($familia !== self::OUTFIT || $archivo === null) {
            throw new RuntimeException("Fuente no reproducible: {$familia} {$peso}.");
        }

        return self::directorio().'/outfit/'.$archivo;
    }

    /** @return array<int,string> */
    public static function pesosReproducibles(): array
    {
        return array_keys(self::ARCHIVOS_OUTFIT);
    }

    /** Familias válidas al guardar (reproducibles + heredadas). */
    public static function familias(): array
    {
        return [self::OUTFIT, ...self::HEREDADAS];
    }

    public static function esHeredada(string $familia): bool
    {
        return in_array($familia, self::HEREDADAS, true);
    }

    public static function esReproducible(string $familia, int $peso): bool
    {
        return $familia === self::OUTFIT && isset(self::ARCHIVOS_OUTFIT[$peso]);
    }

    /**
     * ¿Es válida la pareja familia+peso? Outfit exige uno de sus pesos reales; las heredadas
     * conservan los seis pesos históricos para no invalidar diseños existentes.
     */
    public static function combinacionValida(string $familia, int $peso): bool
    {
        if ($familia === self::OUTFIT) {
            return isset(self::ARCHIVOS_OUTFIT[$peso]);
        }

        return self::esHeredada($familia) && in_array($peso, DisenoSchema::PESOS, true);
    }

    /** Datos que recibe el editor (los archivos y métricas los importa Vite desde resources/fonts). */
    public static function paraEditor(): array
    {
        return [
            'familias' => [
                ['valor' => self::OUTFIT, 'etiqueta' => 'Outfit', 'heredada' => false, 'pesos' => self::pesosReproducibles()],
                ...array_map(
                    fn (string $f) => ['valor' => $f, 'etiqueta' => $f, 'heredada' => true, 'pesos' => DisenoSchema::PESOS],
                    self::HEREDADAS
                ),
            ],
            'porDefecto' => ['familia' => self::OUTFIT, 'peso' => 700],
        ];
    }

    /**
     * Genera la tabla de métricas a partir de los TTF.
     *
     * @return array<string,mixed>
     */
    public static function generarMetricas(): array
    {
        $fuentes = [];
        foreach (self::ARCHIVOS_OUTFIT as $peso => $archivo) {
            $ruta = self::rutaArchivo(self::OUTFIT, $peso);
            $m = LectorTtf::desdeArchivo($ruta)->metricas();

            $fuentes[self::OUTFIT][(string) $peso] = [
                'archivo' => 'outfit/'.$archivo,
                'sha256' => hash_file('sha256', $ruta),
                'unitsPerEm' => $m['unitsPerEm'],
                'ascent' => $m['ascent'],
                'descent' => $m['descent'],
                'anchos' => $m['anchos'],
            ];
        }

        return ['version' => 1, 'fuentes' => $fuentes];
    }

    /** Serialización estable: una línea por fuente, para que un cambio se lea bien en el diff. */
    public static function serializarMetricas(array $metricas): string
    {
        $lineas = [];
        foreach ($metricas['fuentes'] as $familia => $pesos) {
            $bloques = [];
            foreach ($pesos as $peso => $d) {
                $anchos = $d['anchos'];
                $d['anchos'] = '@@';
                $json = json_encode($d, JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
                $bloques[] = '    '.json_encode((string) $peso).': '
                    .str_replace('"@@"', json_encode((object) $anchos, JSON_THROW_ON_ERROR), $json);
            }
            $lineas[] = '  '.json_encode($familia).": {\n".implode(",\n", $bloques)."\n  }";
        }

        return "{\n  \"version\": ".$metricas['version'].",\n  \"fuentes\": {\n".implode(",\n", $lineas)."\n  }\n}\n";
    }

    /**
     * Ancho de un texto en puntos: suma de avances (hmtx) del texto normalizado a NFC, sin kerning
     * ni ligaduras. Es la misma cuenta que hace el editor. Si algún carácter no existe en la fuente
     * no se estima nada: `soportado` es false y `faltantes` lista los caracteres.
     *
     * @return array{ancho:float, soportado:bool, faltantes:array<int,string>, texto:string}
     */
    public static function medirTexto(string $texto, string $familia, int $peso, float $fontSize): array
    {
        $texto = \Normalizer::normalize($texto, \Normalizer::FORM_C);
        if ($texto === false) {
            throw new RuntimeException('El texto no es UTF-8 válido.');
        }

        $m = self::metricasDe($familia, $peso);
        $suma = 0;
        $faltantes = [];
        foreach (mb_str_split($texto) as $caracter) {
            $cp = mb_ord($caracter);
            if ($cp < 32 || ! isset($m['anchos'][$cp])) {
                $faltantes[$caracter] = $caracter;

                continue;
            }
            $suma += $m['anchos'][$cp];
        }

        return [
            'ancho' => $suma / $m['unitsPerEm'] * $fontSize,
            'soportado' => $faltantes === [],
            'faltantes' => array_values($faltantes),
            'texto' => $texto,
        ];
    }

    /** Línea base (pt desde el borde superior de la página) de un texto centrado en su caja. */
    public static function lineaBase(float $y, float $alto, string $familia, int $peso, float $fontSize): float
    {
        $m = self::metricasDe($familia, $peso);

        return $y + $alto / 2 + (($m['ascent'] + $m['descent']) / 2 / $m['unitsPerEm']) * $fontSize;
    }

    /** Tabla guardada en metricas.json. */
    public static function metricas(): array
    {
        $ruta = self::rutaMetricas();
        if (! is_file($ruta)) {
            throw new RuntimeException('Falta metricas.json: ejecuta php artisan credential-flow:fuentes-metricas.');
        }

        return json_decode(file_get_contents($ruta), true, 512, JSON_THROW_ON_ERROR);
    }

    /**
     * Métricas de una fuente ya cargadas para uso del generador.
     *
     * @return array{unitsPerEm:int, ascent:int, descent:int, anchos:array<int,int>}
     */
    public static function metricasDe(string $familia, int $peso): array
    {
        $d = self::metricas()['fuentes'][$familia][(string) $peso] ?? null;
        if ($d === null) {
            throw new RuntimeException("Sin métricas para {$familia} {$peso}.");
        }
        $d['anchos'] = array_map('intval', $d['anchos']);

        return $d;
    }
}
