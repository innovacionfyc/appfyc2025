<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\Conciliaciones\EvidenciaExterna;
use App\Support\CredentialFlow\Conciliaciones\GestionCaso;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CodigoEmision;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Emisiones\SnapshotCredencial;
use App\Support\CredentialFlow\Generacion\DatosCredencial;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Generacion\PlanificadorQr;
use App\Support\CredentialFlow\Generacion\PlanificadorTexto;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Participantes\ValidadorParticipante;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;
use Throwable;

/**
 * Reemplaza un certificado HISTÓRICO por una NUEVA emisión moderna (Fase 10B-2B-2A). El histórico es inmutable: no cambia su snapshot, nombre,
 * documento, código, descargas, PDF ni mapas; solo pasa a `reemplazado` y apunta a la emisión nueva (`reemplazado_por_emision_id`). La corrección
 * vive en la emisión (participante + snapshot) y la decisión, en la conciliación (regla + SHA-256 + longitud, nunca el valor en claro).
 *
 * El certificado origen SALE DEL CASO (nunca de un id libre). Todo es ATÓMICO, con este orden de locks (el mismo que el resto de conciliaciones):
 *   caso → certificado histórico → evento (serializa «buscar o crear» el lote) → lote → participante nuevo.
 *   1 lock del caso · 2 lock del histórico · 3 validar (regla, plantilla, campos, caracteres) · 4 buscar/crear el lote (evento + plantilla) ·
 *   5 crear el participante · 6–9 la MISMA maquinaria de `EmisorCredencial` (snapshot, render, archivo, emisión con `operacion` determinista) ·
 *   10–12 dentro de su transacción: enlazar el histórico, cerrar el caso y auditar · 13 commit.
 * Si algo falla, nada queda a medias y el archivo escrito se borra. (Render dentro de la transacción: aceptable para una acción administrativa
 * individual y rara; solo bloquea estas filas.) Una emisión creada nunca se borra: si fue un error se revoca o se reemite (cadena moderna).
 */
final class ReemplazoHistorico
{
    public const RESOLUCION = 'reemplazo_emitido';

    public const ACCION = 'certificado_historico_reemplazado';

    /** Marca del lote técnico en `datos_comunes.origen`. */
    public const ORIGEN_LOTE = 'reemplazo_historico';

    public const ROLES = ['super-admin', 'admin'];

    /** Casos que admiten reemplazo: el documento (10B-2B) y el conflicto de nombre DIF_NOMBRE con nombre aprobado (10B-3C-2). */
    public const TIPOS_ADMITIDOS = [Conciliacion::TIPO_REVISION_DOCUMENTO, Conciliacion::TIPO_CONFLICTO_VARIANTES];

    /** Prefijo del documento en el diseño → tipo de documento histórico que implica. */
    public const TIPO_POR_PREFIJO = ['C.C.' => 'CC', 'C.E.' => 'CE', 'T.I.' => 'TI'];

    private const COLUMNAS_CERT = ['id', 'evento_id', 'tipo_documento', 'documento', 'documento_clave', 'nombre_completo', 'estado', 'reemplazado_por_emision_id', 'revocado_at', 'conciliacion_estado'];

    private const NAMESPACE_OPERACION = 'credential-flow:reemplazo-historico:';

    public function __construct(private readonly EmisorCredencial $emisor) {}

    /** UUID v5 determinista por certificado histórico: el mismo reemplazo siempre produce la misma `operacion`. */
    public static function operacion(int $certificadoId): string
    {
        return Uuid::uuid5(Uuid::NAMESPACE_URL, self::NAMESPACE_OPERACION.$certificadoId)->toString();
    }

    /**
     * Evaluación SIN escribir: ¿se puede reemplazar con esta solicitud? Devuelve solo huellas de los valores (nunca el valor en claro).
     *
     * @return array<string,mixed>
     */
    public function evaluar(int $casoId, SolicitudReemplazo $s): array
    {
        $caso = Conciliacion::query()->find($casoId);
        if ($caso === null) {
            return ['aplicable' => false, 'bloqueos' => [['codigo' => ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'mensaje' => 'El caso no existe.']]];
        }
        $reglas = ReglasValorAprobado::reglasPara((string) $caso->motivo_origen);

        try {
            $this->exigirTipo($caso);
            $cert = $this->certificadoOrigen($caso, $s->certificadoId, false);
            $p = $this->preparar($caso, $cert, $s);
        } catch (ResolucionNoPermitida $e) {
            return ['aplicable' => false, 'reglas_documento' => $reglas, 'bloqueos' => [['codigo' => $e->codigo, 'mensaje' => $e->getMessage()]]];
        }

        return [
            'aplicable' => true, 'bloqueos' => [], 'reglas_documento' => $reglas, 'categoria' => $p['categoria'],
            'documento' => ['regla' => $p['regla_documento']] + ReglasValorAprobado::huella($p['documento']),
            'nombre' => ['regla' => $p['regla_nombre'], 'origen' => $p['origen_nombre']] + ReglasValorAprobado::huella($p['nombre']),
            'campos_usados' => $p['usados'], 'plantilla' => ['id' => (int) $p['plantilla']->id, 'es_clon_historico' => $p['plantilla']->origen_legado_sha256 !== null, 'advertencias' => (array) ($p['plantilla']->origen_legado_meta['advertencias'] ?? [])],
        ];
    }

    /**
     * PDF de previsualización con los datos aprobados y la plantilla elegida: no persiste NADA (ni emisión, ni participante, ni lote, ni código).
     * El QR, si lo hay, lleva un código de muestra que no existe.
     *
     * @throws ResolucionNoPermitida
     */
    public function previsualizar(int $casoId, SolicitudReemplazo $s): string
    {
        $caso = Conciliacion::query()->findOrFail($casoId);
        $this->exigirTipo($caso);
        $cert = $this->certificadoOrigen($caso, $s->certificadoId, false);
        $p = $this->preparar($caso, $cert, $s);

        $url = DisenoSchema::tieneQr($p['diseno']) ? UrlVerificacion::para(CodigoEmision::generar()) : null;

        try {
            return GeneradorCredencialPdf::generarDesde($p['diseno'], $p['base'], $p['datos'], (int) $p['plantilla']->schema_version, ['reemplazo' => 'preview'], $url);
        } catch (GeneracionCredencialException $e) {
            throw $this->mapear($e);
        }
    }

    /**
     * @return array{caso_id:int,certificado_id:int,emision_id:int,codigo_emision:string,participante_id:int,lote_id:int,plantilla_id:int,lote_creado:bool,operacion:string}
     *
     * @throws ResolucionNoPermitida
     */
    public function reemplazar(int $casoId, int $actorId, string $motivo, SolicitudReemplazo $s, ?string $huellaPreview = null): array
    {
        $motivo = $this->motivoValido($motivo);
        $this->actor($actorId);

        $escrito = null;
        try {
            return DB::transaction(function () use ($casoId, $actorId, $motivo, $s, $huellaPreview, &$escrito) {
                // 1–2. Locks, en el orden obligatorio: caso → pivote → evento → TODAS las filas del certificado lógico (por id). El evento va antes de
                // cualquier lectura sin lock: en MySQL (REPEATABLE READ) la primera lectura normal fija el snapshot de la transacción y, si se hiciera
                // antes de esperar este lock, no se vería el lote que otro proceso acaba de confirmar. Tomar el evento y luego las filas del grupo en orden
                // creciente evita el interbloqueo entre quien entra por la raíz y quien entra por una variante.
                $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
                $this->exigirTipo($caso);
                $id = $this->idDelCaso($caso, $s->certificadoId, true);
                // El evento del caso se bloquea ANTES de leer nada del certificado (una lectura normal fijaría el snapshot); todo el que reemplaza en este
                // evento pasa por este lock, así que las filas del grupo se bloquean después sin riesgo de interbloqueo.
                if ($caso->evento_id !== null) {
                    DB::table('cf_eventos')->where('id', $caso->evento_id)->lockForUpdate()->value('id');
                }
                $meta = DB::table('cf_certificados_legado')->where('id', $id)->lockForUpdate()->first(['evento_id', 'grupo_duplicado']);
                if ($meta === null) {
                    throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'El certificado del caso ya no existe.');
                }
                if ($caso->evento_id === null || (int) $caso->evento_id !== (int) $meta->evento_id) {
                    DB::table('cf_eventos')->where('id', $meta->evento_id)->lockForUpdate()->value('id');
                }
                $logico = CertificadoLogico::deGrupo($id, $meta->grupo_duplicado === null ? null : (string) $meta->grupo_duplicado);
                $filas = DB::table('cf_certificados_legado')->whereIn('id', $logico['miembros'])->orderBy('id')->lockForUpdate()->get(self::COLUMNAS_CERT)->keyBy('id');
                if ($caso->tipo === Conciliacion::TIPO_CONFLICTO_VARIANTES) {
                    // DIF_NOMBRE: el caso agrupa las variantes de UN certificado lógico; el reemplazo lo lleva su raíz (ya bloqueada con todas las filas del grupo).
                    $id = $this->raizDelCaso($caso, $logico);
                }
                $cert = $filas->get($id) ?? throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'El certificado del caso ya no existe.');

                // «Ya reemplazado» (él mismo, o su certificado lógico) y «variante» se comprueban ANTES que el estado del caso: es el mensaje que debe ver el
                // segundo administrador.
                $this->exigirRaizLogica($cert, $logico, $filas);

                $operacion = self::operacion((int) $cert->id);
                if (DB::table('cf_emisiones')->where('operacion', $operacion)->exists()) {
                    throw new ResolucionNoPermitida(ResolucionNoPermitida::OPERACION_INCONSISTENTE, 'Existe una emisión de esta operación sin enlace con el histórico: requiere revisión técnica.');
                }

                // 3. Validación completa (aquí sí se exige que el diseño del clon esté confirmado) y, si viene de la pantalla, que la vista previa
                // corresponda EXACTAMENTE a los datos actuales (revalidado bajo lock).
                $p = $this->preparar($caso, $cert, $s, true);
                if ($huellaPreview !== null && ! hash_equals($this->calcularHuella($caso, $cert, $p), $huellaPreview)) {
                    throw new ResolucionNoPermitida(ResolucionNoPermitida::PREVIEW_DESACTUALIZADO, 'Los datos cambiaron. Genere nuevamente la vista previa.');
                }

                // 4. Lote (evento + plantilla), bajo el lock del evento.
                [$lote, $loteCreado] = $this->lote($cert, $p, $actorId);

                // 5. Participante moderno (sin correo: no se copia nada personal que no haga falta).
                $participante = new Participante([
                    'lote_id' => $lote->id, 'nombre_completo' => $p['nombre'], 'documento' => $p['documento'], 'documento_clave' => Texto::claveDocumento($p['documento']),
                ]);
                $participante->forceFill(['created_by' => $actorId, 'update_by' => $actorId])->save();

                // 6–12. La misma maquinaria de emisión; el gancho (dentro de su transacción) enlaza, cierra y audita.
                $emision = $this->emisor->emitirParaReemplazo($participante, $lote, $actorId, $operacion, function ($emision) use ($caso, $cert, $actorId, $motivo, $s, $p, $lote, $participante, $loteCreado, $operacion, &$escrito) {
                    $escrito = (string) $emision->pdf_archivo;
                    $ahora = now();

                    $enlazados = DB::table('cf_certificados_legado')->where('id', $cert->id)->where('estado', 'vigente')->whereNull('reemplazado_por_emision_id')
                        ->update(['estado' => 'reemplazado', 'reemplazado_por_emision_id' => $emision->id, 'update_by' => $actorId, 'updated_at' => $ahora]);
                    if ($enlazados !== 1) {
                        throw new ResolucionNoPermitida(ResolucionNoPermitida::YA_REEMPLAZADO, 'Este certificado ya fue reemplazado.');
                    }

                    $caso->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => self::RESOLUCION, 'resuelto_por' => $actorId, 'resuelto_at' => $ahora]);

                    $evidencia = [
                        'certificado_historico_id' => (int) $cert->id, 'emision_id' => (int) $emision->id, 'participante_id' => (int) $participante->id, 'lote_id' => (int) $lote->id,
                        'plantilla_id' => (int) $p['plantilla']->id, 'plantilla_origen' => $p['plantilla']->origen_legado_sha256 !== null ? 'clon_historica' : 'existente',
                        'lote_creado' => $loteCreado, 'operacion' => $operacion, 'categoria' => $p['categoria'],
                        'documento' => ['regla' => $p['regla_documento']] + ReglasValorAprobado::huella($p['documento']),
                        'nombre' => ['regla' => $p['regla_nombre']] + ReglasValorAprobado::huella($p['nombre']),
                        'confirmado' => $s->confirmado, 'evidencia_manual' => $s->evidencia === null ? null : ReglasValorAprobado::huella(trim($s->evidencia)),
                        'campos_usados' => $p['usados'], 'fecha_aportada' => in_array('fecha', $p['usados'], true), 'intensidad_aportada' => in_array('intensidad_horaria', $p['usados'], true),
                    ];
                    if ($p['categoria'] === ReglasValorAprobado::CAT_DIF_NOMBRE) {
                        // Sin nombres en claro: el nombre aprobado solo deja SHA-256 + longitud, su origen (variante histórica exacta o externo con evidencia) y los ids cubiertos.
                        $evidencia['caso_tipo'] = (string) $caso->tipo;
                        $evidencia['nombre_aprobado'] = ['origen' => $p['origen_nombre'], 'variante_historica_id' => $p['variante_id']] + ReglasValorAprobado::huella($p['nombre']);
                        $evidencia['certificado_reemplazado_id'] = (int) $cert->id;
                        $evidencia['certificados_del_caso'] = $p['cubiertos'];
                        $evidencia['variantes_cubiertas'] = array_values(array_diff($p['cubiertos'], [(int) $cert->id]));
                        $evidencia['identidad_independiente'] = self::identidadDe((string) $cert->documento_clave);
                    }
                    ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCION, 'estado_anterior' => Conciliacion::ABIERTO, 'estado_nuevo' => Conciliacion::RESUELTO, 'motivo' => $motivo, 'actor_id' => $actorId, 'evidencia' => $evidencia]);

                    // Sin IP ni agente de usuario: solo ids.
                    Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$caso->id}: certificado histórico reemplazado por la emisión #{$emision->id}", 'metadata' => ['caso_id' => (int) $caso->id, 'emision_id' => (int) $emision->id]]);

                    // Los casos de las VARIANTES del mismo certificado lógico, si son estrictamente equivalentes, quedan cubiertos por este reemplazo.
                    (new CoberturaCanonica)->cubrir((int) $cert->id, $actorId);
                });

                return [
                    'caso_id' => (int) $caso->id, 'certificado_id' => (int) $cert->id, 'emision_id' => (int) $emision->id, 'codigo_emision' => (string) $emision->codigo,
                    'participante_id' => (int) $participante->id, 'lote_id' => (int) $lote->id, 'plantilla_id' => (int) $p['plantilla']->id, 'lote_creado' => $loteCreado, 'operacion' => $operacion,
                ];
            });
        } catch (Throwable $e) {
            // Si el archivo llegó a escribirse y la transacción exterior no se confirmó, no queda huérfano.
            if ($escrito !== null && $escrito !== '') {
                AlmacenEmisiones::borrar($escrito);
            }
            if ($e instanceof GeneracionCredencialException) {
                throw $this->mapear($e);
            }
            // Quien llegó por una variante mientras otro proceso reemplazaba la raíz: su caso no pudo cerrarse dentro de esa transacción (se omite si estaba
            // retenido). Ahora, ya libre, se barre (idempotente, con revalidación estricta); un fallo aquí nunca altera el rechazo.
            if ($e instanceof ResolucionNoPermitida && $e->codigo === ResolucionNoPermitida::YA_REEMPLAZADO_LOGICAMENTE) {
                try {
                    $idCert = $this->idDelCaso(Conciliacion::query()->findOrFail($casoId), $s->certificadoId, false);
                    (new CoberturaCanonica)->cubrir($idCert, null, false);
                } catch (Throwable) {
                    // best-effort
                }
            }

            throw $e;
        }
    }

    // ── Validación ────────────────────────────────────────────────────────────

    /**
     * Todo lo que se necesita para emitir, ya validado. No escribe nada.
     *
     * @return array<string,mixed>
     *
     * @throws ResolucionNoPermitida
     */
    private function preparar(Conciliacion $caso, object $cert, SolicitudReemplazo $s, bool $exigirDisenoConfirmado = false): array
    {
        $this->exigirTipo($caso);
        $this->exigirRaizLogica($cert);
        if ($caso->estado !== Conciliacion::ABIERTO) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::CASO_YA_RESUELTO, 'Este caso ya fue resuelto.');
        }
        if ($cert->estado !== 'vigente' || $cert->revocado_at !== null) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::ESTADO_HISTORICO_INVALIDO, 'El certificado histórico no está vigente.');
        }

        $categoria = (string) $caso->motivo_origen;
        // 10B-3C-4: un documento VACÍO solo se corrige con el documento correcto aportado desde fuera y REGISTRADO como evidencia externa vigente (nunca se infiere).
        if ($categoria === 'DOC_VACIO' && ! EvidenciaExterna::tieneVigente((int) $caso->id, 'documento_correcto')) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::EVIDENCIA_REQUERIDA, 'Registra primero la evidencia externa del documento correcto (fuente «documento correcto aportado»).');
        }
        $dif = $categoria === ReglasValorAprobado::CAT_DIF_NOMBRE ? $this->contextoDifNombre($caso, $cert) : null;
        $doc = ReglasValorAprobado::documento($s, $cert, $categoria);
        $nom = ReglasValorAprobado::nombre($s, $cert, $categoria, $dif['variantes'] ?? []);

        $plantilla = Plantilla::query()->find($s->plantillaId);
        if ($plantilla === null) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La plantilla moderna no existe o fue eliminada.');
        }
        try {
            [$base] = SnapshotCredencial::cargarPlantilla($plantilla);
        } catch (GeneracionCredencialException $e) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, $e->getMessage());
        }
        $diseno = $plantilla->diseno;
        if (! is_array($diseno) || ! isset($diseno['page'], $diseno['elements'])) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La plantilla no tiene un diseño guardado.');
        }

        if ($exigirDisenoConfirmado && $plantilla->origen_legado_sha256 !== null && ! CreadorPlantillaReemplazo::disenoConfirmado($plantilla)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::DISENO_NO_CONFIRMADO, 'El diseño de la plantilla moderna todavía no fue revisado y confirmado para emitir reemplazos.');
        }

        $usados = $this->camposUsados($diseno);
        if (! in_array('nombre_completo', $usados, true) || ! in_array('documento', $usados, true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La plantilla no imprime el nombre y el documento.');
        }
        $this->comprobarPrefijo($diseno, (string) $cert->tipo_documento);

        $evento = (string) DB::table('cf_eventos')->where('id', $cert->evento_id)->value('nombre');
        if (in_array('evento', $usados, true) && (mb_strlen($evento) > CamposDinamicos::todos()['evento']['maxLongitud'] || ValidadorParticipante::noSoportados($evento) !== [])) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::DATO_NO_IMPRIMIBLE, 'El nombre del evento tiene caracteres que la fuente de la plantilla no puede imprimir.');
        }
        // Fecha e intensidad: nunca se inventan. Si la plantilla las imprime, deben aportarse explícitamente; si no, quedan vacías.
        $fecha = in_array('fecha', $usados, true) ? $this->datoComun('fecha', $s->fecha) : '';
        $intensidad = in_array('intensidad_horaria', $usados, true) ? $this->datoComun('intensidad_horaria', $s->intensidadHoraria) : '';

        try {
            $datos = DatosCredencial::fromArray(['nombre_completo' => $nom['valor'], 'documento' => $doc['valor'], 'evento' => $evento, 'fecha' => $fecha, 'intensidad_horaria' => $intensidad]);
            $pagina = GeneradorCredencialPdf::tamanoPagina($base, ['plantilla' => $plantilla->id]);
            PlanificadorTexto::planificar($diseno, $datos, $pagina, (int) $plantilla->schema_version);
            PlanificadorQr::planificar($diseno, $pagina, (int) $plantilla->schema_version);
        } catch (GeneracionCredencialException $e) {
            throw $this->mapear($e);
        }

        return [
            'categoria' => $categoria, 'regla_documento' => $doc['regla'], 'documento' => $doc['valor'], 'regla_nombre' => $nom['regla'], 'nombre' => $nom['valor'],
            'origen_nombre' => $nom['origen'], 'variante_id' => $nom['variante_id'], 'cubiertos' => $dif['ids'] ?? [(int) $cert->id], 'variantes_huella' => $dif['huella'] ?? null,
            'evidencia_huella' => $s->evidencia === null ? null : hash('sha256', trim($s->evidencia)), 'confirmado' => $s->confirmado,
            'plantilla' => $plantilla, 'base' => $base, 'diseno' => $diseno, 'usados' => $usados, 'datos' => $datos,
            'evento_nombre' => $evento, 'datos_comunes' => ['evento' => $evento, 'fecha' => $fecha, 'intensidad_horaria' => $intensidad],
        ];
    }

    /**
     * ¿Se ofrece «Emitir certificado corregido» para este caso? (sin escribir). Solo casos documentales abiertos cuyo certificado siga vigente y sin
     * reemplazar; los DOC_LETRAS de texto, los de soporte/descarte, DOC_VACIO, nombres reales e identidad ambigua NO lo son.
     *
     * @return array{elegible:bool,motivo:?string,codigo:?string}
     */
    public function elegible(Conciliacion $caso): array
    {
        try {
            $this->exigirTipo($caso);
            $cert = $this->certificadoOrigen($caso, null, false);
        } catch (ResolucionNoPermitida $e) {
            return ['elegible' => false, 'motivo' => $e->getMessage(), 'codigo' => $e->codigo];
        }
        // «Ya reemplazado» va ANTES que «caso resuelto»: es el mensaje que debe ver quien llega segundo (el caso también quedó cerrado).
        try {
            $this->exigirRaizLogica($cert);
        } catch (ResolucionNoPermitida $e) {
            return ['elegible' => false, 'motivo' => $e->getMessage(), 'codigo' => $e->codigo];
        }
        if ($caso->estado !== Conciliacion::ABIERTO) {
            return ['elegible' => false, 'motivo' => 'Este caso ya fue resuelto.', 'codigo' => ResolucionNoPermitida::CASO_YA_RESUELTO];
        }
        if ($caso->tipo === Conciliacion::TIPO_CONFLICTO_VARIANTES) {
            // DIF_NOMBRE: el soporte es la alternativa por defecto, no un bloqueo; sí debe tratarse de nombres realmente distintos y de un solo certificado lógico.
            try {
                $this->contextoDifNombre($caso, $cert);
            } catch (ResolucionNoPermitida $e) {
                return ['elegible' => false, 'motivo' => $e->getMessage(), 'codigo' => $e->codigo];
            }
        } elseif (($gestion = (new GestionCaso)->evaluar($caso))['accion'] !== null && ! ($gestion['categoria'] === 'documento_vacio_con_nombre' && EvidenciaExterna::tieneVigente((int) $caso->id, 'documento_correcto'))) {
            return ['elegible' => false, 'motivo' => 'Este caso se gestiona como soporte o descarte, no con un reemplazo.', 'codigo' => ResolucionNoPermitida::TIPO_NO_ADMITIDO];
        }
        if ($cert->estado !== 'vigente' || $cert->revocado_at !== null) {
            return ['elegible' => false, 'motivo' => 'El certificado histórico no está vigente.', 'codigo' => ResolucionNoPermitida::ESTADO_HISTORICO_INVALIDO];
        }

        return ['elegible' => true, 'motivo' => null, 'codigo' => null];
    }

    /** El certificado histórico del caso (sale del caso, nunca de un id libre). @throws ResolucionNoPermitida */
    public function certificadoDelCaso(Conciliacion $caso, ?int $pedido = null): object
    {
        $this->exigirTipo($caso);

        return $this->certificadoOrigen($caso, $pedido, false);
    }

    /**
     * Huella de FRESCURA de la vista previa: cambia si cambia cualquier dato que influya en el certificado (caso, histórico, plantilla y su diseño,
     * valores aprobados, campos comunes). No incluye motivo ni actor.
     *
     * @throws ResolucionNoPermitida
     */
    public function huellaDe(int $casoId, SolicitudReemplazo $s): string
    {
        $caso = Conciliacion::query()->findOrFail($casoId);
        $this->exigirTipo($caso);
        $cert = $this->certificadoOrigen($caso, $s->certificadoId, false);

        return $this->calcularHuella($caso, $cert, $this->preparar($caso, $cert, $s));
    }

    /** @param  array<string,mixed>  $p */
    private function calcularHuella(Conciliacion $caso, object $cert, array $p): string
    {
        $plantilla = $p['plantilla'];

        return hash('sha256', json_encode([
            'caso' => [(int) $caso->id, (string) $caso->estado], 'historico' => [(int) $cert->id, (string) $cert->estado, $cert->reemplazado_por_emision_id],
            'plantilla' => [(int) $plantilla->id, (string) $plantilla->hash_sha256, (int) $plantilla->schema_version, CreadorPlantillaReemplazo::hashDiseno((array) $plantilla->diseno), $plantilla->origen_legado_meta['diseno_confirmado']['hash'] ?? null],
            'documento' => [$p['regla_documento'], hash('sha256', $p['documento'])], 'nombre' => [$p['regla_nombre'], hash('sha256', $p['nombre'])],
            'comunes' => $p['datos_comunes'], 'campos' => $p['usados'],
            // DIF_NOMBRE: también la fotografía de las variantes, el origen del nombre, la evidencia aportada y el estado de la identidad del documento.
            'dif_nombre' => $p['categoria'] === ReglasValorAprobado::CAT_DIF_NOMBRE ? [$p['variantes_huella'], $p['origen_nombre'], $p['evidencia_huella'], $p['confirmado'], self::identidadDe((string) $cert->documento_clave)] : null,
        ], JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR));
    }

    /** @throws ResolucionNoPermitida */
    private function exigirTipo(Conciliacion $caso): void
    {
        $motivo = (string) $caso->motivo_origen;
        $coherente = match ($caso->tipo) {
            Conciliacion::TIPO_REVISION_DOCUMENTO => $motivo !== ReglasValorAprobado::CAT_DIF_NOMBRE && in_array($motivo, ReglasValorAprobado::categorias(), true),
            Conciliacion::TIPO_CONFLICTO_VARIANTES => $motivo === ReglasValorAprobado::CAT_DIF_NOMBRE,
            default => false,
        };
        if (! in_array($caso->tipo, self::TIPOS_ADMITIDOS, true) || ! $coherente) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Este caso no admite un reemplazo con las reglas actuales.');
        }
    }

    /** Id del certificado que corresponde al caso (sale del pivote del caso, nunca de un id libre). @throws ResolucionNoPermitida */
    private function idDelCaso(Conciliacion $caso, ?int $pedido, bool $bloquear): int
    {
        $pivote = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id');
        $ids = ($bloquear ? $pivote->lockForUpdate() : $pivote)->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        if ($pedido !== null) {
            if (! in_array($pedido, $ids, true)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'Ese certificado no pertenece a este caso.');
            }
            if ($caso->tipo !== Conciliacion::TIPO_CONFLICTO_VARIANTES) {
                return $pedido;
            }
        }
        if ($caso->tipo === Conciliacion::TIPO_CONFLICTO_VARIANTES && $ids !== []) {
            // DIF_NOMBRE: el certificado lógico del caso. Con bloqueo NO se lee nada más (la raíz se resuelve después, ya bloqueado el evento).
            return $bloquear ? $ids[0] : $this->raizDelCaso($caso, CertificadoLogico::de($ids[0]));
        }
        if (count($ids) === 1) {
            return $ids[0];
        }

        throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'Este caso tiene varios certificados: indica a cuál corresponde el reemplazo.');
    }

    /** El certificado origen SALE DEL CASO. @throws ResolucionNoPermitida */
    private function certificadoOrigen(Conciliacion $caso, ?int $pedido, bool $bloquear): object
    {
        $id = $this->idDelCaso($caso, $pedido, $bloquear);
        $consulta = DB::table('cf_certificados_legado')->where('id', $id);
        $fila = ($bloquear ? $consulta->lockForUpdate() : $consulta)->first(self::COLUMNAS_CERT);

        return $fila ?? throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'El certificado del caso ya no existe.');
    }

    /**
     * DIF_NOMBRE: todos los certificados del caso deben ser miembros del MISMO certificado lógico; devuelve su raíz (la que lleva el reemplazo).
     *
     * @param  array{grupo:?string,miembros:list<int>,raiz:int}  $logico
     *
     * @throws ResolucionNoPermitida
     */
    private function raizDelCaso(Conciliacion $caso, array $logico): int
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        if (count($ids) < 2 || array_diff($ids, $logico['miembros']) !== [] || ! in_array($logico['raiz'], $ids, true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'Los certificados de este caso no forman un solo certificado: no se puede emitir un reemplazo.');
        }

        return $logico['raiz'];
    }

    /**
     * Contexto validado de un caso DIF_NOMBRE (sin escribir): las variantes del certificado lógico, todas vigentes y pendientes, del mismo evento, documento
     * y tipo, con nombres realmente distintos. Los nombres en claro solo viven en memoria (la huella lleva su SHA-256).
     *
     * @return array{variantes:array<int,string>,ids:list<int>,huella:list<array{0:int,1:string}>}
     *
     * @throws ResolucionNoPermitida
     */
    private function contextoDifNombre(Conciliacion $caso, object $cert): array
    {
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $filas = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id')->get(self::COLUMNAS_CERT);
        $logico = CertificadoLogico::de((int) $cert->id);
        if ($filas->count() < 2 || $filas->count() !== count($ids) || array_diff($ids, $logico['miembros']) !== [] || ! in_array((int) $cert->id, $ids, true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADO_NO_PERTENECE, 'Los certificados de este caso no forman un solo certificado: no se puede emitir un reemplazo.');
        }
        foreach ($filas as $f) {
            if ($f->estado !== 'vigente' || $f->revocado_at !== null || $f->reemplazado_por_emision_id !== null || $f->conciliacion_estado !== CertificadoLegado::CONCILIACION_PENDIENTE) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::ESTADO_HISTORICO_INVALIDO, 'Alguna variante de este caso ya no está pendiente y vigente.');
            }
        }
        if ($filas->pluck('evento_id')->unique()->count() !== 1 || $filas->pluck('tipo_documento')->unique()->count() !== 1) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Las variantes no son del mismo evento y tipo de documento: este caso no admite un reemplazo con nombre aprobado.');
        }
        // DOCUMENTO_SIN_CAMBIO: el documento es el mismo (exacto) en todas las variantes; si no, es otro problema (documento), no este.
        if ($filas->pluck('documento')->unique()->count() !== 1) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::VALOR_NO_VALIDO, 'Las variantes no tienen exactamente el mismo documento: primero debe resolverse el documento.');
        }
        if ($filas->map(fn ($f) => NombreConservador::normalizar((string) $f->nombre_completo))->unique()->count() < 2) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Los nombres de este caso solo difieren en la presentación: usa «Consolidar variación de nombre».');
        }
        $variantes = $filas->mapWithKeys(fn ($f) => [(int) $f->id => (string) $f->nombre_completo])->all();

        return ['variantes' => $variantes, 'ids' => $ids, 'huella' => array_map(fn ($id) => [$id, hash('sha256', $variantes[$id])], $ids)];
    }

    /**
     * Fotografía SIN PII de la identidad del documento: casos de identidad ambigua (id, estado, resolución) y decisiones (id, tipo, estado). Es independiente
     * del nombre aprobado: aprobar un nombre no decide quién es la persona ni autoriza ningún correo, y viceversa.
     *
     * @return array{casos:list<array<string,mixed>>,decisiones:list<array<string,mixed>>}
     */
    public static function identidadDe(string $documentoClave): array
    {
        $hash = EvidenciaIdentidad::hashDocumento($documentoClave);

        return [
            'casos' => DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->where('referencia_clave', $hash)->orderBy('id')->get(['id', 'estado', 'resolucion'])
                ->map(fn ($c) => ['id' => (int) $c->id, 'estado' => (string) $c->estado, 'resolucion' => $c->resolucion])->all(),
            'decisiones' => DB::table('cf_decisiones_identidad')->where('documento_hash', $hash)->orderBy('id')->get(['id', 'tipo', 'estado'])
                ->map(fn ($d) => ['id' => (int) $d->id, 'tipo' => (string) $d->tipo, 'estado' => (string) $d->estado])->all(),
        ];
    }

    /**
     * Un certificado LÓGICO (grupo de duplicados) tiene UN solo reemplazo y lo lleva SOLO su raíz (ver `CertificadoLogico`):
     *  - él mismo ya reemplazado → YA_REEMPLAZADO · otra fila del mismo certificado lógico reemplazada → YA_REEMPLAZADO_LOGICAMENTE
     *  - es una variante (no la raíz) → VARIANTE_NO_CANONICA: el reemplazo se gestiona desde el certificado principal.
     * Con `$filas` (ya bloqueadas) no se lee nada más; sin ellas, se leen los miembros.
     *
     * @param  array{grupo:?string,miembros:list<int>,raiz:int}|null  $logico
     * @param  Collection<int,object>|null  $filas
     *
     * @throws ResolucionNoPermitida
     */
    private function exigirRaizLogica(object $cert, ?array $logico = null, ?Collection $filas = null): void
    {
        if ($cert->estado === 'reemplazado' || $cert->reemplazado_por_emision_id !== null) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::YA_REEMPLAZADO, 'Este certificado ya fue reemplazado.');
        }
        $logico ??= CertificadoLogico::de((int) $cert->id);
        if (count($logico['miembros']) < 2) {
            return;
        }
        $filas ??= DB::table('cf_certificados_legado')->whereIn('id', $logico['miembros'])->get(['id', 'estado', 'reemplazado_por_emision_id'])->keyBy('id');
        if ($filas->contains(fn ($f) => (int) $f->id !== (int) $cert->id && ($f->estado === 'reemplazado' || $f->reemplazado_por_emision_id !== null))) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::YA_REEMPLAZADO_LOGICAMENTE, 'Este registro pertenece al mismo certificado que otro registro histórico, que ya fue reemplazado. El reemplazo se gestiona desde su certificado principal.');
        }
        if ($logico['raiz'] !== (int) $cert->id) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::VARIANTE_NO_CANONICA, 'Este registro pertenece al mismo certificado que otro registro histórico. El reemplazo se gestiona desde su certificado principal.');
        }
    }

    /** @param  array<string,mixed>  $diseno  @return list<string> */
    private function camposUsados(array $diseno): array
    {
        $campos = [];
        foreach ($diseno['elements'] ?? [] as $e) {
            if (is_array($e) && ($e['type'] ?? null) === DisenoSchema::TIPO_TEXTO && ($e['field'] ?? null) !== null) {
                $campos[(string) $e['field']] = true;
            }
        }

        return array_keys($campos);
    }

    /** Un prefijo «C.C.» implica un documento de tipo CC: no se imprime sobre otro tipo. @throws ResolucionNoPermitida */
    private function comprobarPrefijo(array $diseno, string $tipoDocumento): void
    {
        foreach ($diseno['elements'] ?? [] as $e) {
            if (is_array($e) && ($e['field'] ?? null) === 'documento') {
                $tipo = self::TIPO_POR_PREFIJO[trim((string) ($e['prefix'] ?? ''))] ?? null;
                if ($tipo !== null && $tipo !== $tipoDocumento) {
                    throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La plantilla imprime un tipo de documento distinto al de este certificado.');
                }
            }
        }
    }

    /** @throws ResolucionNoPermitida */
    private function datoComun(string $campo, ?string $valor): string
    {
        $def = CamposDinamicos::todos()[$campo];
        $texto = Texto::limpiar((string) $valor);
        if ($texto === '') {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::CAMPO_SIN_VALOR, "La plantilla imprime «{$def['etiqueta']}» y el histórico no tiene ese dato: debe aportarse explícitamente.");
        }
        if (Texto::tieneControl($texto) || mb_strlen($texto) > $def['maxLongitud'] || ValidadorParticipante::noSoportados($texto) !== []) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::DATO_NO_IMPRIMIBLE, "El dato «{$def['etiqueta']}» no se puede imprimir (largo, caracteres de control o caracteres que la fuente no tiene).");
        }

        return $texto;
    }

    private function mapear(GeneracionCredencialException $e): ResolucionNoPermitida
    {
        $codigo = match ($e->codigo) {
            GeneracionCredencialException::CAMPO_SIN_VALOR => ResolucionNoPermitida::CAMPO_SIN_VALOR,
            GeneracionCredencialException::CARACTER_NO_SOPORTADO, GeneracionCredencialException::NO_CABE, GeneracionCredencialException::DATO_INVALIDO,
            GeneracionCredencialException::FUERA_DE_PAGINA => ResolucionNoPermitida::DATO_NO_IMPRIMIBLE,
            default => ResolucionNoPermitida::PLANTILLA_INVALIDA,
        };

        return new ResolucionNoPermitida($codigo, $e->getMessage());
    }

    // ── Lote ──────────────────────────────────────────────────────────────────

    /**
     * El lote técnico del reemplazo: UNO por (evento histórico + plantilla moderna + datos comunes). Se busca o crea bajo el lock del evento, así
     * dos procesos que llegan a la vez terminan con un solo lote. No se crea ningún evento moderno: `evento_id` apunta al evento histórico.
     *
     * @param  array<string,mixed>  $p
     * @return array{0:Lote,1:bool}
     */
    private function lote(object $cert, array $p, int $actorId): array
    {
        // El lock del evento ya se tomó al principio de la transacción (ver `reemplazar`): serializa «buscar o crear».
        $datos = ['origen' => self::ORIGEN_LOTE] + $p['datos_comunes'];
        ksort($datos);
        foreach (Lote::query()->where('evento_id', $cert->evento_id)->where('plantilla_id', $p['plantilla']->id)->orderBy('id')->lockForUpdate()->get() as $lote) {
            $existente = (array) $lote->datos_comunes;
            ksort($existente);
            if (($existente['origen'] ?? null) === self::ORIGEN_LOTE && $existente === $datos) {
                return [$lote, false];
            }
        }

        $lote = new Lote([
            'plantilla_id' => $p['plantilla']->id, 'evento_id' => $cert->evento_id, 'nombre' => 'Reemplazos históricos · '.mb_substr((string) $p['evento_nombre'], 0, 150),
            'descripcion' => 'Lote técnico: certificados históricos reemplazados por una emisión moderna.', 'datos_comunes' => $datos,
        ]);
        $lote->forceFill(['created_by' => $actorId, 'update_by' => $actorId])->save();

        return [$lote, true];
    }

    // ── Actor y motivo ────────────────────────────────────────────────────────

    /** @throws ResolucionNoPermitida */
    private function actor(int $actorId): void
    {
        $u = Usuario::query()->find($actorId);
        if ($u === null || ! in_array($u->rol, self::ROLES, true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::ACTOR_NO_AUTORIZADO, 'Solo un administrador puede reemplazar certificados históricos.');
        }
    }

    /** @throws ResolucionNoPermitida */
    private function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);
        $largo = mb_strlen($motivo);
        if ($largo < ResolucionPlantillas::MOTIVO_MIN || $largo > ResolucionPlantillas::MOTIVO_MAX) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'El motivo debe tener entre '.ResolucionPlantillas::MOTIVO_MIN.' y '.ResolucionPlantillas::MOTIVO_MAX.' caracteres.');
        }

        return $motivo;
    }
}
