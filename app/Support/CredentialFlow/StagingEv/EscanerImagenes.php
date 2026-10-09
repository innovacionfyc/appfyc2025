<?php

namespace App\Support\CredentialFlow\StagingEv;

use InvalidArgumentException;

/**
 * Inventario de las imágenes de fondo del sistema viejo (solo lectura: calcula SHA-256, tamaño, dimensiones, tipo real
 * y si el FPDF 1.81 del sistema viejo habría podido dibujarla). No mueve, convierte ni modifica ningún archivo.
 *
 * La regla de «renderizable» replica lo que hacía FPDF 1.81 en Image(): el tipo se decide por la EXTENSIÓN del nombre
 * (lo que sigue al último punto), y luego _parsejpg/_parsepng/_parsegif validan el contenido. Se verificó contra la
 * ejecución real de FPDF sobre los 632 archivos (629 cargables, 3 no).
 */
final class EscanerImagenes
{
    public const PREFIJO_RUTA = 'document/certImages/';

    /** Tamaño máximo que se lee para clasificar: nada de contenido ejecutable, solo cabeceras. */
    private const CABECERA = 64;

    /**
     * @return array<int,array<string,mixed>> una descripción por archivo, ordenada por nombre
     */
    public function escanear(string $directorio, string $prefijoRuta = self::PREFIJO_RUTA): array
    {
        if (! is_dir($directorio)) {
            throw new InvalidArgumentException('La carpeta de imágenes no existe.');
        }

        $nombres = [];
        foreach (new \DirectoryIterator($directorio) as $f) {
            if ($f->isDot() || ! $f->isFile() || $f->isLink() || str_starts_with($f->getFilename(), '.')) {
                continue;
            }
            $nombres[] = $f->getFilename();
        }
        sort($nombres, SORT_STRING);

        $r = [];
        foreach ($nombres as $nombre) {
            $r[] = $this->describir($directorio.DIRECTORY_SEPARATOR.$nombre, $nombre, $prefijoRuta);
        }

        return $r;
    }

    /** @return array<string,mixed> */
    public function describir(string $rutaFisica, string $nombre, string $prefijoRuta = self::PREFIJO_RUTA): array
    {
        $evaluacion = self::evaluar($nombre, $rutaFisica);

        return [
            'ruta_relativa' => $prefijoRuta.$nombre,
            'nombre_original' => $nombre,
            'extension' => Normalizador::extensionFpdf($nombre),
            'sha256' => hash_file('sha256', $rutaFisica),
            'bytes' => filesize($rutaFisica),
            'ancho_px' => $evaluacion['ancho'],
            'alto_px' => $evaluacion['alto'],
            'mime_real' => $evaluacion['mime'],
            'renderizable_fpdf' => $evaluacion['ok'],
            'motivo_no_renderizable' => $evaluacion['motivo'],
        ];
    }

    /**
     * @return array{ok:bool,motivo:?string,mime:?string,ancho:?int,alto:?int}
     */
    public static function evaluar(string $nombre, string $rutaFisica): array
    {
        $info = @getimagesize($rutaFisica);
        $mime = is_array($info) ? ($info['mime'] ?? null) : null;
        $ancho = is_array($info) ? (int) $info[0] : null;
        $alto = is_array($info) ? (int) $info[1] : null;
        $tipoReal = is_array($info) ? (int) $info[2] : null;

        $falla = fn (string $motivo): array => ['ok' => false, 'motivo' => $motivo, 'mime' => $mime, 'ancho' => $ancho, 'alto' => $alto];

        $ext = Normalizador::extensionFpdf($nombre);
        if ($ext === null) {
            return $falla('SIN_EXTENSION');
        }
        $ext = $ext === 'jpeg' ? 'jpg' : $ext;
        if (! in_array($ext, ['jpg', 'png', 'gif'], true)) {
            return $falla('TIPO_NO_SOPORTADO');
        }

        if ($ext === 'jpg') {
            if ($info === false) {
                return $falla('JPEG_INVALIDO');
            }

            return $tipoReal === IMAGETYPE_JPEG ? ['ok' => true, 'motivo' => null, 'mime' => $mime, 'ancho' => $ancho, 'alto' => $alto] : $falla('NO_ES_JPEG');
        }

        if ($ext === 'gif') {
            return $tipoReal === IMAGETYPE_GIF ? ['ok' => true, 'motivo' => null, 'mime' => $mime, 'ancho' => $ancho, 'alto' => $alto] : $falla('NO_ES_GIF');
        }

        // PNG: _parsepng lee la firma y la cabecera IHDR.
        $h = @file_get_contents($rutaFisica, false, null, 0, self::CABECERA);
        if ($h === false || strlen($h) < 29 || ! str_starts_with($h, "\x89PNG\r\n\x1a\n")) {
            return $falla('NO_ES_PNG');
        }
        if (substr($h, 12, 4) !== 'IHDR') {
            return $falla('PNG_SIN_IHDR');
        }
        if (ord($h[24]) > 8) {
            return $falla('PNG_16_BITS');
        }
        if (! in_array(ord($h[25]), [0, 2, 3, 4, 6], true)) {
            return $falla('PNG_TIPO_COLOR');
        }
        if (ord($h[26]) !== 0 || ord($h[27]) !== 0) {
            return $falla('PNG_METODO');
        }
        if (ord($h[28]) !== 0) {
            return $falla('PNG_ENTRELAZADO');
        }
        if ($info === false) {
            return $falla('PNG_INVALIDO');
        }

        return ['ok' => true, 'motivo' => null, 'mime' => $mime, 'ancho' => $ancho, 'alto' => $alto];
    }

    /**
     * Descripciones de imágenes a partir de un MANIFIESTO (JSON con `archivos[]`), que es la fuente de verdad del nombre
     * ORIGINAL, la ruta original, el SHA-256, el tamaño, el MIME y las dimensiones. Sirve cuando el nombre original no se
     * puede usar como nombre de archivo (Windows no admite `?`, `"`, un punto final…): el archivo físico puede llamarse
     * distinto (`nombre_local`) y nunca se usa su nombre para nada más que comprobar que existe.
     *
     * Con `$directorio` verifica que cada archivo físico exista y pese lo que dice el manifiesto (y, con `$verificarSha`,
     * que su SHA-256 coincida). No modifica ni lee más que eso.
     *
     * @return array<int,array<string,mixed>>
     *
     * @throws InvalidArgumentException si el manifiesto no es válido o no coincide con los archivos
     */
    public static function desdeManifiesto(string $rutaManifiesto, ?string $directorio = null, bool $verificarSha = false, string $prefijoRuta = self::PREFIJO_RUTA): array
    {
        if (! is_file($rutaManifiesto) || ! is_readable($rutaManifiesto)) {
            throw new InvalidArgumentException('No se puede leer el manifiesto de imágenes.');
        }
        $json = json_decode((string) file_get_contents($rutaManifiesto), true);
        if (! is_array($json) || ! isset($json['archivos']) || ! is_array($json['archivos']) || $json['archivos'] === []) {
            throw new InvalidArgumentException('El manifiesto de imágenes no tiene la forma esperada («archivos»).');
        }

        $imagenes = [];
        $vistos = [];
        $problemas = 0;
        foreach ($json['archivos'] as $i => $a) {
            foreach (['nombre_original', 'nombre_local', 'bytes', 'sha256', 'renderizable_fpdf'] as $campo) {
                if (! is_array($a) || ! array_key_exists($campo, $a)) {
                    throw new InvalidArgumentException("El manifiesto tiene una entrada incompleta (#{$i}, falta «{$campo}»).");
                }
            }
            $nombre = (string) $a['nombre_original'];
            if ($nombre === '' || str_contains($nombre, '/') || preg_match('/^[0-9a-f]{64}$/', (string) $a['sha256']) !== 1 || ! is_int($a['bytes'])) {
                throw new InvalidArgumentException("El manifiesto tiene una entrada inválida (#{$i}).");
            }
            if (isset($vistos[$nombre])) {
                throw new InvalidArgumentException("El manifiesto repite un nombre original (#{$i}).");
            }
            $vistos[$nombre] = true;

            if ($directorio !== null) {
                $fisico = rtrim($directorio, '\\/').DIRECTORY_SEPARATOR.basename((string) $a['nombre_local']);
                if (! is_file($fisico) || filesize($fisico) !== $a['bytes'] || ($verificarSha && hash_file('sha256', $fisico) !== $a['sha256'])) {
                    $problemas++;
                }
            }

            $imagenes[] = [
                'ruta_relativa' => $prefijoRuta.$nombre,
                'nombre_original' => $nombre,
                'extension' => $a['extension'] ?? Normalizador::extensionFpdf($nombre),
                'sha256' => $a['sha256'],
                'bytes' => $a['bytes'],
                'ancho_px' => $a['ancho_px'] ?? null,
                'alto_px' => $a['alto_px'] ?? null,
                'mime_real' => $a['mime_real'] ?? null,
                'renderizable_fpdf' => (bool) $a['renderizable_fpdf'],
                'motivo_no_renderizable' => $a['motivo'] ?? ($a['motivo_no_renderizable'] ?? null),
            ];
        }
        if ($problemas > 0) {
            throw new InvalidArgumentException("{$problemas} archivo(s) físicos no existen o no coinciden con el manifiesto (tamaño".($verificarSha ? ' o SHA-256' : '').').');
        }

        usort($imagenes, fn ($x, $y) => strcmp($x['nombre_original'], $y['nombre_original']));

        return $imagenes;
    }

    /**
     * Compara el inventario con el listado `ruta|bytes|sha256` hecho en el servidor (formato del inventario de la Fase 0).
     *
     * @param  array<int,array<string,mixed>>  $imagenes
     * @return array{coinciden:int,distintas:int,solo_en_inventario:int,solo_en_carpeta:int,sha256_inventario:string}
     */
    public static function compararConInventario(array $imagenes, string $rutaInventario): array
    {
        if (! is_file($rutaInventario)) {
            throw new InvalidArgumentException('No se puede leer el inventario.');
        }

        $inventario = [];
        foreach (file($rutaInventario, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) ?: [] as $linea) {
            $p = explode('|', $linea);
            if (count($p) === 3 && ctype_digit($p[1]) && preg_match('/^[0-9a-f]{64}$/', $p[2]) === 1) {
                $inventario[$p[0]] = [(int) $p[1], $p[2]];
            }
        }

        $coinciden = $distintas = 0;
        foreach ($imagenes as $i) {
            if (! isset($inventario[$i['ruta_relativa']])) {
                continue;
            }
            [$bytes, $sha] = $inventario[$i['ruta_relativa']];
            ($bytes === (int) $i['bytes'] && $sha === $i['sha256']) ? $coinciden++ : $distintas++;
            unset($inventario[$i['ruta_relativa']]);
        }

        return [
            'coinciden' => $coinciden,
            'distintas' => $distintas,
            'solo_en_inventario' => count($inventario),
            'solo_en_carpeta' => count($imagenes) - $coinciden - $distintas,
            'sha256_inventario' => hash_file('sha256', $rutaInventario),
        ];
    }
}
