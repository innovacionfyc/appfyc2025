<?php

namespace App\Support\CredentialFlow\Participantes\Excel;

use DOMDocument;
use DOMElement;
use Throwable;
use XMLReader;
use ZipArchive;

/**
 * Lo que OpenSpout NO distingue de las fórmulas y que decide si un resultado es de fiar:
 *  - si el resultado guardado es un ERROR (#REF!, #N/A…): OpenSpout lo descarta y devuelve «sin valor»;
 *  - si la fórmula tiene un resultado guardado VACÍO (<v></v> de tipo texto) o NO tiene resultado alguno (sin <v>): para OpenSpout ambos son un texto vacío.
 * Sin esta diferencia una fórmula a la que simplemente le falta su resultado pasaría por «vacía comprobada».
 *
 * Se recorre el XML de cada hoja en flujo (XMLReader, sin red y sin sustitución de entidades) SOLO para leer, de las celdas con fórmula o con error, el tipo (t),
 * si traen <v> y si ese <v> está vacío. No se lee ni se guarda el texto de ninguna fórmula y nunca se ejecuta ni se resuelve nada. Los nombres de las partes
 * salen del propio libro (workbook.xml y sus relaciones) y se restringen a `xl/…`.
 */
final class MarcasXlsx
{
    public const MARCAS_MAX = 200000;

    /** Bits de `banderas`. */
    public const FORMULA = 1;

    public const CON_V = 2;

    public const VACIO_GUARDADO = 4;

    /** Clave compacta de una celda (fila y columna de Excel: hasta 16384 columnas). */
    public static function clave(int $fila, int $columna): int
    {
        return $fila * 16384 + $columna;
    }

    private const SIN_CELDAS = '-';

    private const NS_REL = 'http://schemas.openxmlformats.org/officeDocument/2006/relationships';

    /**
     * @return array<int, array{banderas: array<int,int>, errores: array<int,string>}>|null por posición de hoja → banderas por celda (clave compacta) y códigos de error; null si no se pudo verificar
     */
    public static function localizar(string $ruta): ?array
    {
        try {
            $partes = self::partesDeHojas($ruta);
            if ($partes === null) {
                return null;
            }
            $mapa = [];
            foreach ($partes as $i => $parte) {
                $mapa[$i] = self::marcasDe($ruta, $parte);
                if ($mapa[$i] === null) {
                    return null;   // una hoja que no se pudo inspeccionar invalida la verificación de todo el libro
                }
            }

            return $mapa;
        } catch (Throwable) {
            return null;
        }
    }

    /** @return list<string>|null nombre de la parte XML de cada hoja, en el orden del libro */
    private static function partesDeHojas(string $ruta): ?array
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            return null;
        }
        try {
            $libro = $zip->getFromName('xl/workbook.xml', 1048576);
            $rels = $zip->getFromName('xl/_rels/workbook.xml.rels', 1048576);
        } finally {
            $zip->close();
        }
        if (! is_string($libro) || ! is_string($rels)) {
            return null;
        }

        $usarInternos = libxml_use_internal_errors(true);
        try {
            $docRels = new DOMDocument;
            $docLibro = new DOMDocument;
            if (! $docRels->loadXML($rels, LIBXML_NONET) || ! $docLibro->loadXML($libro, LIBXML_NONET)) {
                return null;
            }
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($usarInternos);
        }

        $destinos = [];
        foreach ($docRels->getElementsByTagName('Relationship') as $r) {
            if ($r instanceof DOMElement) {
                $destinos[$r->getAttribute('Id')] = [$r->getAttribute('Target'), $r->getAttribute('Type')];
            }
        }
        $partes = [];
        foreach ($docLibro->getElementsByTagName('sheet') as $h) {
            if (! $h instanceof DOMElement) {
                continue;
            }
            [$destino, $tipo] = $destinos[$h->getAttributeNS(self::NS_REL, 'id')] ?? ['', ''];
            if ($destino === '') {
                $partes[] = '';

                continue;
            }
            // Hojas de gráfico u otros tipos: no tienen celdas; ocupan su posición pero no se inspeccionan.
            if (! str_ends_with($tipo, '/worksheet')) {
                $partes[] = self::SIN_CELDAS;

                continue;
            }
            $destino = ltrim(str_starts_with($destino, '/') ? $destino : 'xl/'.$destino, '/');
            // Solo partes dentro de xl/, sin recorridos de directorio.
            $partes[] = str_starts_with($destino, 'xl/') && ! str_contains($destino, '..') ? $destino : '';
        }

        return $partes;
    }

    /** @return array{banderas: array<int,int>, errores: array<int,string>}|null */
    private static function marcasDe(string $ruta, string $parte): ?array
    {
        if ($parte === self::SIN_CELDAS) {
            return ['banderas' => [], 'errores' => []];
        }
        if ($parte === '') {
            return null;
        }
        $lector = new XMLReader;
        if (! @$lector->open('zip://'.$ruta.'#'.$parte, null, LIBXML_NONET | LIBXML_COMPACT)) {
            return null;
        }
        $banderas = [];
        $errores = [];
        $actual = null;
        $fila = 0;
        $col = -1;
        try {
            while ($lector->read()) {
                $nodo = $lector->nodeType;
                $nombre = $lector->localName;

                if ($nodo === XMLReader::ELEMENT && $nombre === 'row') {
                    // El atributo r es opcional en el formato: sin él la fila es la siguiente a la anterior.
                    $r = (string) $lector->getAttribute('r');
                    $fila = ctype_digit($r) && $r !== '' ? (int) $r : $fila + 1;
                    $col = -1;

                    continue;
                }
                if ($nodo === XMLReader::ELEMENT && $nombre === 'c') {
                    // Igual para las celdas: sin r, ocupan la columna siguiente a la anterior de su fila.
                    $ref = (string) $lector->getAttribute('r');
                    $pos = self::posicion($ref) ?? [$fila, $col + 1];
                    $fila = $pos[0];
                    $col = $pos[1];
                    $actual = $lector->isEmptyElement ? null : ['pos' => $pos, 't' => (string) $lector->getAttribute('t'), 'f' => false, 'v' => false, 'texto' => ''];

                    continue;
                }
                if ($actual === null) {
                    continue;
                }
                if ($nodo === XMLReader::ELEMENT && $nombre === 'f') {
                    $actual['f'] = true;
                } elseif ($nodo === XMLReader::ELEMENT && $nombre === 'v') {
                    $actual['v'] = true;
                    if (! $lector->isEmptyElement) {
                        $actual['texto'] = substr($lector->readString(), 0, 64);
                    }
                } elseif ($nodo === XMLReader::END_ELEMENT && $nombre === 'c') {
                    $esError = $actual['t'] === 'e';
                    if (($actual['f'] || $esError) && count($banderas) < self::MARCAS_MAX) {
                        $k = self::clave($actual['pos'][0], $actual['pos'][1]);
                        // «Resultado vacío guardado»: trae <v>, sin texto, y es un texto (una fórmula que devolvió "").
                        $vacio = $actual['v'] && trim($actual['texto']) === '' && in_array($actual['t'], ['str', 'inlineStr', 's'], true);
                        $banderas[$k] = ($actual['f'] ? self::FORMULA : 0) | ($actual['v'] ? self::CON_V : 0) | ($vacio ? self::VACIO_GUARDADO : 0);
                        if ($esError) {
                            $errores[$k] = mb_substr(trim($actual['texto']), 0, 20) ?: '#ERROR';
                        }
                    }
                    $actual = null;
                }
            }
        } finally {
            $lector->close();
        }

        return ['banderas' => $banderas, 'errores' => $errores];
    }

    /** «D5» → [fila 5, columna 3] */
    private static function posicion(string $ref): ?array
    {
        if (preg_match('/^([A-Z]{1,3})(\d{1,7})$/', $ref, $m) !== 1) {
            return null;
        }
        $col = 0;
        foreach (str_split($m[1]) as $letra) {
            $col = $col * 26 + (ord($letra) - 64);
        }

        return [(int) $m[2], $col - 1];
    }
}
