<?php

namespace Tests\Feature\CredentialFlow\Support;

use RuntimeException;

/**
 * Inspector mínimo de los PDFs QA generados con TCPDF/FPDI (no es un parser PDF general): localiza
 * objetos y streams (descomprime Flate), y lee MediaBox, fuentes de la página, XObjects y los
 * operadores Tf / Td / TJ del contenido de la página. Sin dependencias externas.
 */
final class InspectorPdf
{
    /** @var array<int,string> número de objeto => cuerpo (dict + stream sin descomprimir) */
    private array $objetos = [];

    public function __construct(public readonly string $bytes)
    {
        preg_match_all('/(\d+) 0 obj\s*(.*?)endobj/s', $bytes, $m, PREG_SET_ORDER);
        foreach ($m as $o) {
            $this->objetos[(int) $o[1]] = $o[2];
        }
    }

    public function firmaValida(): bool
    {
        return str_starts_with($this->bytes, '%PDF-') && str_contains(substr($this->bytes, -1024), '%%EOF');
    }

    public function paginas(): int
    {
        return count(array_filter($this->objetos, fn (string $o) => preg_match('/\/Type\s*\/Page(?![a-z])/', $o) === 1));
    }

    private function paginaObjeto(): string
    {
        foreach ($this->objetos as $o) {
            if (preg_match('/\/Type\s*\/Page(?![a-z])/', $o)) {
                return $o;
            }
        }
        throw new RuntimeException('El PDF no tiene páginas.');
    }

    /** @return array{0:float,1:float} ancho y alto del MediaBox de la página */
    public function mediaBox(): array
    {
        preg_match('/\/MediaBox\s*\[\s*([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s+([\d.\-]+)\s*\]/', $this->paginaObjeto(), $m);

        return [(float) $m[3] - (float) $m[1], (float) $m[4] - (float) $m[2]];
    }

    private function diccionarioRecursos(): string
    {
        preg_match('/\/Resources\s+(\d+) 0 R/', $this->paginaObjeto(), $m);

        return $this->objetos[(int) $m[1]];
    }

    /**
     * Fuentes de la PÁGINA (no las de los XObjects importados).
     *
     * @return array<string,array{baseFont:string, subconjunto:bool, fontFile2:bool}> por nombre (F1, F2…)
     */
    public function fuentes(): array
    {
        preg_match('/\/Font\s*<<(.*?)>>/s', $this->diccionarioRecursos(), $bloque);
        preg_match_all('/\/(F\d+)\s+(\d+) 0 R/', $bloque[1] ?? '', $refs, PREG_SET_ORDER);

        $fuentes = [];
        foreach ($refs as $r) {
            $tipo0 = $this->objetos[(int) $r[2]];
            preg_match('/\/BaseFont\s*\/(\S+)/', $tipo0, $base);
            preg_match('/\/DescendantFonts\s*\[\s*(\d+) 0 R/', $tipo0, $desc);
            $cid = $this->objetos[(int) $desc[1]];
            preg_match('/\/FontDescriptor\s+(\d+) 0 R/', $cid, $fd);
            $descriptor = $this->objetos[(int) $fd[1]];

            $fuentes[$r[1]] = [
                'baseFont' => $base[1],
                'subconjunto' => preg_match('/^[A-Z]{6}\+/', $base[1]) === 1,
                'fontFile2' => str_contains($descriptor, '/FontFile2'),
            ];
        }

        return $fuentes;
    }

    /** @return array<int,array{nombre:string, subtype:string, contieneImagenes:bool}> */
    public function xobjetos(): array
    {
        preg_match('/\/XObject\s*<<(.*?)>>/s', $this->diccionarioRecursos(), $bloque);
        preg_match_all('/\/(\w+)\s+(\d+) 0 R/', $bloque[1] ?? '', $refs, PREG_SET_ORDER);

        $salida = [];
        foreach ($refs as $r) {
            $o = $this->objetos[(int) $r[2]];
            preg_match('/\/Subtype\s*\/(\w+)/', $o, $st);
            $salida[] = [
                'nombre' => $r[1],
                'subtype' => $st[1] ?? '',
                'contieneImagenes' => $this->streamDe($o) !== null && str_contains($this->streamDe($o), ' Do'),
            ];
        }

        return $salida;
    }

    /**
     * XObject de la plantilla importada: su /Matrix (null si no hay) y su contenido descomprimido.
     *
     * @return array{matrix:?array<int,float>, contenido:string}
     */
    public function plantilla(): array
    {
        preg_match('/\/TPL\d+\s+(\d+) 0 R/', $this->diccionarioRecursos(), $m); // la plantilla importada por FPDI
        $o = $this->objetos[(int) $m[1]];
        $matrix = null;
        if (preg_match('/\/Matrix\s*\[\s*([^\]]+)\]/', $o, $mm)) {
            $matrix = array_map('floatval', preg_split('/\s+/', trim($mm[1])));
        }

        return ['matrix' => $matrix, 'contenido' => $this->streamDe($o) ?? ''];
    }

    private function streamDe(string $objeto): ?string
    {
        if (! preg_match('/stream\r?\n/', $objeto, $m, PREG_OFFSET_CAPTURE)) {
            return null;
        }
        $datos = substr($objeto, $m[0][1] + strlen($m[0][0]));
        $datos = preg_replace('/\r?\nendstream\s*$/', '', $datos);
        $plano = @gzuncompress($datos);

        return $plano === false ? $datos : $plano;
    }

    private function contenidoPagina(): string
    {
        preg_match('/\/Contents\s+(\d+) 0 R/', $this->paginaObjeto(), $m);

        return $this->streamDe($this->objetos[(int) $m[1]]) ?? '';
    }

    /**
     * Textos escritos en la página, en orden: fuente (BaseFont), tamaño (Tf), x, baseline medida
     * desde ARRIBA (alto − y del operador Td) y el texto decodificado.
     *
     * @return array<int,array{baseFont:string, size:float, x:float, baseline:float, texto:string}>
     */
    public function textos(): array
    {
        [, $alto] = $this->mediaBox();
        $fuentes = $this->fuentes();
        $contenido = $this->contenidoPagina();

        $salida = [];
        $actual = ['nombre' => null, 'size' => 0.0];
        preg_match_all('/\/(F\d+)\s+([\d.]+)\s+Tf|BT\s+([\d.\-]+)\s+([\d.\-]+)\s+Td\s+\[\((.*?)\)\]\s*TJ/s', $contenido, $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            if (($op[1] ?? '') !== '') {
                $actual = ['nombre' => $op[1], 'size' => (float) $op[2]];

                continue;
            }
            $salida[] = [
                'baseFont' => $fuentes[$actual['nombre']]['baseFont'] ?? '?',
                'size' => $actual['size'],
                'x' => (float) $op[3],
                'baseline' => $alto - (float) $op[4],
                'texto' => self::decodificar($op[5]),
            ];
        }

        return $salida;
    }

    /**
     * Rectángulos rellenos (`re f`) del contenido de la página, en orden, con el color de relleno vigente y medidos
     * desde ARRIBA a la izquierda (pt): x, y (borde superior), ancho y alto positivos. Sirve para localizar el QR
     * vectorial (fondo blanco + módulos negros) sin decodificarlo.
     *
     * @return array<int,array{color:string, x:float, y:float, w:float, h:float}>
     */
    public function rectangulosRellenos(): array
    {
        [, $alto] = $this->mediaBox();
        $salida = [];
        $color = '0 0 0';
        preg_match_all('/([\d.]+) ([\d.]+) ([\d.]+) rg|([\d.\-]+) ([\d.\-]+) ([\d.\-]+) ([\d.\-]+) re f/', $this->contenidoPagina(), $ops, PREG_SET_ORDER);
        foreach ($ops as $op) {
            if (($op[1] ?? '') !== '') {
                $color = round((float) $op[1], 2).' '.round((float) $op[2], 2).' '.round((float) $op[3], 2);

                continue;
            }
            [$x, $y, $w, $h] = [(float) $op[4], (float) $op[5], (float) $op[6], (float) $op[7]];
            $salida[] = ['color' => $color, 'x' => $x, 'y' => $alto - max($y, $y + $h), 'w' => abs($w), 'h' => abs($h)];
        }

        return $salida;
    }

    /** Quita los escapes de una cadena PDF y la decodifica (códigos de 2 bytes = Unicode). */
    private static function decodificar(string $literal): string
    {
        $bytes = '';
        $n = strlen($literal);
        for ($i = 0; $i < $n; $i++) {
            $c = $literal[$i];
            if ($c !== '\\') {
                $bytes .= $c;

                continue;
            }
            $i++;
            $s = $literal[$i];
            if (ctype_digit($s)) {
                $oct = $s;
                while (strlen($oct) < 3 && $i + 1 < $n && ctype_digit($literal[$i + 1])) {
                    $oct .= $literal[++$i];
                }
                $bytes .= chr(octdec($oct) & 0xFF);
            } else {
                $bytes .= match ($s) {
                    'n' => "\n", 'r' => "\r", 't' => "\t", 'b' => "\x08", 'f' => "\x0C",
                    default => $s,
                };
            }
        }

        return mb_convert_encoding($bytes, 'UTF-8', 'UTF-16BE');
    }
}
