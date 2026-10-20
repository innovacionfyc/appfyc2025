<?php

namespace Tests\Feature\CredentialFlow\Support;

use ZipArchive;

/**
 * Constructor de libros .xlsx SINTÉTICOS a mano (XML mínimo) para probar lectores: permite lo que un escritor normal no deja hacer —fórmulas con o sin resultado
 * guardado, errores guardados, resultados vacíos, partes dañadas o macros—. Todo el contenido es ficticio.
 *
 * Una celda se describe así: ['v' => valor guardado|null, 't' => tipo OOXML (n|str|b|e|inlineStr), 'f' => texto de la fórmula|null, 's' => estilo (1 = fecha)].
 * Atajos: un string es texto en línea; un int/float es número; true/false es booleano.
 */
final class XlsxSintetico
{
    /**
     * @param  list<array{nombre: string, estado?: string, celdas: array<string, mixed>}>  $hojas
     * @param  array<string,string>  $extra  partes adicionales o sustitutas (nombre en el ZIP => contenido)
     */
    public static function libro(array $hojas, array $extra = []): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'xs_');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);

        $tipos = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Types xmlns="http://schemas.openxmlformats.org/package/2006/content-types"><Default Extension="rels" ContentType="application/vnd.openxmlformats-package.relationships+xml"/><Default Extension="xml" ContentType="application/xml"/><Override PartName="/xl/workbook.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.sheet.main+xml"/><Override PartName="/xl/styles.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.styles+xml"/>';
        $libro = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><workbook xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main" xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets>';
        $rels = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rIdStyles" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/styles" Target="styles.xml"/>';

        foreach ($hojas as $i => $hoja) {
            $n = $i + 1;
            $tipos .= '<Override PartName="/xl/worksheets/sheet'.$n.'.xml" ContentType="application/vnd.openxmlformats-officedocument.spreadsheetml.worksheet+xml"/>';
            $estado = ($hoja['estado'] ?? 'visible') === 'visible' ? '' : ' state="'.$hoja['estado'].'"';
            $libro .= '<sheet name="'.self::xml($hoja['nombre']).'" sheetId="'.$n.'"'.$estado.' r:id="rId'.$n.'"/>';
            $rels .= '<Relationship Id="rId'.$n.'" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/worksheet" Target="worksheets/sheet'.$n.'.xml"/>';
            $zip->addFromString('xl/worksheets/sheet'.$n.'.xml', self::hoja($hoja['celdas']));
        }
        $tipos .= '</Types>';
        $libro .= '</sheets></workbook>';
        $rels .= '</Relationships>';

        $zip->addFromString('[Content_Types].xml', $tipos);
        $zip->addFromString('_rels/.rels', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="http://schemas.openxmlformats.org/officeDocument/2006/relationships/officeDocument" Target="xl/workbook.xml"/></Relationships>');
        $zip->addFromString('xl/workbook.xml', $libro);
        $zip->addFromString('xl/_rels/workbook.xml.rels', $rels);
        $zip->addFromString('xl/styles.xml', '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><styleSheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><fonts count="1"><font><sz val="11"/></font></fonts><fills count="2"><fill><patternFill patternType="none"/></fill><fill><patternFill patternType="gray125"/></fill></fills><borders count="1"><border><left/><right/><top/><bottom/><diagonal/></border></borders><cellStyleXfs count="1"><xf numFmtId="0" fontId="0" fillId="0" borderId="0"/></cellStyleXfs><cellXfs count="2"><xf numFmtId="0" fontId="0" fillId="0" borderId="0" xfId="0"/><xf numFmtId="14" fontId="0" fillId="0" borderId="0" xfId="0" applyNumberFormat="1"/></cellXfs></styleSheet>');
        foreach ($extra as $nombre => $contenido) {
            $zip->addFromString($nombre, $contenido);
        }
        $zip->close();
        $bytes = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    /** @param  array<string, mixed>  $celdas  referencia («B3») => especificación */
    private static function hoja(array $celdas): string
    {
        $filas = [];
        foreach ($celdas as $ref => $spec) {
            preg_match('/^([A-Z]+)(\d+)$/', (string) $ref, $m);
            $col = 0;
            foreach (str_split($m[1]) as $l) {
                $col = $col * 26 + (ord($l) - 64);
            }
            $filas[(int) $m[2]][$col] = [$ref, $spec];
        }
        ksort($filas);
        $xml = '<?xml version="1.0" encoding="UTF-8" standalone="yes"?><worksheet xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"><sheetData>';
        foreach ($filas as $n => $columnas) {
            ksort($columnas);
            $xml .= '<row r="'.$n.'">';
            foreach ($columnas as [$ref, $spec]) {
                $xml .= self::celda((string) $ref, $spec);
            }
            $xml .= '</row>';
        }

        return $xml.'</sheetData></worksheet>';
    }

    private static function celda(string $ref, mixed $spec): string
    {
        if (! is_array($spec)) {
            $spec = match (true) {
                is_bool($spec) => ['t' => 'b', 'v' => $spec ? '1' : '0'],
                is_int($spec) || is_float($spec) => ['t' => 'n', 'v' => (string) $spec],
                default => ['t' => 'inlineStr', 'v' => (string) $spec],
            };
        }
        $t = $spec['t'] ?? (array_key_exists('v', $spec) && $spec['v'] !== null ? 'n' : null);
        $atributos = ' r="'.$ref.'"'.($t !== null ? ' t="'.$t.'"' : '').(isset($spec['s']) ? ' s="'.$spec['s'].'"' : '');
        $f = array_key_exists('f', $spec) && $spec['f'] !== null ? '<f>'.self::xml($spec['f']).'</f>' : '';
        if ($t === 'inlineStr' && $f === '') {
            return '<c'.$atributos.'><is><t xml:space="preserve">'.self::xml((string) ($spec['v'] ?? '')).'</t></is></c>';
        }
        // «v» => null: la celda no trae <v> (fórmula sin resultado guardado). «v» => '': trae <v></v> (resultado vacío guardado).
        $v = array_key_exists('v', $spec) && $spec['v'] !== null ? '<v>'.self::xml((string) $spec['v']).'</v>' : '';

        return '<c'.$atributos.'>'.$f.$v.'</c>';
    }

    private static function xml(string $texto): string
    {
        return htmlspecialchars($texto, ENT_XML1 | ENT_QUOTES, 'UTF-8');
    }
}
