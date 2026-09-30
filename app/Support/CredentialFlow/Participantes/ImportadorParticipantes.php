<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * Validación (preview) e importación atómica de participantes desde un .xlsx/.csv.
 *
 * `validar()` procesa TODO el archivo y NO toca la base de datos. `importar()` vuelve a validar desde
 * cero (no confía en un preview anterior) y, solo si no hay un solo error, crea el lote y los
 * participantes dentro de UNA transacción.
 */
final class ImportadorParticipantes
{
    /** Tamaño de los INSERT masivos. */
    public const CHUNK = 200;

    /** Encabezados aceptados (ya normalizados con Texto::encabezado) → campo canónico. */
    private const ALIAS = [
        'nombre_completo' => ['nombrecompleto', 'nombre', 'nombres', 'nombresyapellidos', 'participante', 'asistente'],
        'documento' => ['documento', 'cedula', 'cc', 'identificacion', 'numerodedocumento', 'numerodocumento', 'doc'],
    ];

    /** Datos que se definen una sola vez en el formulario del lote; no pueden venir por fila. */
    private const NIVEL_LOTE = [
        'evento' => ['evento'],
        'fecha' => ['fecha'],
        'intensidad_horaria' => ['intensidadhoraria', 'intensidad'],
    ];

    public function __construct(private readonly LectorArchivo $lector) {}

    public function validar(UploadedFile $archivo, ?Plantilla $plantilla = null, array $datosComunes = []): ResultadoImportacion
    {
        try {
            $lectura = $this->lector->leer($archivo);
        } catch (ErrorArchivoException $e) {
            return ResultadoImportacion::deArchivo($e->comoError());
        }

        return $this->validarLectura($lectura, $plantilla, $datosComunes);
    }

    public function validarLectura(LecturaArchivo $lectura, ?Plantilla $plantilla = null, array $datosComunes = []): ResultadoImportacion
    {
        if ($lectura->filas === []) {
            return ResultadoImportacion::deArchivo(ErrorFila::error(ErrorFila::SIN_FILAS, 'El archivo no tiene filas.'));
        }
        if ($lectura->excedeFilas) {
            return ResultadoImportacion::deArchivo(ErrorFila::error(ErrorFila::DEMASIADAS_FILAS, 'El archivo tiene más de '.LectorArchivo::FILAS_MAX.' participantes.'));
        }

        // ── Encabezados ───────────────────────────────────────────────────────
        $errores = [];
        $avisos = [];
        $ignoradas = [];
        $columnas = []; // campo canónico => índice de columna
        $encabezado = $lectura->filas[0];

        foreach ($encabezado['celdas'] as $i => $celda) {
            $original = is_scalar($celda->valor) ? Texto::limpiar((string) $celda->valor) : '';
            if ($original === '') {
                continue;
            }
            $norm = Texto::encabezado($original);

            $canonico = $this->buscar(self::ALIAS, $norm);
            if ($canonico !== null) {
                if (isset($columnas[$canonico])) {
                    $errores[] = ErrorFila::error(ErrorFila::COLUMNA_DUPLICADA, "La columna «{$canonico}» aparece más de una vez en el encabezado.", $encabezado['fila'], $original);
                } else {
                    $columnas[$canonico] = $i;
                }

                continue;
            }
            if ($this->buscar(self::NIVEL_LOTE, $norm) !== null) {
                $errores[] = ErrorFila::error(ErrorFila::COLUMNA_NIVEL_LOTE, "La columna «{$original}» no va en el archivo: el evento, la fecha y la intensidad horaria se definen una sola vez en el formulario del lote.", $encabezado['fila'], $original);

                continue;
            }
            $ignoradas[] = $original;
            $avisos[] = ErrorFila::aviso(ErrorFila::COLUMNA_IGNORADA, "La columna «{$original}» no se reconoce y se ignora (no se guarda).", $encabezado['fila'], $original);
        }

        $faltantes = array_values(array_diff(array_keys(self::ALIAS), array_keys($columnas)));
        foreach ($faltantes as $campo) {
            $errores[] = ErrorFila::error(ErrorFila::COLUMNA_FALTANTE, "Falta la columna «{$campo}» en el encabezado.", $encabezado['fila'], $campo);
        }

        if ($errores !== []) {
            return new ResultadoImportacion(0, 0, 0, $errores, $avisos, $ignoradas, $faltantes, [], []);
        }

        // ── Filas ─────────────────────────────────────────────────────────────
        $datos = array_slice($lectura->filas, 1);
        if ($datos === []) {
            return ResultadoImportacion::deArchivo(ErrorFila::error(ErrorFila::SIN_FILAS, 'El archivo solo tiene el encabezado: no hay participantes.'));
        }

        $participantes = [];
        $preview = [];
        $conError = 0;
        $vistas = []; // documento_clave => fila
        $sinCaja = $this->elementosVariables($plantilla);

        foreach ($datos as $fila) {
            $r = ValidadorParticipante::validar(
                $fila['celdas'][$columnas['nombre_completo']] ?? new CeldaCruda,
                $fila['celdas'][$columnas['documento']] ?? new CeldaCruda,
                $fila['fila'],
            );
            $errFila = $r->errores;
            $avisos = [...$avisos, ...$r->avisos];

            if ($r->clave !== null) {
                if (isset($vistas[$r->clave])) {
                    $errFila[] = ErrorFila::error(ErrorFila::DOCUMENTO_DUPLICADO, "Documento duplicado: ya aparece en la fila {$vistas[$r->clave]}.", $fila['fila'], 'documento', $r->documento);
                } else {
                    $vistas[$r->clave] = $fila['fila'];
                }
            }

            if ($errFila !== []) {
                $conError++;
                $errores = [...$errores, ...$errFila];
            } else {
                $participantes[] = ['fila' => $fila['fila'], 'nombre_completo' => $r->nombre, 'documento' => $r->documento, 'documento_clave' => $r->clave];
                if ($sinCaja !== []) {
                    $avisos = [...$avisos, ...$this->avisosDeCaja($sinCaja, $plantilla, $r, $fila['fila'])];
                }
            }

            if (count($preview) < ResultadoImportacion::PREVIEW_FILAS) {
                $preview[] = ['fila' => $fila['fila'], 'nombre_completo' => $r->nombreVista, 'documento' => $r->documentoVista, 'valida' => $errFila === []];
            }
        }

        return new ResultadoImportacion(count($datos), count($participantes), $conError, $errores, $avisos, $ignoradas, [], $preview, $participantes);
    }

    /**
     * Valida de nuevo y, si todo es válido, crea el lote y los participantes en una transacción.
     *
     * @param  array<string,string>  $datosComunes  ya validados (ValidadorDatosComunes)
     *
     * @throws ImportacionInvalidaException
     */
    public function importar(UploadedFile $archivo, Plantilla $plantilla, string $nombre, ?string $descripcion, array $datosComunes): Lote
    {
        $resultado = $this->validar($archivo, $plantilla, $datosComunes);
        if (! $resultado->valido()) {
            throw new ImportacionInvalidaException($resultado);
        }

        $hash = hash_file('sha256', $archivo->getRealPath());
        $nombreArchivo = self::sanearNombre($archivo->getClientOriginalName());
        $usuario = Auth::id();

        return DB::transaction(function () use ($resultado, $plantilla, $nombre, $descripcion, $datosComunes, $hash, $nombreArchivo, $usuario) {
            $lote = Lote::create([
                'plantilla_id' => $plantilla->id,
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'datos_comunes' => $datosComunes,
                'archivo_nombre' => $nombreArchivo,
                'archivo_hash' => $hash,
            ]);

            $ahora = now();
            foreach (array_chunk($resultado->participantes, self::CHUNK) as $bloque) {
                // insert() no dispara HasAuditFields: created_by/update_by se rellenan a mano.
                DB::table('cf_participantes')->insert(array_map(fn (array $p) => [
                    'lote_id' => $lote->id,
                    'nombre_completo' => $p['nombre_completo'],
                    'documento' => $p['documento'],
                    'documento_clave' => $p['documento_clave'],
                    'fila_origen' => $p['fila'],
                    'created_by' => $usuario,
                    'update_by' => $usuario,
                    'created_at' => $ahora,
                    'updated_at' => $ahora,
                ], $bloque));
            }

            // Un solo Movimiento por importación, sin nombres, documentos ni contenido del archivo.
            Movimiento::registrar(
                tipo: 'registro',
                modulo: 'credential-flow',
                descripcion: "Se importó el lote de Credential Flow «{$lote->nombre}»",
                extra: [
                    'lote_id' => $lote->id,
                    'plantilla_id' => $plantilla->id,
                    'participantes_total' => count($resultado->participantes),
                ],
            );

            return $lote;
        });
    }

    /** Nombre del archivo apto para mostrar: sin ruta, sin controles, con caracteres seguros y ≤ 150. */
    public static function sanearNombre(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $nombre) ?? '';
        $nombre = trim($nombre);

        return mb_substr($nombre === '' ? 'archivo' : $nombre, 0, 150);
    }

    /** @param  array<string,array<int,string>>  $tabla */
    private function buscar(array $tabla, string $normalizado): ?string
    {
        foreach ($tabla as $campo => $alias) {
            if (in_array($normalizado, $alias, true)) {
                return $campo;
            }
        }

        return null;
    }

    // ── Aviso NO_CABE_PROBABLE ────────────────────────────────────────────────

    /**
     * Elementos del diseño que muestran datos de participante (nombre/documento): se evalúan con el
     * planificador para avisar si un valor probablemente no cabe. No es bloqueante en V1.
     *
     * @return array<int,array>
     */
    private function elementosVariables(?Plantilla $plantilla): array
    {
        $elementos = $plantilla?->diseno['elements'] ?? [];

        return array_values(array_filter($elementos, fn (array $e) => in_array($e['field'] ?? null, ['nombre_completo', 'documento'], true)));
    }

    /** @return array<int,ErrorFila> */
    private function avisosDeCaja(array $elementos, Plantilla $plantilla, ResultadoFila $r, int $fila): array
    {
        $avisos = [];
        $datos = DatosCredencial::fromArray(['nombre_completo' => $r->nombre, 'documento' => $r->documento]);
        $pagina = $plantilla->diseno['page'];

        foreach ($elementos as $el) {
            try {
                PlanificadorTexto::planificar(
                    ['page' => $pagina, 'elements' => [$el]],
                    $datos,
                    ['width' => (float) $pagina['width'], 'height' => (float) $pagina['height']],
                    (int) $plantilla->schema_version,
                );
            } catch (GeneracionCredencialException $e) {
                if ($e->codigo === GeneracionCredencialException::NO_CABE) {
                    $campo = $el['field'];
                    $avisos[] = ErrorFila::aviso(ErrorFila::NO_CABE_PROBABLE, 'Este valor probablemente no cabe en su caja de la plantilla, ni reduciendo el tamaño. Podrás ajustar el diseño o editar el participante.', $fila, $campo, $campo === 'nombre_completo' ? $r->nombre : $r->documento);
                }
                // Otros problemas de diseño (fuente heredada, etc.) se reportan al generar, no por participante.
            }
        }

        return $avisos;
    }
}
