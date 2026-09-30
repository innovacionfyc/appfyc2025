<?php

namespace App\Support\CredentialFlow;

use RuntimeException;

/**
 * Lector mínimo de fuentes TrueType (glyf) para extraer las métricas que necesita Credential Flow:
 * unitsPerEm, ascent/descent horizontales (hhea) y el ancho de avance (hmtx) de cada carácter
 * mapeado en cmap (formatos 4 y 12). No interpreta contornos ni aplica kerning/GPOS.
 */
final class LectorTtf
{
    private string $datos;

    /** @var array<string, array{offset:int, length:int}> */
    private array $tablas = [];

    public function __construct(string $datos)
    {
        if (strlen($datos) < 12) {
            throw new RuntimeException('El archivo no es una fuente TrueType válida.');
        }
        $this->datos = $datos;

        $tag = substr($datos, 0, 4);
        if ($tag !== "\x00\x01\x00\x00" && $tag !== 'true') {
            throw new RuntimeException('Solo se admiten fuentes TrueType (glyf); no OpenType/CFF ni colecciones.');
        }

        $n = $this->u16(4);
        for ($i = 0; $i < $n; $i++) {
            $o = 12 + $i * 16;
            $nombre = substr($datos, $o, 4);
            $this->tablas[$nombre] = ['offset' => $this->u32($o + 8), 'length' => $this->u32($o + 12)];
        }

        foreach (['head', 'hhea', 'hmtx', 'cmap', 'maxp', 'glyf'] as $requerida) {
            if (! isset($this->tablas[$requerida])) {
                throw new RuntimeException("Falta la tabla «{$requerida}» en la fuente.");
            }
        }
    }

    public static function desdeArchivo(string $ruta): self
    {
        $datos = @file_get_contents($ruta);
        if ($datos === false) {
            throw new RuntimeException("No se pudo leer la fuente: {$ruta}");
        }

        return new self($datos);
    }

    /**
     * @return array{unitsPerEm:int, ascent:int, descent:int, pesoOs2:?int, anchos:array<int,int>}
     *                                                                                             descent es NEGATIVO (como en hhea). anchos: codepoint => avance en unidades de la fuente.
     */
    public function metricas(): array
    {
        $head = $this->tablas['head']['offset'];
        $hhea = $this->tablas['hhea']['offset'];

        $unitsPerEm = $this->u16($head + 18);
        $ascent = $this->i16($hhea + 4);
        $descent = $this->i16($hhea + 6);
        $numHMetrics = $this->u16($hhea + 34);
        $numGlyphs = $this->u16($this->tablas['maxp']['offset'] + 4);

        $hmtx = $this->tablas['hmtx']['offset'];
        $avanceGlifo = function (int $gid) use ($hmtx, $numHMetrics): int {
            $i = min($gid, $numHMetrics - 1);

            return $this->u16($hmtx + $i * 4);
        };

        $anchos = [];
        foreach ($this->cmap() as $cp => $gid) {
            if ($gid >= $numGlyphs) {
                continue;
            }
            $anchos[$cp] = $avanceGlifo($gid);
        }
        ksort($anchos);

        $pesoOs2 = isset($this->tablas['OS/2']) ? $this->u16($this->tablas['OS/2']['offset'] + 4) : null;

        return [
            'unitsPerEm' => $unitsPerEm,
            'ascent' => $ascent,
            'descent' => $descent,
            'pesoOs2' => $pesoOs2,
            'anchos' => $anchos,
        ];
    }

    /** @return array<int,int> codepoint => glyph id (gid 0 = .notdef se excluye) */
    private function cmap(): array
    {
        $base = $this->tablas['cmap']['offset'];
        $n = $this->u16($base + 2);

        // Se prefiere Unicode completo (3,10 / 0,4) y luego BMP (3,1 / 0,3 y similares).
        $elegido = null;
        $prioridad = -1;
        for ($i = 0; $i < $n; $i++) {
            $o = $base + 4 + $i * 8;
            $plat = $this->u16($o);
            $enc = $this->u16($o + 2);
            $sub = $base + $this->u32($o + 4);
            $p = match (true) {
                $plat === 3 && $enc === 10 => 3,
                $plat === 0 && $enc === 4 => 3,
                $plat === 3 && $enc === 1 => 2,
                $plat === 0 => 1,
                default => -1,
            };
            if ($p > $prioridad) {
                $prioridad = $p;
                $elegido = $sub;
            }
        }
        if ($elegido === null) {
            throw new RuntimeException('La fuente no tiene un cmap Unicode.');
        }

        $formato = $this->u16($elegido);
        $mapa = match ($formato) {
            4 => $this->cmapFormato4($elegido),
            12 => $this->cmapFormato12($elegido),
            default => throw new RuntimeException("Formato de cmap no soportado: {$formato}."),
        };

        // Sí se mantiene un formato 12 adicional si existe junto a uno 4 (cubre caracteres > U+FFFF).
        return $mapa;
    }

    /** @return array<int,int> */
    private function cmapFormato4(int $o): array
    {
        $segX2 = $this->u16($o + 6);
        $seg = intdiv($segX2, 2);
        $fin = $o + 14;
        $ini = $fin + $segX2 + 2;
        $delta = $ini + $segX2;
        $rango = $delta + $segX2;

        $mapa = [];
        for ($i = 0; $i < $seg; $i++) {
            $e = $this->u16($fin + $i * 2);
            $s = $this->u16($ini + $i * 2);
            $d = $this->i16($delta + $i * 2);
            $r = $this->u16($rango + $i * 2);
            if ($s === 0xFFFF) {
                continue;
            }
            for ($c = $s; $c <= $e; $c++) {
                if ($r === 0) {
                    $gid = ($c + $d) & 0xFFFF;
                } else {
                    $pos = $rango + $i * 2 + $r + ($c - $s) * 2;
                    $gid = $this->u16($pos);
                    if ($gid !== 0) {
                        $gid = ($gid + $d) & 0xFFFF;
                    }
                }
                if ($gid !== 0) {
                    $mapa[$c] = $gid;
                }
            }
        }

        return $mapa;
    }

    /** @return array<int,int> */
    private function cmapFormato12(int $o): array
    {
        $grupos = $this->u32($o + 12);
        $mapa = [];
        for ($i = 0; $i < $grupos; $i++) {
            $g = $o + 16 + $i * 12;
            $s = $this->u32($g);
            $e = $this->u32($g + 4);
            $gid0 = $this->u32($g + 8);
            for ($c = $s; $c <= $e; $c++) {
                $gid = $gid0 + ($c - $s);
                if ($gid !== 0) {
                    $mapa[$c] = $gid;
                }
            }
        }

        return $mapa;
    }

    private function u16(int $o): int
    {
        if ($o < 0 || $o + 2 > strlen($this->datos)) {
            throw new RuntimeException('Lectura fuera de rango en la fuente.');
        }

        return (ord($this->datos[$o]) << 8) | ord($this->datos[$o + 1]);
    }

    private function i16(int $o): int
    {
        $v = $this->u16($o);

        return $v >= 0x8000 ? $v - 0x10000 : $v;
    }

    private function u32(int $o): int
    {
        if ($o < 0 || $o + 4 > strlen($this->datos)) {
            throw new RuntimeException('Lectura fuera de rango en la fuente.');
        }

        return (ord($this->datos[$o]) << 24) | (ord($this->datos[$o + 1]) << 16) | (ord($this->datos[$o + 2]) << 8) | ord($this->datos[$o + 3]);
    }
}
