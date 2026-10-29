<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
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

    /** Columnas OPCIONALES: si el archivo no las trae, la importación sigue como antes (bases antiguas sin correo). */
    private const ALIAS_OPCIONAL = [
        'correo' => ['correo', 'correoelectronico', 'correoelectronicoinstitucional', 'email', 'mail', 'emailaddress', 'direccioncorreo'],
        // Estado de certificación de cada persona: elegible | pendiente | no_elegible. Sin la columna, todos quedan «elegible» (como siempre).
        'elegibilidad' => ['elegibilidad', 'estadodecertificacion', 'estadocertificacion'],
        // Tipo de documento (C.C., C.E., T.I., pasaporte…). Si la columna existe y la celda está vacía (o dice «por verificar»), la persona se guarda con el tipo SIN CONFIRMAR.
        'tipo_documento' => ['tipodedocumento', 'tipodocumento', 'tipodoc', 'tipoid'],
    ];

    /** Datos que se definen una sola vez en el formulario del lote; no pueden venir por fila. */
    private const NIVEL_LOTE = [
        'evento' => ['evento'],
        'fecha' => ['fecha'],
        'intensidad_horaria' => ['intensidadhoraria', 'intensidad'],
    ];

    public function __construct(private readonly LectorArchivo $lector) {}

    /**
     * @param  array<int,string>|null  $dobleModalidad  solo en eventos autorizados (DobleModalidad): modalidad EFECTIVA normalizada de cada persona, indexada por
     *                                                  su número de FILA en el archivo (el mismo `fila` que se guarda como origen; nunca por posición). Una fila sin
     *                                                  modalidad cuenta como vacía y no puede repetir documento. null = la regla de siempre (un documento por base).
     */
    public function validar(UploadedFile $archivo, ?Plantilla $plantilla = null, array $datosComunes = [], ?array $dobleModalidad = null): ResultadoImportacion
    {
        try {
            $lectura = $this->lector->leer($archivo);
        } catch (ErrorArchivoException $e) {
            return ResultadoImportacion::deArchivo($e->comoError());
        }

        return $this->validarLectura($lectura, $plantilla, $datosComunes, $dobleModalidad);
    }

    /** @param  array<int,string>|null  $dobleModalidad  ver `validar` */
    public function validarLectura(LecturaArchivo $lectura, ?Plantilla $plantilla = null, array $datosComunes = [], ?array $dobleModalidad = null): ResultadoImportacion
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
            $opcional = $this->buscar(self::ALIAS_OPCIONAL, $norm);
            if ($opcional !== null) {
                if (isset($columnas[$opcional])) {
                    $errores[] = ErrorFila::error(ErrorFila::COLUMNA_DUPLICADA, "La columna «{$opcional}» aparece más de una vez en el encabezado.", $encabezado['fila'], $original);
                } else {
                    $columnas[$opcional] = $i;
                }

                continue;
            }
            if ($this->buscar(self::NIVEL_LOTE, $norm) !== null) {
                $errores[] = ErrorFila::error(ErrorFila::COLUMNA_NIVEL_LOTE, "La columna «{$original}» no va en el archivo: el evento, la fecha y la intensidad horaria se escriben una sola vez en el formulario de la base.", $encabezado['fila'], $original);

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
        $participaciones = []; // solo con doble modalidad: documento_clave => list<{fila, nombre, modalidad}>
        $sinCaja = $this->elementosVariables($plantilla);

        foreach ($datos as $fila) {
            $celdaDocumento = $fila['celdas'][$columnas['documento']] ?? new CeldaCruda;
            $tipo = isset($columnas['tipo_documento']) ? self::tipoDocumentoDe($fila['celdas'][$columnas['tipo_documento']] ?? new CeldaCruda, $fila['fila']) : ['tipo' => null, 'confirmado' => true, 'errores' => [], 'avisos' => []];
            // Con un tipo confirmado y un documento que es solo el número, se guardan juntos («C.C. 1.023.456.789»), como siempre se ha escrito en el certificado.
            if ($tipo['tipo'] !== null && ! $celdaDocumento->vacia() && ! $celdaDocumento->formula && is_scalar($celdaDocumento->valor) && ! self::yaTraeSuTipo((string) $celdaDocumento->valor, $tipo['tipo'])) {
                $celdaDocumento = CeldaCruda::texto($tipo['tipo'].' '.trim((string) $celdaDocumento->valor));
            }
            $r = ValidadorParticipante::validar(
                $fila['celdas'][$columnas['nombre_completo']] ?? new CeldaCruda,
                $celdaDocumento,
                $fila['fila'],
            );
            $errFila = $r->errores;
            $avisos = [...$avisos, ...$r->avisos];
            $correo = isset($columnas['correo']) ? ValidadorCorreo::validar($fila['celdas'][$columnas['correo']] ?? new CeldaCruda, $fila['fila']) : ['correos' => [], 'errores' => [], 'avisos' => [], 'vista' => ''];
            $errFila = [...$errFila, ...$correo['errores']];
            $avisos = [...$avisos, ...$correo['avisos']];
            $elegibilidad = isset($columnas['elegibilidad']) ? self::elegibilidadDe($fila['celdas'][$columnas['elegibilidad']] ?? new CeldaCruda, $fila['fila']) : ['valor' => Participante::ELEGIBLE, 'motivo' => null, 'errores' => [], 'avisos' => []];
            $errFila = [...$errFila, ...$elegibilidad['errores'], ...$tipo['errores']];
            $avisos = [...$avisos, ...$elegibilidad['avisos'], ...$tipo['avisos']];

            if ($r->clave !== null && $dobleModalidad === null) {
                if (isset($vistas[$r->clave])) {
                    $errFila[] = ErrorFila::error(ErrorFila::DOCUMENTO_DUPLICADO, "Documento duplicado: ya aparece en la fila {$vistas[$r->clave]}.", $fila['fila'], 'documento', $r->documento);
                } else {
                    $vistas[$r->clave] = $fila['fila'];
                }
            } elseif ($r->clave !== null) {
                // Evento autorizado: el mismo documento puede repetirse solo con otra modalidad y el mismo nombre (misma regla que el formulario).
                $modalidad = (string) ($dobleModalidad[$fila['fila']] ?? '');
                $otras = $participaciones[$r->clave] ?? [];
                $motivo = $otras === [] ? null : DobleModalidad::conflicto((string) $r->nombre, $modalidad, $otras);
                if ($motivo !== null) {
                    $errFila[] = ErrorFila::error(ErrorFila::DOCUMENTO_DUPLICADO, "Documento duplicado: ya aparece en la fila {$otras[0]['fila']} (".DobleModalidad::mensaje($motivo).').', $fila['fila'], 'documento', $r->documento);
                } else {
                    $participaciones[$r->clave][] = ['fila' => $fila['fila'], 'nombre' => (string) $r->nombre, 'modalidad' => $modalidad];
                }
            }

            if ($errFila !== []) {
                $conError++;
                $errores = [...$errores, ...$errFila];
            } else {
                $participantes[] = ['fila' => $fila['fila'], 'nombre_completo' => $r->nombre, 'documento' => $r->documento, 'documento_clave' => $r->clave, 'correos' => $correo['correos'], 'correo_columna' => isset($columnas['correo']), 'elegibilidad' => $elegibilidad['valor'], 'elegibilidad_motivo' => $elegibilidad['motivo'], 'elegibilidad_columna' => isset($columnas['elegibilidad']), 'tipo_documento_confirmado' => $tipo['confirmado'], 'tipo_columna' => isset($columnas['tipo_documento'])];
                if ($sinCaja !== []) {
                    $avisos = [...$avisos, ...$this->avisosDeCaja($sinCaja, $plantilla, $r, $fila['fila'])];
                }
            }

            if (count($preview) < ResultadoImportacion::PREVIEW_FILAS) {
                $preview[] = ['fila' => $fila['fila'], 'nombre_completo' => $r->nombreVista, 'documento' => $r->documentoVista, 'correo' => $correo['vista'], 'valida' => $errFila === []];
            }
        }

        return new ResultadoImportacion(count($datos), count($participantes), $conError, $errores, $avisos, $ignoradas, [], $preview, $participantes);
    }

    /**
     * Valida de nuevo y, si todo es válido, crea el lote y los participantes en una transacción.
     *
     * @param  array<string,string>  $datosComunes  ya validados (ValidadorDatosComunes)
     * @param  array<int,string>|null  $dobleModalidad  ver `validar`
     *
     * @throws ImportacionInvalidaException
     */
    public function importar(UploadedFile $archivo, Plantilla $plantilla, string $nombre, ?string $descripcion, array $datosComunes, ?int $eventoId = null, ?string $hashOriginal = null, ?string $nombreOriginal = null, ?array $dobleModalidad = null): Lote
    {
        $resultado = $this->validar($archivo, $plantilla, $datosComunes, $dobleModalidad);
        if (! $resultado->valido()) {
            throw new ImportacionInvalidaException($resultado);
        }

        // La importación inteligente valida un CSV revisado, generado desde el borrador, pero la base debe recordar el Excel ORIGINAL (para detectar el mismo archivo).
        $hash = $hashOriginal ?? hash_file('sha256', $archivo->getRealPath());
        $nombreArchivo = self::sanearNombre($nombreOriginal ?? $archivo->getClientOriginalName());
        $usuario = Auth::id();

        return DB::transaction(function () use ($resultado, $plantilla, $nombre, $descripcion, $datosComunes, $hash, $nombreArchivo, $usuario, $eventoId) {
            $lote = Lote::create([
                'plantilla_id' => $plantilla->id,
                ...($eventoId === null ? [] : ['evento_id' => $eventoId]),
                'nombre' => $nombre,
                'descripcion' => $descripcion,
                'datos_comunes' => $datosComunes,
                'archivo_nombre' => $nombreArchivo,
                'archivo_hash' => $hash,
            ]);

            $ahora = now();
            $conCorreo = 0;
            $conColumnaCorreo = ($resultado->participantes[0]['correo_columna'] ?? false) === true;   // igual para todo el archivo
            $conColumnaElegibilidad = ($resultado->participantes[0]['elegibilidad_columna'] ?? false) === true;
            $conColumnaTipo = ($resultado->participantes[0]['tipo_columna'] ?? false) === true;
            foreach (array_chunk($resultado->participantes, self::CHUNK) as $bloque) {
                // insert() no dispara HasAuditFields: created_by/update_by se rellenan a mano.
                DB::table('cf_participantes')->insert(array_map(function (array $p) use ($lote, $usuario, $ahora, $conColumnaCorreo, $conColumnaElegibilidad, $conColumnaTipo) {
                    // Mismo convenio que `Participante::CORREO_*`: un correo válido → se guarda en la fila; varios → `multiple` y la fuente son las filas de
                    // cf_correos; ninguno → `sin_correo` solo si el archivo traía la columna (las bases antiguas conservan NULL).
                    $validos = $p['correos'] ?? [];
                    $unico = count($validos) === 1 ? $validos[0] : null;

                    // Un archivo sin columna de correo (bases antiguas) se inserta exactamente como antes: ni siquiera toca las columnas de correo.
                    $columnasCorreo = ! $conColumnaCorreo ? [] : [
                        'correo' => $unico,
                        'correo_normalizado' => $unico === null ? null : Correo::normalizar($unico),
                        'correo_estado' => match (true) {
                            $unico !== null => Participante::CORREO_VALIDO,
                            count($validos) > 1 => Participante::CORREO_MULTIPLE,
                            default => Participante::CORREO_SIN_CORREO,
                        },
                    ];

                    return [
                        'lote_id' => $lote->id,
                        'nombre_completo' => $p['nombre_completo'],
                        'documento' => $p['documento'],
                        'documento_clave' => $p['documento_clave'],
                        ...$columnasCorreo,
                        ...(! $conColumnaElegibilidad ? [] : ['elegibilidad' => $p['elegibilidad'], 'elegibilidad_motivo' => $p['elegibilidad'] === Participante::ELEGIBLE ? null : ($p['elegibilidad_motivo'] ?? 'Indicado así en el archivo de importación.')]),
                        ...(! $conColumnaTipo ? [] : ['tipo_documento_confirmado' => $p['tipo_documento_confirmado']]),
                        'fila_origen' => $p['fila'],
                        'created_by' => $usuario,
                        'update_by' => $usuario,
                        'created_at' => $ahora,
                        'updated_at' => $ahora,
                    ];
                }, $bloque));

                $conCorreo += $this->guardarCorreos($lote, $bloque, $usuario, $ahora);
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
                    'participantes_con_correo' => $conCorreo,
                ],
            );

            return $lote;
        });
    }

    /**
     * Escribe en cf_correos los correos válidos de un bloque ya insertado (origen `credential_flow`, el primero como principal). Las columnas derivadas
     * (normalizado, estado) se calculan con las mismas funciones del modelo `Correo`, porque el insert masivo no dispara sus validaciones.
     *
     * @param  array<int,array<string,mixed>>  $bloque
     * @return int participantes del bloque con al menos un correo
     */
    private function guardarCorreos(Lote $lote, array $bloque, ?int $usuario, $ahora): int
    {
        $conCorreo = array_filter($bloque, fn (array $p) => ($p['correos'] ?? []) !== []);
        if ($conCorreo === []) {
            return 0;
        }
        $ids = DB::table('cf_participantes')->where('lote_id', $lote->id)->whereIn('fila_origen', array_column($conCorreo, 'fila'))->pluck('id', 'fila_origen');
        $filas = [];
        foreach ($conCorreo as $p) {
            foreach (array_values($p['correos']) as $i => $correo) {
                $normalizado = Correo::normalizar($correo);
                $filas[] = [
                    'participante_id' => $ids[$p['fila']], 'certificado_legado_id' => null, 'corrida_id' => null, 'correo' => $correo, 'correo_normalizado' => $normalizado,
                    'estado' => Correo::estadoDe($normalizado), 'orden' => $i + 1, 'es_principal' => $i === 0, 'origen' => Correo::ORIGEN_CREDENTIAL_FLOW,
                    'created_by' => $usuario, 'update_by' => $usuario, 'created_at' => $ahora, 'updated_at' => $ahora,
                ];
            }
        }
        foreach (array_chunk($filas, self::CHUNK) as $parte) {
            DB::table('cf_correos')->insert($parte);
        }

        return count($conCorreo);
    }

    /** Nombre del archivo apto para mostrar: sin ruta, sin controles, con caracteres seguros y ≤ 150. */
    public static function sanearNombre(string $nombre): string
    {
        $nombre = basename(str_replace('\\', '/', $nombre));
        $nombre = preg_replace('/[^\p{L}\p{N} ._()\-]/u', '_', $nombre) ?? '';
        $nombre = trim($nombre);

        return mb_substr($nombre === '' ? 'archivo' : $nombre, 0, 150);
    }

    /** ¿El documento escrito ya empieza por ese mismo tipo («C.C. 123», «cc123», «Pasaporte AB1»)? Entonces no se duplica. */
    private static function yaTraeSuTipo(string $documento, string $tipo): bool
    {
        $patron = match ($tipo) {
            'C.C.' => 'c\.?\s?c\.?',
            'C.E.' => 'c\.?\s?e\.?',
            'T.I.' => 't\.?\s?i\.?',
            'PASAPORTE' => 'pasaporte',
            default => preg_quote(mb_strtolower($tipo), '/'),
        };

        return preg_match('/^\s*'.$patron.'(?![\p{L}])/iu', $documento) === 1;
    }

    /**
     * Estado de certificación de la fila. NUNCA eleva a nadie: «elegible», «pendiente» y «no elegible» se leen tal cual; «Cupos» y cualquier otro texto (p. ej. el que
     * trae el formulario de inscripción) quedan «pendiente de validación» con un aviso, sin rechazar la carga y sin interpretarlos como asistencia completa.
     *
     * @return array{valor:string,motivo:?string,errores:list<ErrorFila>,avisos:list<ErrorFila>}
     */
    private static function elegibilidadDe(CeldaCruda $celda, int $fila): array
    {
        if ($celda->vacia()) {
            return ['valor' => Participante::PENDIENTE, 'motivo' => 'Sin estado en el archivo: pendiente de validación.', 'errores' => [], 'avisos' => []];
        }
        if ($celda->formula) {
            return ['valor' => Participante::PENDIENTE, 'motivo' => null, 'errores' => [ErrorFila::error(ErrorFila::ELEGIBILIDAD_INVALIDA, 'El estado de certificación no puede ser una fórmula.', $fila, 'elegibilidad')], 'avisos' => []];
        }
        $texto = Texto::limpiar((string) (is_scalar($celda->valor) ? $celda->valor : ''));
        $valor = match (Texto::encabezado($texto)) {
            'elegible' => Participante::ELEGIBLE,
            'pendiente', 'pendientedevalidacion' => Participante::PENDIENTE,
            'noelegible' => Participante::NO_ELEGIBLE,
            default => null,
        };
        if ($valor !== null) {
            return ['valor' => $valor, 'motivo' => $valor === Participante::ELEGIBLE ? null : 'Indicado así en el archivo de importación.', 'errores' => [], 'avisos' => []];
        }

        $corto = mb_substr($texto, 0, 40);
        $esCupos = Texto::encabezado($texto) === 'cupos';
        $motivo = $esCupos ? 'Cupos: asistencia pendiente de validación (no se asume asistencia completa ni horas).' : "Valor «{$corto}» del archivo sin interpretar: pendiente de validación.";

        return ['valor' => Participante::PENDIENTE, 'motivo' => $motivo, 'errores' => [], 'avisos' => [ErrorFila::aviso(ErrorFila::ELEGIBILIDAD_INVALIDA, "«{$corto}» no es un estado de certificación (elegible, pendiente o no elegible): la persona queda pendiente de validación y no se emitirá hasta confirmarla.", $fila, 'elegibilidad', $corto)]];
    }

    /**
     * Tipo de documento de la fila: un tipo reconocido (lo confirma), vacío o «por verificar» (queda SIN CONFIRMAR: se guarda y se valida después, sin suponer C.C.) o
     * un texto desconocido (error de la fila).
     *
     * @return array{tipo:?string,confirmado:bool,errores:list<ErrorFila>,avisos:list<ErrorFila>}
     */
    private static function tipoDocumentoDe(CeldaCruda $celda, int $fila): array
    {
        $sinConfirmar = fn () => ['tipo' => null, 'confirmado' => false, 'errores' => [], 'avisos' => [ErrorFila::aviso(ErrorFila::TIPO_DOCUMENTO_SIN_CONFIRMAR, 'Tipo de documento sin confirmar: la persona se guarda, pero no se emitirá hasta confirmarlo (no se supone C.C.).', $fila, 'tipo_documento')]];
        if ($celda->vacia()) {
            return $sinConfirmar();
        }
        if ($celda->formula) {
            return ['tipo' => null, 'confirmado' => false, 'errores' => [ErrorFila::error(ErrorFila::TIPO_DOCUMENTO_INVALIDO, 'El tipo de documento no puede ser una fórmula.', $fila, 'tipo_documento')], 'avisos' => []];
        }
        $texto = Texto::limpiar((string) (is_scalar($celda->valor) ? $celda->valor : ''));
        $tipo = match (Texto::encabezado($texto)) {
            'cc', 'cedula', 'cedulaciudadania', 'cedulasdeciudadania', 'cedulaasciudadania' => 'C.C.',
            'ce', 'cedulaextranjeria', 'cedulasdeextranjeria' => 'C.E.',
            'ti', 'tarjetaidentidad', 'tarjetadeidentidad' => 'T.I.',
            'pasaporte', 'pa' => 'PASAPORTE',
            'nit' => 'NIT',
            'pep' => 'PEP',
            'ppt' => 'PPT',
            'pendiente', 'porverificar', 'sinconfirmar', 'noconfirmado', 'verificar', 'sintipo' => '',
            default => null,
        };
        if ($tipo === '') {
            return $sinConfirmar();
        }
        if ($tipo === null) {
            return ['tipo' => null, 'confirmado' => false, 'errores' => [ErrorFila::error(ErrorFila::TIPO_DOCUMENTO_INVALIDO, 'Tipo de documento no reconocido. Usa C.C., C.E., T.I., Pasaporte, NIT, PEP o PPT; o déjalo vacío para confirmarlo después.', $fila, 'tipo_documento', mb_substr($texto, 0, 40))], 'avisos' => []];
        }

        return ['tipo' => $tipo, 'confirmado' => true, 'errores' => [], 'avisos' => []];
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
