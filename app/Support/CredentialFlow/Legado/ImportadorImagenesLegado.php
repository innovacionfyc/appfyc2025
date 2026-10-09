<?php

namespace App\Support\CredentialFlow\Legado;

use Closure;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use RuntimeException;

/**
 * IMPORTADOR de las imágenes históricas al storage privado (Fase 11A). La identidad de un contenido es su SHA-256 (`cf_plantillas_legado_contenidos.sha256`): jamás el
 * nombre del archivo, su extensión histórica ni el id del evento. Destino: `credential-flow/legado/plantillas/{sha256}/original.{ext}` (`RutasLegado::plantilla`), una sola
 * copia por contenido en el disco privado.
 *
 *  - Streaming (bloques de 1 MB, SHA-256 incremental): nunca se lee un archivo completo en memoria (cabe en `memory_limit=128M`).
 *  - Escritura segura: temporal en el MISMO directorio → cierre → bytes y SHA-256 del temporal → `rename` atómico. Nunca se escribe sobre el definitivo.
 *  - Nunca sobrescribe: si el destino existe con el mismo SHA se reutiliza; con otro SHA, error controlado `DESTINO_SHA_DISTINTO`.
 *  - `ruta_almacenada` se actualiza SOLO tras verificar archivo, bytes > 0 y SHA; nunca queda apuntando a un archivo inválido.
 *  - Idempotente y reanudable: el estado es la propia columna + el archivo; una corrida interrumpida (entre el `rename` y el `UPDATE`) se completa al repetirla.
 *  - Faltantes: si el SHA esperado no está en la fuente → `CONTENIDO_FALTANTE` con ids técnicos (sin PII); no se inventa nada.
 *  - Diario (sin schema): cada corrida que cambia algo deja `credential-flow/legado/importaciones/{id}.json`; `revertir` borra SOLO lo que esa corrida creó y restaura la ruta previa.
 */
final class ImportadorImagenesLegado
{
    public const BLOQUE = 1048576;

    public const DIARIOS = 'credential-flow/legado/importaciones';

    /** Solo para tests: se ejecuta tras el `rename` y antes del `UPDATE` (simula una caída a mitad de la corrida). */
    public static ?Closure $despuesDeRenombrar = null;

    public function __construct(private readonly string $disco = 'local') {}

    /**
     * @return array{total:int,importados:int,reutilizados:int,verificados:int,faltantes:list<int>,errores:array<string,list<int>>,simulado:bool,diario:?string,bytes_copiados:int,pendientes:int}
     */
    public function importar(string $origen, ?string $manifiesto = null, bool $simular = false, ?int $limite = null): array
    {
        if (! is_dir($origen)) {
            throw new InvalidArgumentException('La carpeta de origen no existe.');
        }
        $contenidos = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->get(['id', 'sha256', 'bytes', 'mime_real', 'ruta_almacenada']);
        $indice = $this->indiceOrigen($origen, $manifiesto, $contenidos->pluck('sha256')->all());
        $this->limpiarTemporales();

        $r = ['total' => $contenidos->count(), 'importados' => 0, 'reutilizados' => 0, 'verificados' => 0, 'faltantes' => [], 'errores' => [], 'simulado' => $simular, 'diario' => null, 'bytes_copiados' => 0, 'pendientes' => 0];
        $diario = [];
        $procesados = 0;
        foreach ($contenidos as $c) {
            if ($limite !== null && $procesados >= $limite) {
                $r['pendientes']++;

                continue;
            }
            $procesados++;
            $ext = RutasLegado::extensionPorMime($c->mime_real);
            if ($ext === null) {
                $r['errores']['EXTENSION_NO_SOPORTADA'][] = (int) $c->id;

                continue;
            }
            $ruta = RutasLegado::plantilla((string) $c->sha256, $ext);
            $fisico = Storage::disk($this->disco)->path($ruta);

            // 1. ¿Ya está correcto? (ruta registrada + archivo con bytes y SHA esperados)
            if (is_file($fisico)) {
                if (! $this->coincide($fisico, (string) $c->sha256, (int) $c->bytes)) {
                    $r['errores']['DESTINO_SHA_DISTINTO'][] = (int) $c->id;   // nunca se sobrescribe

                    continue;
                }
                if ($c->ruta_almacenada === $ruta) {
                    $r['verificados']++;
                } else {
                    $r['reutilizados']++;
                    if (! $simular) {
                        $this->registrarRuta((int) $c->id, $ruta);
                        $diario[] = ['contenido_id' => (int) $c->id, 'ruta' => $ruta, 'sha256' => (string) $c->sha256, 'ruta_previa' => $c->ruta_almacenada, 'creado' => false];
                    }
                }

                continue;
            }

            // 2. Hay que copiar desde la fuente.
            $fuente = $indice[(string) $c->sha256] ?? null;
            if ($fuente === null || ! is_file($fuente)) {
                $r['faltantes'][] = (int) $c->id;

                continue;
            }
            if ($simular) {
                $r['importados']++;

                continue;
            }
            try {
                $bytes = $this->copiarSeguro($fuente, $fisico, (string) $c->sha256, (int) $c->bytes);
            } catch (RuntimeException $e) {
                $r['errores'][$e->getMessage()][] = (int) $c->id;

                continue;
            }
            if (self::$despuesDeRenombrar !== null) {
                (self::$despuesDeRenombrar)((int) $c->id);
            }
            $this->registrarRuta((int) $c->id, $ruta);
            $diario[] = ['contenido_id' => (int) $c->id, 'ruta' => $ruta, 'sha256' => (string) $c->sha256, 'ruta_previa' => $c->ruta_almacenada, 'creado' => true];
            $r['importados']++;
            $r['bytes_copiados'] += $bytes;
        }
        if ($diario !== [] && ! $simular) {
            $id = date('Ymd-His').'-'.bin2hex(random_bytes(3));
            Storage::disk($this->disco)->put(self::DIARIOS.'/'.$id.'.json', json_encode(['id' => $id, 'entradas' => $diario], JSON_UNESCAPED_UNICODE));
            $r['diario'] = $id;
        }
        sort($r['faltantes']);

        return $r;
    }

    /**
     * Verifica las 624 referencias sin escribir nada: ruta no nula, archivo existente, bytes, SHA-256 y MIME esperados.
     *
     * @return array{total:int,ok:int,fallos:array<string,list<int>>}
     */
    public function verificar(): array
    {
        $disco = Storage::disk($this->disco);
        $r = ['total' => 0, 'ok' => 0, 'fallos' => []];
        $mime = function_exists('finfo_open') ? finfo_open(FILEINFO_MIME_TYPE) : false;
        DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->chunkById(100, function ($filas) use (&$r, $disco, $mime) {
            foreach ($filas as $c) {
                $r['total']++;
                $fallo = null;
                $fisico = $c->ruta_almacenada === null ? null : $disco->path((string) $c->ruta_almacenada);
                if ($c->ruta_almacenada === null) {
                    $fallo = 'RUTA_NULA';
                } elseif ($fisico === null || ! is_file($fisico)) {
                    $fallo = 'ARCHIVO_INEXISTENTE';
                } elseif (filesize($fisico) !== (int) $c->bytes || (int) $c->bytes === 0) {
                    $fallo = 'BYTES_DISTINTOS';
                } elseif (! hash_equals((string) $c->sha256, (string) hash_file('sha256', $fisico))) {
                    $fallo = 'SHA_DISTINTO';
                } elseif ($mime !== false && $c->mime_real !== null && finfo_file($mime, $fisico) !== $c->mime_real) {
                    $fallo = 'MIME_DISTINTO';
                }
                if ($fallo === null) {
                    $r['ok']++;
                } else {
                    $r['fallos'][$fallo][] = (int) $c->id;
                }
            }
        });

        return $r;
    }

    /**
     * Manifiesto sin PII (contenido_id, sha256, bytes, mime, ruta relativa) y su huella determinista (SHA-256 de las líneas ordenadas): comparable entre reconstrucciones.
     *
     * @return array{archivos:list<array<string,mixed>>,huella:string}
     */
    public function manifiesto(): array
    {
        $archivos = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->get(['id', 'sha256', 'bytes', 'mime_real', 'ruta_almacenada'])
            ->map(fn ($c) => ['contenido_id' => (int) $c->id, 'sha256' => (string) $c->sha256, 'bytes' => (int) $c->bytes, 'mime' => $c->mime_real, 'ruta' => $c->ruta_almacenada])->all();
        $lineas = array_map(fn ($a) => implode('|', [$a['contenido_id'], $a['sha256'], $a['bytes'], $a['mime'], $a['ruta']]), $archivos);

        return ['archivos' => $archivos, 'huella' => hash('sha256', implode("\n", $lineas))];
    }

    /** Huella del contenido REAL del storage del módulo: SHA-256 de las líneas `ruta|sha256|bytes` de todos los archivos bajo `plantillas/` (ordenadas). */
    public function huellaStorage(): array
    {
        $raiz = Storage::disk($this->disco)->path(RutasLegado::RAIZ.'/plantillas');
        $lineas = [];
        $temporales = 0;
        if (is_dir($raiz)) {
            foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)) as $f) {
                if ($f->isFile()) {
                    if (str_ends_with($f->getFilename(), '.tmp')) {
                        $temporales++;

                        continue;
                    }
                    $rel = str_replace('\\', '/', substr($f->getPathname(), strlen($raiz) + 1));
                    $lineas[] = $rel.'|'.hash_file('sha256', $f->getPathname()).'|'.$f->getSize();
                }
            }
        }
        sort($lineas);

        return ['archivos' => count($lineas), 'temporales' => $temporales, 'huella' => hash('sha256', implode("\n", $lineas))];
    }

    /**
     * Revierte UNA corrida: borra solo los archivos que esa corrida creó (y que siguen teniendo el SHA registrado) y restaura la ruta previa. No toca nada más.
     *
     * @return array{borrados:int,restauradas:int,omitidos:int}
     */
    public function revertir(string $diarioId): array
    {
        if (preg_match('/^[0-9]{8}-[0-9]{6}-[0-9a-f]{6}$/', $diarioId) !== 1) {
            throw new InvalidArgumentException('Identificador de diario inválido.');
        }
        $disco = Storage::disk($this->disco);
        $ruta = self::DIARIOS.'/'.$diarioId.'.json';
        if (! $disco->exists($ruta)) {
            throw new InvalidArgumentException('No existe ese diario de importación.');
        }
        $diario = json_decode((string) $disco->get($ruta), true);
        $r = ['borrados' => 0, 'restauradas' => 0, 'omitidos' => 0];
        foreach (array_reverse($diario['entradas'] ?? []) as $e) {
            $fisico = $disco->path((string) $e['ruta']);
            $actual = DB::table('cf_plantillas_legado_contenidos')->where('id', $e['contenido_id'])->value('ruta_almacenada');
            if ($actual === $e['ruta']) {
                DB::table('cf_plantillas_legado_contenidos')->where('id', $e['contenido_id'])->update(['ruta_almacenada' => $e['ruta_previa'], 'updated_at' => now()]);
                $r['restauradas']++;
            }
            if (($e['creado'] ?? false) && is_file($fisico) && hash_equals((string) $e['sha256'], (string) hash_file('sha256', $fisico))) {
                unlink($fisico);
                @rmdir(dirname($fisico));
                $r['borrados']++;
            } elseif ($e['creado'] ?? false) {
                $r['omitidos']++;
            }
        }
        $disco->move($ruta, self::DIARIOS.'/'.$diarioId.'.revertido.json');

        return $r;
    }

    // ── Internos ──────────────────────────────────────────────────────────────

    /**
     * SHA-256 → archivo de la fuente. Con manifiesto se usan sus SHA (cada copia se vuelve a verificar al escribir); sin manifiesto se calcula el SHA de cada archivo, en streaming.
     *
     * @param  list<string>  $necesarios
     * @return array<string,string>
     */
    private function indiceOrigen(string $origen, ?string $manifiesto, array $necesarios): array
    {
        $indice = [];
        if ($manifiesto !== null) {
            $j = json_decode((string) file_get_contents($manifiesto), true);
            foreach ((array) ($j['archivos'] ?? []) as $a) {
                $sha = (string) ($a['sha256'] ?? '');
                $nombre = (string) ($a['nombre_local'] ?? basename((string) ($a['ruta_tar'] ?? '')));
                $archivo = rtrim($origen, '/\\').DIRECTORY_SEPARATOR.$nombre;
                // Solo un NOMBRE de archivo (sin separadores de ruta): nunca una ruta; «..» dentro de un nombre (p. ej. «a..b.png») es válido.
                if (preg_match('/^[0-9a-f]{64}$/', $sha) === 1 && $nombre !== '' && $nombre !== '.' && $nombre !== '..' && basename($nombre) === $nombre && ! str_contains($nombre, chr(92)) && ! isset($indice[$sha])) {
                    $indice[$sha] = $archivo;
                }
            }

            return $indice;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($origen, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile()) {
                $indice[hash_file('sha256', $f->getPathname())] ??= $f->getPathname();
            }
        }

        return $indice;
    }

    /** Copia en streaming a un temporal del mismo directorio, verifica bytes y SHA-256 y hace `rename` atómico. @throws RuntimeException con el código del error */
    private function copiarSeguro(string $fuente, string $destino, string $sha, int $bytesEsperados): int
    {
        $dir = dirname($destino);
        if (! is_dir($dir) && ! mkdir($dir, 0775, true) && ! is_dir($dir)) {
            throw new RuntimeException('ALMACENAMIENTO_NO_ESCRIBIBLE');
        }
        $temporal = $dir.DIRECTORY_SEPARATOR.'.'.basename($destino).'.'.bin2hex(random_bytes(6)).'.tmp';
        $in = fopen($fuente, 'rb');
        $out = fopen($temporal, 'xb');
        if ($in === false || $out === false) {
            $in !== false && fclose($in);
            throw new RuntimeException('ALMACENAMIENTO_NO_ESCRIBIBLE');
        }
        $h = hash_init('sha256');
        $total = 0;
        try {
            while (! feof($in)) {
                $bloque = fread($in, self::BLOQUE);
                if ($bloque === false) {
                    throw new RuntimeException('ORIGEN_ILEGIBLE');
                }
                if ($bloque === '') {
                    continue;
                }
                hash_update($h, $bloque);
                if (fwrite($out, $bloque) !== strlen($bloque)) {
                    throw new RuntimeException('ALMACENAMIENTO_NO_ESCRIBIBLE');
                }
                $total += strlen($bloque);
            }
            fflush($out);
            fclose($out);
            fclose($in);
            if ($total === 0 || $total !== $bytesEsperados || ! hash_equals($sha, hash_final($h)) || ! hash_equals($sha, (string) hash_file('sha256', $temporal))) {
                throw new RuntimeException('ORIGEN_SHA_DISTINTO');
            }
            if (is_file($destino) || ! rename($temporal, $destino)) {
                throw new RuntimeException('DESTINO_NO_ATOMICO');
            }
        } catch (\Throwable $e) {
            is_resource($out) && fclose($out);
            is_resource($in) && fclose($in);
            @unlink($temporal);

            throw $e instanceof RuntimeException ? $e : new RuntimeException('ALMACENAMIENTO_NO_ESCRIBIBLE');
        }

        return $total;
    }

    private function coincide(string $fisico, string $sha, int $bytes): bool
    {
        $t = filesize($fisico);

        return $t !== false && $t > 0 && $t === $bytes && hash_equals($sha, (string) hash_file('sha256', $fisico));
    }

    private function registrarRuta(int $id, string $ruta): void
    {
        DB::table('cf_plantillas_legado_contenidos')->where('id', $id)->update(['ruta_almacenada' => $ruta, 'updated_at' => now()]);
    }

    /** Temporales huérfanos de una corrida caída (más de 10 minutos): lo único que se borra sin diario. */
    private function limpiarTemporales(): void
    {
        $raiz = Storage::disk($this->disco)->path(RutasLegado::RAIZ.'/plantillas');
        if (! is_dir($raiz)) {
            return;
        }
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($raiz, \FilesystemIterator::SKIP_DOTS)) as $f) {
            if ($f->isFile() && str_ends_with($f->getFilename(), '.tmp') && time() - $f->getMTime() > 600) {
                @unlink($f->getPathname());
            }
        }
    }
}
