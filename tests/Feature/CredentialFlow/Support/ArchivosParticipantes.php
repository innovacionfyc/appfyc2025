<?php

namespace Tests\Feature\CredentialFlow\Support;

use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Writer\XLSX\Writer;
use ZipArchive;

/** Fixtures generados en los tests (sin binarios en el repositorio): XLSX con OpenSpout y CSV como texto. */
final class ArchivosParticipantes
{
    /**
     * Bytes de un .xlsx. Cada celda puede ser string|int|float|null, o ['f' => '=1+1'] para una fórmula.
     *
     * @param  array<int,array<int,mixed>>  $filas
     * @param  array<string,array<int,array<int,mixed>>>  $hojas  hojas adicionales por nombre
     */
    public static function xlsxBytes(array $filas, array $hojas = [], string $nombreHoja = 'Participantes'): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cft_');
        $writer = new Writer;
        $writer->openToFile($ruta);
        $writer->getCurrentSheet()->setName($nombreHoja);
        self::escribir($writer, $filas);
        foreach ($hojas as $nombre => $f) {
            $writer->addNewSheetAndMakeItCurrent()->setName($nombre);
            self::escribir($writer, $f);
        }
        $writer->close();
        $bytes = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    public static function xlsx(array $filas, string $nombre = 'participantes.xlsx', array $hojas = [], string $nombreHoja = 'Participantes'): UploadedFile
    {
        return self::subir(self::xlsxBytes($filas, $hojas, $nombreHoja), $nombre);
    }

    public static function subir(string $bytes, string $nombre): UploadedFile
    {
        return UploadedFile::fake()->createWithContent($nombre, $bytes);
    }

    /** @param  array<int,array<int,string>>  $filas */
    public static function csv(array $filas, string $delimitador = ',', string $codificacion = 'UTF-8', bool $bom = false, string $nombre = 'participantes.csv'): UploadedFile
    {
        $lineas = array_map(fn (array $f) => implode($delimitador, array_map(fn ($c) => str_contains((string) $c, $delimitador) || str_contains((string) $c, '"') ? '"'.str_replace('"', '""', (string) $c).'"' : (string) $c, $f)), $filas);
        $texto = implode("\r\n", $lineas)."\r\n";
        if ($codificacion !== 'UTF-8') {
            $texto = mb_convert_encoding($texto, $codificacion, 'UTF-8');
        }

        return self::subir(($bom ? "\xEF\xBB\xBF" : '').$texto, $nombre);
    }

    /** Reemplaza o agrega una entrada del ZIP de un xlsx. */
    public static function conEntrada(string $xlsxBytes, string $entrada, string $contenido): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cft_');
        file_put_contents($ruta, $xlsxBytes);
        $zip = new ZipArchive;
        $zip->open($ruta);
        $zip->addFromString($entrada, $contenido);
        $zip->close();
        $bytes = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    /** Reescribe [Content_Types].xml aplicando un reemplazo de texto. */
    public static function conTiposReemplazados(string $xlsxBytes, string $buscar, string $reemplazo): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cft_');
        file_put_contents($ruta, $xlsxBytes);
        $zip = new ZipArchive;
        $zip->open($ruta);
        $tipos = str_replace($buscar, $reemplazo, (string) $zip->getFromName('[Content_Types].xml'));
        $zip->addFromString('[Content_Types].xml', $tipos);
        $zip->close();
        $bytes = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    /** ZIP con las partes mínimas de un xlsx y una entrada enorme muy comprimible (zip bomb de prueba). */
    public static function zipBomb(int $bytesDescomprimidos): string
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cft_');
        $zip = new ZipArchive;
        $zip->open($ruta, ZipArchive::OVERWRITE);
        $zip->addFromString('[Content_Types].xml', '<Types/>');
        $zip->addFromString('xl/workbook.xml', '<workbook/>');
        $zip->addFromString('xl/worksheets/sheet1.xml', str_repeat('0', $bytesDescomprimidos));
        $zip->close();
        $bytes = (string) file_get_contents($ruta);
        @unlink($ruta);

        return $bytes;
    }

    /** @param  array<int,array<int,mixed>>  $filas */
    private static function escribir(Writer $writer, array $filas): void
    {
        foreach ($filas as $fila) {
            $celdas = array_map(fn ($v) => is_array($v) ? new FormulaCell($v['f'], $v['calculado'] ?? null) : Cell::fromValue($v), $fila);
            $writer->addRow(new Row($celdas));
        }
    }
}
