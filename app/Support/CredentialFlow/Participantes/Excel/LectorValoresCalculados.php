<?php

namespace App\Support\CredentialFlow\Participantes\Excel;

use App\Support\CredentialFlow\Participantes\ErrorArchivoException;
use App\Support\CredentialFlow\Participantes\ErrorFila;
use App\Support\CredentialFlow\Participantes\LectorArchivo;
use DateInterval;
use DateTimeInterface;
use Illuminate\Http\UploadedFile;
use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Cell\EmptyCell;
use OpenSpout\Common\Entity\Cell\ErrorCell;
use OpenSpout\Common\Entity\Cell\FormulaCell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Reader\SheetWithVisibilityInterface;
use OpenSpout\Reader\XLSX\Options;
use OpenSpout\Reader\XLSX\Reader;
use Throwable;

/**
 * Lector AUXILIAR de libros .xlsx que resuelve el valor de TODAS las celdas, incluidas las fórmulas, sin evaluar jamás nada. Es independiente del lector del
 * importador vigente (`LectorArchivo`), que sigue rechazando fórmulas tal como hasta ahora; este lo prepara para el análisis inteligente de archivos como el formato
 * F-SC-DC-08 exportado de Google Sheets (todas sus celdas son fórmulas IMPORTRANGE con el último valor guardado).
 *
 * Para cada fórmula, por orden de confianza:
 *  1. el resultado GUARDADO en el archivo (lo que el programa de origen calculó);
 *  2. el valor literal de reserva de la envoltura IFERROR(__xludf.DUMMYFUNCTION(…), valor), con un analizador controlado (AnalizadorFormula);
 *  3. si nada de lo anterior existe: se distingue «vacía comprobada» (resultado vacío guardado, o fila sin ningún dato: relleno) de «sin valor recuperable»
 *     (la fila sí tiene datos y a esta celda le falta el suyo), y un resultado guardado que es un error se informa como error.
 *
 * Seguridad y límites: las mismas comprobaciones del importador (tamaño, macros, zip bombs…) vía `LectorArchivo::verificarXlsx`; nada se ejecuta (ni fórmulas,
 * ni referencias externas, ni macros); no se guarda el texto de las fórmulas; topes de hojas, filas, columnas, celdas, memoria y tiempo compatibles con 128 MB.
 */
final class LectorValoresCalculados
{
    public const HOJAS_MAX = 20;

    public const FILAS_MAX = 20000;

    public const COLUMNAS_MAX = 100;

    public const CELDAS_MAX = 60000;

    public const TEXTO_MAX = 5000;

    /** Memoria adicional máxima que puede consumir la lectura (producción: memory_limit de 128 MB). */
    public const MEMORIA_MAX_BYTES = 48 * 1048576;

    public const SEGUNDOS_MAX = 20;

    /** Los topes se pueden reducir (pruebas); nunca superar los de producción. */
    public function __construct(private readonly LectorArchivo $seguridad = new LectorArchivo, private readonly int $filasMax = self::FILAS_MAX, private readonly int $celdasMax = self::CELDAS_MAX) {}

    /** @throws ErrorArchivoException */
    public function leer(UploadedFile $archivo): LibroLeido
    {
        $ruta = $this->seguridad->verificarXlsx($archivo);
        $inicio = microtime(true);
        $memoria0 = memory_get_usage();

        $marcas = MarcasXlsx::localizar($ruta);

        try {
            $lector = new Reader(new Options(SHOULD_FORMAT_DATES: false, SHOULD_PRESERVE_EMPTY_ROWS: true));
            $lector->open($ruta);
        } catch (Throwable) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'No se pudo abrir el libro de Excel: está dañado o no es un .xlsx válido.');
        }

        $hojas = [];
        $celdasTotal = 0;
        try {
            $posicion = -1;
            foreach ($lector->getSheetIterator() as $hoja) {
                $posicion++;
                if ($posicion >= self::HOJAS_MAX) {
                    break;
                }
                $visible = ! ($hoja instanceof SheetWithVisibilityInterface) || $hoja->isVisible();
                $leida = new HojaLeida($hoja->getName(), $visible);
                $this->recorrer($hoja->getRowIterator(), $leida, $marcas === null ? null : ($marcas[$posicion] ?? []), $celdasTotal, $inicio, $memoria0);
                $this->resolverVaciasPorContexto($leida);
                $hojas[] = $leida;
            }
        } catch (ErrorArchivoException $e) {
            throw $e;
        } catch (Throwable) {
            throw new ErrorArchivoException(ErrorFila::ARCHIVO_CORRUPTO, 'No se pudo leer el libro de Excel: está dañado.');
        } finally {
            $lector->close();
        }
        if ($hojas === []) {
            throw new ErrorArchivoException(ErrorFila::SIN_FILAS, 'El libro no tiene ninguna hoja.');
        }

        return new LibroLeido($hojas, $marcas !== null, memory_get_peak_usage(), microtime(true) - $inicio);
    }

    /**
     * @param  iterable<Row>  $filas
     * @param  array{banderas: array<int,int>, errores: array<int,string>}|null  $marcas  null = no se pudo verificar el XML de las hojas
     */
    private function recorrer(iterable $filas, HojaLeida $hoja, ?array $marcas, int &$celdasTotal, float $inicio, int $memoria0): void
    {
        $n = 0;
        foreach ($filas as $fila) {
            $n++;
            $hoja->filasLeidas = $n;
            if ($n > min($this->filasMax, self::FILAS_MAX)) {
                $this->truncar($hoja, 'filas');

                return;
            }
            if ($n % 200 === 0) {
                if (microtime(true) - $inicio > self::SEGUNDOS_MAX) {
                    $this->truncar($hoja, 'tiempo');

                    return;
                }
                if (memory_get_usage() - $memoria0 > self::MEMORIA_MAX_BYTES) {
                    $this->truncar($hoja, 'memoria');

                    return;
                }
            }

            foreach ($fila->cells as $columna => $celda) {
                if ($columna >= self::COLUMNAS_MAX) {
                    $hoja->columnasRecortadas++;

                    break;
                }
                $resuelta = $this->resolver($celda, $n, $columna, $this->marcaDe($marcas, $n, $columna), $marcas !== null);
                if ($resuelta === null) {
                    continue;
                }
                if (++$celdasTotal > min($this->celdasMax, self::CELDAS_MAX)) {
                    $this->truncar($hoja, 'celdas');

                    return;
                }
                $hoja->poner($resuelta);
            }
        }
    }

    /**
     * @param  array{banderas: array<int,int>, errores: array<int,string>}|null  $marcas
     * @return array{codigo: ?string, formula: bool, v: bool, vacio: bool}|null
     */
    private function marcaDe(?array $marcas, int $fila, int $columna): ?array
    {
        $k = MarcasXlsx::clave($fila, $columna);
        $b = $marcas['banderas'][$k] ?? null;
        if ($b === null) {
            return null;
        }

        return [
            'codigo' => $marcas['errores'][$k] ?? null,
            'formula' => ($b & MarcasXlsx::FORMULA) !== 0,
            'v' => ($b & MarcasXlsx::CON_V) !== 0,
            'vacio' => ($b & MarcasXlsx::VACIO_GUARDADO) !== 0,
        ];
    }

    private function truncar(HojaLeida $hoja, string $motivo): void
    {
        $hoja->truncada = true;
        $hoja->motivoTruncada = $motivo;
    }

    /** @param  array{codigo: ?string, formula: bool, v: bool, vacio: bool}|null  $marca */
    private function resolver(Cell $celda, int $fila, int $columna, ?array $marca, bool $verificado): ?CeldaLeida
    {
        if ($celda instanceof EmptyCell) {
            return null;
        }
        if ($celda instanceof ErrorCell) {
            return new CeldaLeida($fila, $columna, null, CeldaLeida::ERROR, CeldaLeida::LITERAL, null, mb_substr($celda->getRawValue() !== '' ? $celda->getRawValue() : (string) $celda->getValue(), 0, 20));
        }
        if (! $celda instanceof FormulaCell) {
            $n = $this->normalizar($celda->getValue());

            return $n === null ? null : new CeldaLeida($fila, $columna, $n[1], $n[0], CeldaLeida::LITERAL);
        }

        // ── Fórmula: nunca se evalúa. Se usa el resultado guardado, o el valor de reserva de la envoltura de Google Sheets. ──
        $formula = $celda->getValue();
        $perfil = AnalizadorFormula::perfil($formula);
        $funcion = $perfil['funcion'];
        $ir = $perfil['importrange'];

        if ($marca !== null && $marca['codigo'] !== null) {
            return new CeldaLeida($fila, $columna, null, CeldaLeida::ERROR, CeldaLeida::FORMULA_ERROR, $funcion, $marca['codigo'], $ir);
        }

        $reserva = AnalizadorFormula::reserva($formula);
        $guardado = $this->normalizar($celda->getComputedValue());
        // OpenSpout devuelve '' tanto si el resultado guardado es vacío como si NO hay resultado: solo el XML (marcas) los distingue. Sin esa verificación
        // un resultado vacío NO se da por comprobado.
        $hayGuardado = $guardado !== null && $guardado[1] !== '' && $guardado[0] !== CeldaLeida::VACIA && ($marca === null ? $verificado === false : $marca['v']);
        $vacioGuardado = $verificado && $marca !== null && $marca['vacio'];

        if ($hayGuardado) {
            return new CeldaLeida($fila, $columna, $guardado[1], $guardado[0], CeldaLeida::FORMULA_GUARDADA, $funcion, null, $ir, $reserva !== null && $reserva['tipo'] !== 'vacio' && ! $this->iguales($guardado, $reserva['valor']));
        }
        if ($vacioGuardado) {
            return new CeldaLeida($fila, $columna, '', CeldaLeida::VACIA, CeldaLeida::FORMULA_VACIA, $funcion, null, $ir, $reserva !== null && $reserva['tipo'] !== 'vacio');
        }
        if ($reserva !== null) {
            if ($reserva['tipo'] === 'vacio') {
                return new CeldaLeida($fila, $columna, '', CeldaLeida::VACIA, CeldaLeida::FORMULA_VACIA, $funcion, null, $ir);
            }
            $tipo = match ($reserva['tipo']) {
                'numero' => CeldaLeida::NUMERO,
                'booleano' => CeldaLeida::BOOLEANO,
                default => CeldaLeida::TEXTO,
            };

            return new CeldaLeida($fila, $columna, $this->recortar($reserva['valor']), $tipo, CeldaLeida::FORMULA_RESERVA, $funcion, null, $ir);
        }

        return new CeldaLeida($fila, $columna, null, CeldaLeida::VACIA, CeldaLeida::FORMULA_SIN_VALOR, $funcion, null, $ir);
    }

    /**
     * Una fórmula sin resultado en una fila donde NO hay ningún dato es relleno de la plantilla (vacía comprobada por contexto); en una fila con datos es un
     * dato que no se pudo recuperar y se conserva como «sin valor».
     */
    private function resolverVaciasPorContexto(HojaLeida $hoja): void
    {
        foreach ($hoja->filas() as $fila) {
            $tieneSinValor = false;
            foreach ($hoja->fila($fila) as $c) {
                if ($c->origen === CeldaLeida::FORMULA_SIN_VALOR) {
                    $tieneSinValor = true;

                    break;
                }
            }
            if ($tieneSinValor && ! $hoja->filaConDatos($fila)) {
                $hoja->reemplazarFila($fila, fn (CeldaLeida $c) => $c->origen === CeldaLeida::FORMULA_SIN_VALOR ? $c->comoVaciaPorContexto() : $c);
            }
        }
    }

    /** @return array{0: string, 1: string|int|float|bool}|null [tipo, valor], o null si no hay valor */
    private function normalizar(mixed $v): ?array
    {
        if ($v === null) {
            return null;
        }
        if ($v instanceof DateTimeInterface) {
            return [CeldaLeida::FECHA, $v->format('H:i:s') === '00:00:00' ? $v->format('Y-m-d') : $v->format('Y-m-d H:i:s')];
        }
        if ($v instanceof DateInterval) {
            return [CeldaLeida::DURACION, $v->format('P%yY%mM%dDT%hH%iM%sS')];
        }
        if (is_bool($v)) {
            return [CeldaLeida::BOOLEANO, $v];
        }
        if (is_int($v) || is_float($v)) {
            return is_float($v) && ! is_finite($v) ? null : [CeldaLeida::NUMERO, $v];
        }
        if (is_string($v)) {
            $v = $this->recortar($v);

            return $v === '' ? [CeldaLeida::VACIA, ''] : [CeldaLeida::TEXTO, $v];
        }

        return null;
    }

    private function recortar(mixed $v): mixed
    {
        return is_string($v) && mb_strlen($v) > self::TEXTO_MAX ? mb_substr($v, 0, self::TEXTO_MAX) : $v;
    }

    /**
     * Compara el valor guardado con el de reserva: números con tolerancia, textos sin diferencias de saltos de línea y fechas contra su número de serie de Excel.
     *
     * @param  array{0: string, 1: mixed}  $guardado  [tipo, valor]
     */
    private function iguales(array $guardado, mixed $reserva): bool
    {
        [$tipo, $a] = $guardado;
        if ($tipo === CeldaLeida::FECHA && (is_int($reserva) || is_float($reserva))) {
            $dia = (new \DateTimeImmutable('1899-12-30'))->modify('+'.(int) floor($reserva).' days')->format('Y-m-d');

            return str_starts_with((string) $a, $dia);
        }
        if (is_numeric($a) && is_numeric($reserva)) {
            return abs((float) $a - (float) $reserva) < 1e-9;
        }
        $limpiar = fn ($x) => is_bool($x) ? ($x ? 'TRUE' : 'FALSE') : trim(str_replace("\r\n", "\n", (string) $x));

        return $limpiar($a) === $limpiar($reserva);
    }
}
