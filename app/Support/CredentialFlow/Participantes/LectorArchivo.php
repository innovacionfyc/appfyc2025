<?php

namespace App\Support\CredentialFlow\Participantes;

use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Exception\EncodingConversionException;
use OpenSpout\Common\Exception\IOException;
use OpenSpout\Reader\CSV\Options as OpcionesCsv;
use OpenSpout\Reader\CSV\Reader as LectorCsv;
use OpenSpout\Reader\Exception\ReaderException;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options as OpcionesXlsx;
use OpenSpout\Reader\XLSX\Reader as LectorXlsx;
use Throwable;
use ZipArchive;

/**
 * Lee un .xlsx o .csv subido (en el backend, con OpenSpout) y devuelve filas de celdas crudas. No valida
 * el contenido de los participantes: solo comprueba que el ARCHIVO es seguro y legible.
 *
 * Seguridad (nada se ejecuta: ni macros ni fórmulas):
 *  - extensión permitida, tamaño, tipo MIME razonable y firma del contenido;
 *  - XLSX: firma ZIP, partes obligatorias, sin macros y límites de entradas, tamaño descomprimido y
 *    razón de compresión ANTES de abrirlo con OpenSpout (protección básica contra zip bombs);
 *  - las fórmulas de XLSX llegan marcadas (OpenSpout expone un FormulaCell) y se rechazan después;
 *  - se procesa solo el archivo temporal de la petición; el nombre original nunca se usa como ruta.
 */
final class LectorArchivo
{
    public const ARCHIVO_MAX_BYTES = 2 * 1024 * 1024;

    public const FILAS_MAX = 1000;

    public const COLUMNAS_MAX = 20;

    public const FILAS_EXPLORADAS_MAX = 5000;

    public const ZIP_ENTRADAS_MAX = 100;

    public const ZIP_DESCOMPRIMIDO_MAX = 25 * 1024 * 1024;

    public const ZIP_ENTRADA_MAX = 20 * 1024 * 1024;

    /** Razón de compresión máxima; solo se evalúa en entradas de más de 1 MB descomprimidas. */
    public const ZIP_RATIO_MAX = 100;

    private const MIME_XLSX = [
        'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
        'application/zip',
        'application/x-zip-compressed',
    ];

    private const MIME_CSV = ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel', 'application/x-empty', 'inode/x-empty'];

    /** @throws ErrorArchivoException */
    public function leer(UploadedFile $archivo): LecturaArchivo
    {
        if (! $archivo->isValid()) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'El archivo no se pudo subir completo. Inténtalo de nuevo.');
        }

        $extension = strtolower($archivo->getClientOriginalExtension());
        if (! in_array($extension, ['xlsx', 'csv'], true)) {
            throw new ErrorArchivoException(ErrorFila::EXTENSION_NO_PERMITIDA, 'Solo se admiten archivos .xlsx o .csv (no .xls, .xlsm, .xlsb ni .ods).');
        }

        $ruta = $archivo->getRealPath();
        if ($ruta === false || ! is_file($ruta)) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'No se pudo leer el archivo subido.');
        }
        if (filesize($ruta) > self::ARCHIVO_MAX_BYTES) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_MUY_GRANDE, 'El archivo supera los '.(self::ARCHIVO_MAX_BYTES / 1024 / 1024).' MB permitidos.');
        }

        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: '';

        return $extension === 'xlsx' ? $this->leerXlsx($ruta, $mime) : $this->leerCsv($ruta, $mime);
    }

    // ── XLSX ──────────────────────────────────────────────────────────────────

    private function leerXlsx(string $ruta, string $mime): LecturaArchivo
    {
        if (! in_array($mime, self::MIME_XLSX, true) || file_get_contents($ruta, false, null, 0, 4) !== "PK\x03\x04") {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'El archivo no es un libro de Excel .xlsx válido.');
        }

        $this->verificarZip($ruta);

        try {
            $lector = new LectorXlsx(new OpcionesXlsx(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
            $lector->open($ruta);
        } catch (Throwable) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'No se pudo abrir el libro de Excel: está dañado o no es un .xlsx válido.');
        }

        try {
            $hoja = null;
            foreach ($lector->getSheetIterator() as $candidata) {
                $visible = ! ($candidata instanceof SheetWithVisibilityInterface) || $candidata->isVisible();
                if ($candidata->getName() === 'Participantes' && $visible) {
                    $hoja = $candidata;
                    break;
                }
                if ($hoja === null && $visible) {
                    $hoja = $candidata;
                }
            }
            if ($hoja === null) {
                throw new ErrorArchivoException(ErrorFila::SIN_FILAS, 'El libro no tiene ninguna hoja visible con datos.');
            }

            return $this->recorrer($hoja->getRowIterator(), 'xlsx');
        } catch (ErrorArchivoException $e) {
            throw $e;
        } catch (IOException|ReaderException) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'No se pudo leer el libro de Excel: está dañado.');
        } finally {
            $lector->close();
        }
    }

    private function verificarZip(string $ruta): void
    {
        $zip = new ZipArchive;
        if ($zip->open($ruta, ZipArchive::RDONLY) !== true) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'El archivo .xlsx está dañado o no es un archivo ZIP válido.');
        }

        try {
            if ($zip->numFiles > self::ZIP_ENTRADAS_MAX) {
                throw new ErrorArchivoException(ErrorFila::ARCHIVO_SOSPECHOSO, 'El archivo tiene demasiadas partes internas para ser un libro de Excel normal.');
            }

            $total = 0;
            for ($i = 0; $i < $zip->numFiles; $i++) {
                $e = $zip->statIndex($i);
                if ($e === false) {
                    throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'El archivo .xlsx está dañado.');
                }
                if (preg_match('/vbaProject\.bin$/i', $e['name']) === 1) {
                    throw new ErrorArchivoException(ErrorFila::ARCHIVO_CON_MACROS, 'El libro contiene macros y no se admite. Guárdalo como .xlsx sin macros.');
                }
                $total += $e['size'];
                if ($e['size'] > self::ZIP_ENTRADA_MAX || $total > self::ZIP_DESCOMPRIMIDO_MAX) {
                    throw new ErrorArchivoException(ErrorFila::ARCHIVO_SOSPECHOSO, 'El contenido descomprimido del archivo es demasiado grande.');
                }
                if ($e['size'] > 1024 * 1024 && $e['comp_size'] > 0 && $e['size'] / $e['comp_size'] > self::ZIP_RATIO_MAX) {
                    throw new ErrorArchivoException(ErrorFila::ARCHIVO_SOSPECHOSO, 'El archivo tiene una razón de compresión anormal y no se procesará.');
                }
            }

            if ($zip->locateName('[Content_Types].xml') === false || $zip->locateName('xl/workbook.xml') === false) {
                throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'El archivo no es un libro de Excel .xlsx válido.');
            }
            $tipos = (string) $zip->getFromName('[Content_Types].xml', 1024 * 1024);
            if (stripos($tipos, 'macroEnabled') !== false) {
                throw new ErrorArchivoException(ErrorFila::ARCHIVO_CON_MACROS, 'El libro habilita macros y no se admite. Guárdalo como .xlsx sin macros.');
            }
        } finally {
            $zip->close();
        }
    }

    // ── CSV ───────────────────────────────────────────────────────────────────

    private function leerCsv(string $ruta, string $mime): LecturaArchivo
    {
        if (! in_array($mime, self::MIME_CSV, true)) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'El archivo no parece un CSV de texto.');
        }

        $contenido = (string) file_get_contents($ruta);
        if ($contenido === '') {
            throw new ErrorArchivoException(ErrorFila::SIN_FILAS, 'El archivo está vacío.');
        }
        if (str_contains($contenido, "\0")) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'El archivo no es un CSV de texto (por ejemplo, está en UTF-16). Guárdalo como «CSV UTF-8».');
        }

        $sinBom = str_starts_with($contenido, "\xEF\xBB\xBF") ? substr($contenido, 3) : $contenido;
        $codificacion = mb_check_encoding($sinBom, 'UTF-8') ? 'UTF-8' : 'WINDOWS-1252';
        $delimitador = $this->detectarDelimitador($sinBom);
        unset($contenido, $sinBom);

        try {
            $lector = new LectorCsv(new OpcionesCsv(SHOULD_PRESERVE_EMPTY_ROWS: true, FIELD_DELIMITER: $delimitador, ENCODING: $codificacion));
            $lector->open($ruta);
        } catch (Throwable) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'No se pudo abrir el archivo CSV.');
        }

        try {
            foreach ($lector->getSheetIterator() as $hoja) {
                return $this->recorrer($hoja->getRowIterator(), 'csv');
            }

            throw new ErrorArchivoException(ErrorFila::SIN_FILAS, 'El archivo no tiene filas.');
        } catch (ErrorArchivoException $e) {
            throw $e;
        } catch (EncodingConversionException) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_INVALIDO, 'No se pudo interpretar la codificación del CSV. Guárdalo como «CSV UTF-8».');
        } catch (IOException|ReaderException) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'No se pudo leer el archivo CSV.');
        } finally {
            $lector->close();
        }
    }

    /** Separador más frecuente (fuera de comillas) en la primera línea no vacía: `,`, `;` o tabulador. */
    private function detectarDelimitador(string $contenido): string
    {
        $linea = '';
        foreach (preg_split('/\r\n|\n|\r/', $contenido, 20) ?: [] as $candidata) {
            if (trim($candidata) !== '') {
                $linea = $candidata;
                break;
            }
        }

        $cuentas = [',' => 0, ';' => 0, "\t" => 0];
        $entreComillas = false;
        foreach (str_split($linea) as $c) {
            if ($c === '"') {
                $entreComillas = ! $entreComillas;
            } elseif (! $entreComillas && isset($cuentas[$c])) {
                $cuentas[$c]++;
            }
        }
        arsort($cuentas);

        return reset($cuentas) > 0 ? array_key_first($cuentas) : ',';
    }

    // ── Filas ─────────────────────────────────────────────────────────────────

    /**
     * Recorre filas con tope de exploración (sin recorrer hojas con miles de filas vacías) y de filas de datos.
     *
     * @param  iterable<Row>  $filas
     */
    private function recorrer(iterable $filas, string $formato): LecturaArchivo
    {
        $resultado = [];
        $numero = 0;

        foreach ($filas as $row) {
            $numero++;
            if ($numero > self::FILAS_EXPLORADAS_MAX + 1) {
                break;
            }

            $celdas = array_map(fn (Cell $c) => $this->celda($c), $row->cells);
            while ($celdas !== [] && end($celdas)->vacia()) {
                array_pop($celdas);
            }
            if ($celdas === []) {
                continue;
            }

            if ($numero > self::FILAS_EXPLORADAS_MAX) {
                throw new ErrorArchivoException(ErrorFila::DEMASIADAS_FILAS, 'El archivo tiene datos más allá de la fila '.self::FILAS_EXPLORADAS_MAX.'. Máximo '.self::FILAS_MAX.' participantes por archivo.');
            }
            if (count($celdas) > self::COLUMNAS_MAX) {
                throw new ErrorArchivoException(ErrorFila::DEMASIADAS_COLUMNAS, 'El archivo tiene más de '.self::COLUMNAS_MAX.' columnas con datos (fila '.$numero.').');
            }

            // Encabezado + FILAS_MAX filas de datos; una más ya excede el límite.
            if (count($resultado) > self::FILAS_MAX) {
                return new LecturaArchivo($formato, $resultado, excedeFilas: true);
            }
            $resultado[] = ['fila' => $numero, 'celdas' => $celdas];
        }

        return new LecturaArchivo($formato, $resultado, excedeFilas: count($resultado) > self::FILAS_MAX + 1);
    }

    private function celda(Cell $c): CeldaCruda
    {
        if ($c instanceof FormulaCell) {
            // Nunca se usa el valor calculado: una fórmula se rechaza.
            return new CeldaCruda(null, formula: true);
        }
        if ($c instanceof Cell\EmptyCell) {
            return new CeldaCruda(null);
        }

        return new CeldaCruda($c->getValue());
    }
}
