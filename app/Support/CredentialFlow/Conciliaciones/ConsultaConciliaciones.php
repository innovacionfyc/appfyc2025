<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Historico\ConsultaHistorica;
use App\Support\CredentialFlow\Historico\PresentadorHistorico;
use App\Support\CredentialFlow\Identidad\AutorizacionMasiva;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\GruposSinVia;
use App\Support\CredentialFlow\Identidad\IdentidadFlags;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Reemplazo\ConsultaReemplazo;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Consultas de SOLO LECTURA de los casos de conciliación para el administrador (Fase 10A). Nunca escribe. El LISTADO no lleva nombres,
 * documentos, correos ni códigos; el DETALLE muestra la evidencia necesaria con los datos personales ENMASCARADOS (nombre en iniciales,
 *
 * documento con los últimos dígitos, correo `a***@dominio`). Número de consultas constante por pantalla (sin N+1).
 */
final class ConsultaConciliaciones
{
    public const POR_PAGINA = 25;

    /** Máximo de eventos que se listan por grupo en una identidad ambigua (hay documentos con cientos). */
    public const EVENTOS_VISIBLES = 10;

    /** @param array{tipo?:?string,estado?:?string,evento?:?string} $f */
    public function listar(array $f): LengthAwarePaginator
    {
        $afectados = DB::table('cf_conciliaciones_certificados as k')->selectRaw('COUNT(1)')->whereColumn('k.conciliacion_id', 'c.id');

        return $this->base($f)
            ->leftJoin('cf_eventos as e', 'e.id', '=', 'c.evento_id')
            ->select('c.id', 'c.tipo', 'c.estado', 'c.evento_id', 'c.motivo_origen', 'c.created_at', 'e.nombre as evento_nombre', 'e.anio as evento_anio')
            ->selectSub($afectados, 'afectados')
            ->orderByDesc('c.created_at')->orderByDesc('c.id')
            ->paginate(self::POR_PAGINA)
            ->through(fn ($c) => [
                'id' => (int) $c->id,
                'tipo' => $c->tipo,
                'tipo_info' => PresentadorConciliaciones::tipo($c->tipo),
                'estado' => $c->estado,
                'estado_info' => PresentadorConciliaciones::estado($c->estado),
                'evento' => $c->evento_id === null ? null : ['id' => (int) $c->evento_id, 'nombre' => $c->evento_nombre, 'anio_etiqueta' => PresentadorHistorico::anio($c->evento_anio === null ? null : (int) $c->evento_anio)],
                'motivo' => PresentadorConciliaciones::motivo($c->motivo_origen),
                'afectados' => (int) $c->afectados,
                'detectado_at' => $c->created_at,
            ]);
    }

    /** @return array{total:int,por_estado:array<string,int>,por_tipo:array<string,int>} conteos que respetan los filtros de los OTROS dos campos */
    public function resumen(array $f): array
    {
        $porEstado = $this->base(['estado' => null] + $f)->selectRaw('c.estado, COUNT(1) n')->groupBy('c.estado')->pluck('n', 'estado')->map(fn ($n) => (int) $n)->all();
        $porTipo = $this->base(['tipo' => null] + $f)->selectRaw('c.tipo, COUNT(1) n')->groupBy('c.tipo')->pluck('n', 'tipo')->map(fn ($n) => (int) $n)->all();

        return ['total' => array_sum($porEstado), 'por_estado' => array_replace(array_fill_keys(Conciliacion::ESTADOS, 0), $porEstado), 'por_tipo' => array_replace(array_fill_keys(Conciliacion::TIPOS, 0), $porTipo)];
    }

    /** @return array{tipos:list<array<string,mixed>>,estados:list<array<string,mixed>>,eventos:list<array<string,mixed>>} */
    public function opciones(): array
    {
        $eventos = DB::table('cf_conciliaciones as c')->join('cf_eventos as e', 'e.id', '=', 'c.evento_id')->distinct()->orderBy('e.nombre')->get(['e.id', 'e.nombre', 'e.anio'])
            ->map(fn ($e) => ['valor' => (int) $e->id, 'etiqueta' => $e->nombre.' · '.PresentadorHistorico::anio($e->anio === null ? null : (int) $e->anio)])->all();

        return [
            'tipos' => collect(PresentadorConciliaciones::TIPOS)->map(fn ($t, $k) => ['valor' => $k, 'etiqueta' => $t['etiqueta'], 'ayuda' => $t['ayuda']])->values()->all(),
            'estados' => collect(PresentadorConciliaciones::ESTADOS)->map(fn ($e, $k) => ['valor' => $k, 'etiqueta' => $e['etiqueta'], 'tono' => $e['tono']])->values()->all(),
            'eventos' => $eventos,
        ];
    }

    private function base(array $f)
    {
        return DB::table('cf_conciliaciones as c')
            ->when($f['tipo'] ?? null, fn ($q, $v) => $q->where('c.tipo', $v))
            ->when($f['estado'] ?? null, fn ($q, $v) => $q->where('c.estado', $v))
            ->when($f['evento'] ?? null, fn ($q, $v) => $q->where('c.evento_id', (int) $v));
    }

    // ── Detalle ──────────────────────────────────────────────────────────────────────────────────────────

    /** @return array<string,mixed> */
    public function detalle(Conciliacion $caso): array
    {
        $certs = DB::table('cf_conciliaciones_certificados as k')->join('cf_certificados_legado as c', 'c.id', '=', 'k.certificado_legado_id')->where('k.conciliacion_id', $caso->id)->orderBy('c.id')
            ->get(['c.id', 'c.evento_id', 'c.tipo_documento', 'c.documento', 'c.nombre_completo', 'c.codigo_legado', 'c.conciliacion_estado', 'c.estado', 'c.grupo_duplicado', 'c.snapshot_legado', 'c.pdf_archivo', 'c.reemplazado_por_emision_id', 'k.rol']);
        $eventos = DB::table('cf_eventos')->whereIn('id', $certs->pluck('evento_id')->unique())->get(['id', 'nombre', 'anio'])->keyBy('id');
        $evento = fn ($id) => ($e = $eventos->get($id)) === null ? null : ['id' => (int) $e->id, 'nombre' => $e->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($e->anio === null ? null : (int) $e->anio)];

        // Solo los casos de conflicto ABIERTOS se revisan para consolidar (código 10B-2A; correo y variación de nombre 10B-2B-1).
        $consol = $caso->tipo === Conciliacion::TIPO_CONFLICTO_VARIANTES && $caso->estado === Conciliacion::ABIERTO ? $this->revisarConsolidacion($caso, $certs) : null;
        $evidencia = match ($caso->tipo) {
            Conciliacion::TIPO_CONFLICTO_VARIANTES => $this->conflicto($certs, $evento, $consol, $caso),
            Conciliacion::TIPO_REVISION_DOCUMENTO => $this->documento($certs, $evento),
            Conciliacion::TIPO_IDENTIDAD_AMBIGUA => $this->identidad($certs, $evento),
            default => $this->plantilla($caso, $certs, $evento),
        };

        $accion = $this->accion($caso, $certs, $consol);

        return [
            'id' => (int) $caso->id,
            'tipo' => $caso->tipo,
            'tipo_info' => PresentadorConciliaciones::tipo($caso->tipo),
            'estado' => $caso->estado,
            'estado_info' => PresentadorConciliaciones::estado($caso->estado),
            'motivo' => PresentadorConciliaciones::motivo($caso->motivo_origen),
            'detectado_at' => $caso->created_at?->toDateTimeString(),
            'evento' => $caso->evento_id === null ? null : $evento($caso->evento_id) ?? ($caso->evento === null ? null : ['id' => (int) $caso->evento->id, 'nombre' => $caso->evento->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($caso->evento->anio)]),
            'afectados' => $certs->count(),
            'resolucion' => $caso->resolucion,
            'evidencia' => $evidencia,
            'bitacora' => $caso->eventos()->get(['accion', 'estado_anterior', 'estado_nuevo', 'motivo', 'actor_id', 'created_at'])->map(fn ($e) => [
                'accion' => $e->accion, 'estado_anterior' => $e->estado_anterior, 'estado_nuevo' => $e->estado_nuevo, 'motivo' => $e->motivo,
                'actor' => $e->actor_id === null ? 'Detección automática' : 'Administración', 'fecha' => $e->created_at?->toDateTimeString(),
            ])->all(),
            'accion' => $accion,
            'acciones_disponibles' => $accion['disponible'] ?? false,
            // Reemplazo emitido (10B-2B-2B): referencia a la emisión moderna (código, PDF de administración, verificación pública).
            'reemplazo' => app(ConsultaReemplazo::class)->resultado($caso),
            // Certificado lógico (10B-2B-2C.1): si este caso es de una variante, dónde se gestiona el reemplazo.
            'certificado_logico' => ConsultaReemplazo::logico($caso),
            // Caso especial (10B-3C-4): lo que NO se resuelve automáticamente, qué falta y la evidencia externa registrada (sin PII).
            'especial' => app(CasosEspeciales::class)->paraPantalla($caso),
            // Decisiones de identidad (10B-3A): evidencia por grupo + decisiones registradas. REGISTRAN, no autorizan.
            'identidad' => $caso->tipo === Conciliacion::TIPO_IDENTIDAD_AMBIGUA ? $this->identidadDecisiones($caso) : null,
        ];
    }

    /** @return array<string,mixed> */
    private function identidadDecisiones(Conciliacion $caso): array
    {
        $ev = EvidenciaIdentidad::de($caso);
        $decisiones = DecisionesIdentidad::delCaso((int) $caso->id);
        $sinVia = GruposSinVia::esMotivo((string) $caso->motivo_origen);
        $abierto = in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true) || ($caso->estado === Conciliacion::RESUELTO && $caso->resolucion === 'identidad_aplicada');
        $avisos = [];
        if ($ev['riesgos']['masivo']) {
            $avisos[] = ['codigo' => 'masivo', 'texto' => 'Esta decisión podría afectar el acceso a cientos de certificados.'];
        }
        if ($ev['riesgos']['mismo_evento']) {
            $avisos[] = ['codigo' => 'mismo_evento', 'texto' => 'Las variantes aparecen en el mismo evento. La similitud de los nombres no prueba que sean la misma persona: se requiere evidencia externa.'];
        }
        if ($ev['invalido']) {
            $avisos[] = ['codigo' => 'invalido', 'texto' => 'El documento de este caso es inválido: no se puede inferir. Solo se puede marcar «requiere soporte» o «no resoluble».'];
        }

        return [
            'registra_no_autoriza' => 'Registrar una decisión puede afectar el acceso de la persona a sus certificados. No se fusionan los grupos ni se modifica ningún nombre.',
            'documento' => $ev['documento_hash'] === null ? null : substr($ev['documento_hash'], 0, 10),
            'certificados' => $ev['certificados'], 'grupos' => $ev['grupos'], 'correos_compartidos' => $ev['correos_compartidos'], 'sin_via_individual' => $ev['sin_via_individual'],
            'riesgos' => $ev['riesgos'], 'avisos' => $avisos, 'umbral_masivo' => EvidenciaIdentidad::UMBRAL_MASIVO, 'modelo_acotado' => true,
            'tipos' => $abierto ? ($ev['invalido'] || $sinVia || $ev['riesgos']['nombres_distintos'] ? $this->tiposSinVia($caso, $ev['invalido']) : ['misma_persona', 'personas_distintas', 'correo_autorizado', 'requiere_soporte', 'no_resoluble']) : [],
            // 10B-3C-1: caso de un GRUPO SIN VÍA. `objetivos` = grupos que el caso gestiona; la pantalla solo ofrece lo que su motivo admite (el backend lo valida igual).
            'modo' => $sinVia ? 'grupo_sin_via' : null, 'objetivos' => $sinVia ? app(GruposSinVia::class)->objetivosDe($caso) : [], 'mensajes_sin_via' => $sinVia ? $this->mensajesSinVia((string) $caso->motivo_origen) : [],
            'decisiones' => $decisiones,
            'evidencia_min' => DecisionesIdentidad::EVIDENCIA_MIN, 'evidencia_max' => DecisionesIdentidad::EVIDENCIA_MAX, 'motivo_min' => ResolucionPlantillas::MOTIVO_MIN, 'motivo_max' => ResolucionPlantillas::MOTIVO_MAX,
            'ruta_crear' => route('credential-flow.historico.casos.identidad.crear', $caso->id),
            'rutas_revocar' => collect(DecisionesIdentidad::delCaso((int) $caso->id))->where('estado', 'vigente')->mapWithKeys(fn ($d) => [$d['id'] => route('credential-flow.historico.casos.identidad.revocar', [$caso->id, $d['id']])])->all(),
            // 10B-3C-3: autorización masiva con doble control (sin PII: ids, conteos y la fuente de la evidencia).
            'masivo' => $this->masivoDeCaso($caso, $ev),
            'rutas_aprobar' => collect($decisiones)->filter(fn ($d) => ($d['aprobacion']['puede_aprobar'] ?? false))->mapWithKeys(fn ($d) => [$d['id'] => route('credential-flow.historico.casos.identidad.aprobar-masiva', [$caso->id, $d['id']])])->all(),
            'rutas_revocar_aprobacion' => collect($decisiones)->filter(fn ($d) => ($d['aprobacion']['puede_revocar'] ?? false))->mapWithKeys(fn ($d) => [$d['id'] => route('credential-flow.historico.casos.identidad.revocar-aprobacion', [$caso->id, $d['id']])])->all(),
        ];
    }

    /**
     * Datos de la pantalla de riesgo masivo: por cada grupo con >= 100 certificados, su blast radius (solo conteos), y los textos fijos. Null si ningún grupo lo es.
     *
     * @param  array<string,mixed>  $ev
     * @return array<string,mixed>|null
     */
    private function masivoDeCaso(Conciliacion $caso, array $ev): ?array
    {
        $grandes = collect($ev['por_hash'] ?? [])->filter(fn ($g) => $g['certificados'] >= EvidenciaIdentidad::UMBRAL_MASIVO)->keys()->all();
        if ($grandes === []) {
            return null;
        }
        $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');

        return [
            'grupos' => collect($grandes)->mapWithKeys(fn ($h) => [$h => AutorizacionMasiva::alcance($clave, (string) $h)])->all(),
            'fuentes' => ['propiedad_buzon' => 'Propiedad del buzón', 'certificacion_organizador' => 'Certificación del organizador', 'registro_validado' => 'Documento o registro validado', 'otra' => 'Otra fuente admisible'],
            'texto_confirmacion' => AutorizacionMasiva::TEXTO_CONFIRMACION, 'interruptor_masivo' => IdentidadFlags::masaHabilitada(),
        ];
    }

    /** @return list<string> */
    private function tiposSinVia(Conciliacion $caso, bool $invalido): array
    {
        return ! $invalido && (string) $caso->motivo_origen === GruposSinVia::MOTIVO_COMPARTIDO ? ['correo_autorizado', 'requiere_soporte', 'no_resoluble'] : ['requiere_soporte', 'no_resoluble'];
    }

    /** @return list<string> */
    private function mensajesSinVia(string $motivo): array
    {
        return match ($motivo) {
            GruposSinVia::MOTIVO_COMPARTIDO => ['Este grupo no puede entrar hoy porque su correo aparece también en otro registro histórico. Autorizar el correo para este grupo NO significa que sean la misma persona y NO cambia el acceso del otro registro.'],
            GruposSinVia::MOTIVO_EVIDENCIA => ['El nombre de este grupo es realmente distinto del de los registros con los que comparte el correo (o coinciden en un evento): no se resuelve sin evidencia externa. Se gestionará en una fase posterior.'],
            GruposSinVia::MOTIVO_SIN_CORREO => ['No existe un correo histórico propio para autenticar este grupo. No se usa el correo de otro registro: requiere evidencia externa y se gestionará en una fase posterior.'],
            default => [],
        };
    }

    /**
     * Acción de resolución que corresponde al caso (solo los de plantilla, y solo mientras estén abiertos). `bloqueo` explica por qué no
     * se puede ejecutar (PDF congelado o certificados ya reemplazados); la pantalla solo ofrece el botón cuando `disponible` es true.
     *
     * @return array<string,mixed>|null
     */
    private function accion(Conciliacion $caso, Collection $certs, ?array $consol = null): ?array
    {
        $n = $certs->count();
        $def = match ($caso->tipo) {
            Conciliacion::TIPO_PLANTILLA_CANDIDATA => ['clave' => 'aprobar_candidata', 'ruta' => 'credential-flow.historico.casos.aprobar-candidata', 'etiqueta' => 'Aprobar y asociar', 'archivo' => false,
                'texto' => "Se usará la imagen candidata como plantilla de este evento y {$n} certificados dejarán de estar pendientes de plantilla. El archivo original y la evidencia no se modifican."],
            Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO => ['clave' => 'confirmar_renderizable', 'ruta' => 'credential-flow.historico.casos.confirmar-renderizable', 'etiqueta' => 'Confirmar como renderizable', 'archivo' => false,
                'texto' => "Se permitirá usar el tipo real de la imagen para generar los certificados ({$n}). No se convierte ni se reemplaza el archivo y la extensión histórica se conserva."],
            Conciliacion::TIPO_PLANTILLA_FALTANTE => ['clave' => 'aportar_plantilla', 'ruta' => 'credential-flow.historico.casos.aportar-plantilla', 'etiqueta' => 'Aportar plantilla', 'archivo' => true,
                'texto' => "Sube la imagen de fondo de este evento. Se asociará a los {$n} certificados pendientes. No se sobrescribe ningún archivo existente."],
            // Solo las diferencias de CÓDIGO, de CORREO y la VARIACIÓN cosmética de nombre ofrecen consolidar; los nombres realmente distintos no.
            Conciliacion::TIPO_CONFLICTO_VARIANTES => $this->defConflicto($consol),
            default => null,
        };
        if ($caso->estado !== Conciliacion::ABIERTO) {
            return null;
        }
        if ($def === null) {
            return $this->accionReemplazo($caso) ?? $this->accionGestion($caso);
        }
        if ($consol !== null) {
            $primero = $consol['bloqueos'][0] ?? null;

            return ['clave' => $def['clave'], 'etiqueta' => $def['etiqueta'], 'texto' => $def['texto'], 'url' => route($def['ruta'], $caso->id), 'requiere_archivo' => false, 'disponible' => $primero === null, 'bloqueo' => $primero['mensaje'] ?? null,
                'motivo_min' => ResolucionPlantillas::MOTIVO_MIN, 'motivo_max' => ResolucionPlantillas::MOTIVO_MAX, 'archivo' => null,
                // Variación de nombre: el administrador ELIGE la canónica (los nombres solo se muestran aquí, a quien debe elegir).
                'opciones' => ($def['opciones'] ?? false) ? $certs->map(fn ($c) => ['id' => (int) $c->id, 'nombre' => (string) $c->nombre_completo])->values()->all() : null];
        }
        $bloqueo = match (true) {
            $certs->contains(fn ($c) => $c->pdf_archivo !== null) => ResolucionPlantillas::MSG_PDF_CONGELADO,
            $certs->contains(fn ($c) => $c->reemplazado_por_emision_id !== null) => 'Algún certificado de este caso ya fue reemplazado: no se puede cambiar su plantilla directamente.',
            default => null,
        };

        return [
            'clave' => $def['clave'], 'etiqueta' => $def['etiqueta'], 'texto' => $def['texto'], 'url' => route($def['ruta'], $caso->id), 'requiere_archivo' => $def['archivo'],
            'disponible' => $bloqueo === null, 'bloqueo' => $bloqueo, 'motivo_min' => ResolucionPlantillas::MOTIVO_MIN, 'motivo_max' => ResolucionPlantillas::MOTIVO_MAX,
            'archivo' => $def['archivo'] ? ['formatos' => ['JPEG', 'PNG'], 'max_mb' => (int) round((int) config('credential_flow.legado.plantilla_manual_max_bytes', ResolucionPlantillas::MAX_BYTES_POR_DEFECTO) / 1048576)] : null,
        ];
    }

    /**
     * Revisa un caso de conflicto abierto según su tipo de diferencia (solo código, solo correo o solo nombre) y unifica el resultado.
     *
     * @return array<string,mixed>|null
     */
    private function revisarConsolidacion(Conciliacion $caso, Collection $certs): ?array
    {
        $etiquetas = DetectorConciliaciones::lista($this->snap($certs->first())['duplicado']['etiquetas'] ?? null);
        if ($etiquetas === ['DIF_VERIF']) {
            return ['modo' => 'codigo'] + (new ConsolidacionCodigo)->analizar($caso) + ['nombre_real' => false];
        }
        if (in_array($etiquetas, [['DIF_CORREO'], ['DIF_NOMBRE']], true)) {
            return (new ConsolidacionVariantes)->analizar($caso);
        }

        return null;
    }

    /** @return array<string,mixed>|null */
    private function defConflicto(?array $consol): ?array
    {
        if (! ($consol['aplicable'] ?? false)) {
            return null;
        }

        return match ($consol['modo'] ?? null) {
            'codigo' => ['clave' => 'consolidar_codigo', 'ruta' => 'credential-flow.historico.casos.consolidar-codigo', 'etiqueta' => 'Consolidar diferencia de código', 'archivo' => false,
                'texto' => 'Las variantes son del mismo evento y documento y solo una tiene el código histórico. Se dejará esa como canónica y la otra como duplicado consolidado, sin crear ni copiar códigos. Los registros, descargas y correos originales no se modifican.'],
            'correo' => ['clave' => 'consolidar_variantes', 'ruta' => 'credential-flow.historico.casos.consolidar-variantes', 'etiqueta' => 'Consolidar variantes', 'archivo' => false,
                'texto' => 'Las variantes corresponden al mismo certificado histórico y difieren únicamente en el correo registrado. Se consolidarán en un solo certificado lógico (el canónico propuesto); los correos, las descargas y los registros originales no se modifican y no se crea ningún código.'],
            'nombre_cosmetico' => ['clave' => 'consolidar_nombre', 'ruta' => 'credential-flow.historico.casos.consolidar-nombre', 'etiqueta' => 'Consolidar variación de nombre', 'archivo' => false, 'opciones' => true,
                'texto' => 'Los nombres solo difieren en tildes, mayúsculas o signos. Elige cuál variante queda como canónica (define la grafía del certificado); la otra queda como duplicado consolidado y ningún nombre se modifica.'],
            default => null,
        };
    }

    /**
     * «Emitir certificado corregido» (10B-2B-2B): un ENLACE al asistente (no un POST) solo para los casos documentales que admite el servicio de
     * reemplazo. No se ofrece para nombres reales, identidad ambigua, DOC_VACIO, DOC_LETRAS de texto, soporte, descartados, resueltos ni reemplazados.
     *
     * @return array<string,mixed>|null
     */
    private function accionReemplazo(Conciliacion $caso): ?array
    {
        if (! (new ReemplazoHistorico(app(EmisorCredencial::class)))->elegible($caso)['elegible']) {
            return null;
        }

        $dif = $caso->tipo === Conciliacion::TIPO_CONFLICTO_VARIANTES;

        return ['clave' => 'emitir_reemplazo', 'etiqueta' => $dif ? 'Emitir certificado con nombre aprobado' : 'Emitir certificado corregido', 'enlace' => true, 'url' => route('credential-flow.historico.casos.reemplazo', $caso->id), 'requiere_archivo' => false,
            'texto' => $dif ? 'Las variantes de este certificado imprimían nombres distintos. Un asistente te guiará para aprobar EXACTAMENTE el nombre que se imprimirá (una variante histórica o un nombre confirmado con evidencia), preparar la plantilla moderna, revisar la vista previa y emitir un único certificado nuevo. El documento no cambia y esto no decide la identidad de la persona. '.ConsultaReemplazo::AVISO_INMUTABLE : 'El documento de este certificado histórico no se puede imprimir bien tal como está. Un asistente te guiará para aprobar el dato correcto, preparar la plantilla moderna, revisar la vista previa y emitir un certificado nuevo. '.ConsultaReemplazo::AVISO_INMUTABLE,
            'disponible' => true, 'bloqueo' => null, 'motivo_min' => ResolucionPlantillas::MOTIVO_MIN, 'motivo_max' => ResolucionPlantillas::MOTIVO_MAX, 'archivo' => null, 'opciones' => null];
    }

    /**
     * Acciones de gestión (10B-2B-1) para casos que no se pueden resolver con evidencia: «requiere soporte» y «descartar». Solo cambian el caso.
     *
     * @return array<string,mixed>|null
     */
    private function accionGestion(Conciliacion $caso): ?array
    {
        $e = (new GestionCaso)->evaluar($caso);
        if ($e['accion'] === null) {
            return null;
        }
        $def = $e['accion'] === GestionCaso::DESCARTE
            ? ['clave' => 'descartar', 'ruta' => 'credential-flow.historico.casos.descartar', 'etiqueta' => 'Descartar caso',
                'texto' => 'No hay nombre, correo, código ni descargas: no existen datos útiles para este registro. Se descartará el caso; el certificado histórico permanece intacto y bloqueado y no se borra nada.']
            : ['clave' => 'requiere_soporte', 'ruta' => 'credential-flow.historico.casos.requiere-soporte', 'etiqueta' => 'Marcar requiere soporte',
                'texto' => 'Este caso no se puede resolver con la evidencia disponible. Se marcará como «requiere soporte»: solo cambia el caso; el certificado, su documento y su estado histórico no se modifican.'];

        return ['clave' => $def['clave'], 'etiqueta' => $def['etiqueta'], 'texto' => $def['texto'], 'url' => route($def['ruta'], $caso->id), 'requiere_archivo' => false, 'disponible' => true, 'bloqueo' => null,
            'motivo_min' => ResolucionPlantillas::MOTIVO_MIN, 'motivo_max' => ResolucionPlantillas::MOTIVO_MAX, 'archivo' => null, 'opciones' => null, 'categoria' => $e['categoria']];
    }

    private function snap($c): array
    {
        return json_decode((string) $c->snapshot_legado, true) ?: [];
    }

    /** @return array<int,int> certificado_legado_id → descargas históricas */
    private function descargas(Collection $certs): Collection
    {
        return DB::table('cf_descargas')->whereIn('certificado_legado_id', $certs->pluck('id'))->groupBy('certificado_legado_id')->selectRaw('certificado_legado_id, COUNT(1) n')->pluck('n', 'certificado_legado_id')->map(fn ($n) => (int) $n);
    }

    /** @return Collection<int,Collection> certificado_legado_id → correos válidos (normalizados) */
    private function correos(Collection $certs): Collection
    {
        return DB::table('cf_correos')->whereIn('certificado_legado_id', $certs->pluck('id'))->where('estado', 'valido')->orderBy('orden')->orderBy('id')->get(['certificado_legado_id', 'correo_normalizado'])->groupBy('certificado_legado_id')->map(fn ($g) => $g->pluck('correo_normalizado'));
    }

    private function conflicto(Collection $certs, callable $evento, ?array $consol = null, ?Conciliacion $caso = null): array
    {
        $descargas = $this->descargas($certs);
        $correos = $this->correos($certs);
        $canonicos = DB::table('cf_migraciones_map')->where('destino_tabla', 'cf_certificados_legado')->where('relacion', 'canonico')->whereIn('destino_id', $certs->pluck('id'))->pluck('destino_id')->flip();

        $variantes = $certs->map(fn ($c) => [
            'id' => (int) $c->id,
            'nombre' => PresentadorConciliaciones::nombreEnmascarado($c->nombre_completo),
            'tipo_documento' => $c->tipo_documento,
            'documento' => PresentadorHistorico::documentoEnmascarado($c->documento),
            'correos' => ($correos[$c->id] ?? collect())->map(fn ($x) => PresentadorConciliaciones::correoEnmascarado($x))->values()->all(),
            'tiene_codigo' => $c->codigo_legado !== null && $c->codigo_legado !== '',
            'descargas' => (int) ($descargas[$c->id] ?? 0),
            'conciliacion' => ['estado' => $c->conciliacion_estado] + PresentadorHistorico::conciliacion($c->conciliacion_estado),
            'canonico' => $canonicos->has($c->id),
            'rol' => $c->rol,
        ])->values()->all();

        $difiere = fn (callable $f) => $certs->map($f)->unique()->count() > 1;
        $diferencias = array_values(array_filter([
            $difiere(fn ($c) => (string) $c->nombre_completo) ? 'Nombre' : null,
            $difiere(fn ($c) => (string) $c->tipo_documento) ? 'Tipo de documento' : null,
            $difiere(fn ($c) => (string) $c->documento) ? 'Documento impreso' : null,
            $difiere(fn ($c) => ($correos[$c->id] ?? collect())->sort()->implode('|')) ? 'Correo' : null,
            $difiere(fn ($c) => (string) $c->codigo_legado) ? 'Código' : null,
            $difiere(fn ($c) => (int) $c->evento_id) ? 'Evento' : null,
        ]));

        return [
            'variantes' => $variantes,
            'diferencias' => $diferencias,
            'nombre_igual_conservador' => $certs->map(fn ($c) => NombreConservador::normalizar((string) $c->nombre_completo))->unique()->count() === 1,
            'descargas_total' => $descargas->sum(),
            'canonico_actual' => $canonicos->isNotEmpty(),
            'consolidacion' => $this->bloqueConsolidacion($consol, $certs, $caso),
            'eventos' => $certs->pluck('evento_id')->unique()->map(fn ($id) => $evento($id))->filter()->values()->all(),
        ];
    }

    /**
     * Resumen para la pantalla del caso de código (10B-2A): mismo par, quién tiene el código histórico, descargas y canónico propuesto o ya
     * elegido. El código de verificación no es un dato sensible.
     *
     * @return array<string,mixed>
     */
    private function bloqueConsolidacion(?array $consol, Collection $certs, ?Conciliacion $caso = null): array
    {
        $explicaciones = [
            'codigo_consolidado' => ConsolidacionCodigo::EXPLICACION, 'correo_consolidado' => ConsolidacionVariantes::EXPLICACIONES[ConsolidacionVariantes::MODO_CORREO],
            'nombre_cosmetico_consolidado' => ConsolidacionVariantes::EXPLICACIONES[ConsolidacionVariantes::MODO_NOMBRE],
        ];
        $titulos = ['codigo' => 'Diferencia de código', 'correo' => 'Diferencia de correo', 'nombre_cosmetico' => 'Variación de nombre'];
        if ($certs->contains(fn ($c) => $c->rol === 'canonico')) {
            $resolucion = $caso?->resolucion ?? 'codigo_consolidado';

            return ['estado' => 'consolidada', 'aplicable' => true, 'modo' => array_search($resolucion, ['codigo' => 'codigo_consolidado', 'correo' => 'correo_consolidado', 'nombre_cosmetico' => 'nombre_cosmetico_consolidado'], true) ?: 'codigo',
                'titulo' => 'Variantes consolidadas', 'canonico_id' => (int) $certs->firstWhere('rol', 'canonico')->id, 'explicacion' => $explicaciones[$resolucion] ?? ConsolidacionCodigo::EXPLICACION];
        }
        if ($consol === null || ! $consol['aplicable']) {
            return ['estado' => 'no_aplica', 'aplicable' => false, 'nombre_real' => (bool) ($consol['nombre_real'] ?? false)];
        }
        $modo = $consol['modo'] ?? 'codigo';
        $explicacion = $modo === 'codigo'
            ? 'Ambas variantes son del mismo evento y documento; una tiene el código histórico y la otra no. En el sistema viejo el código se asignaba en la primera descarga a las filas que existían entonces, así que la fila sin código se agregó después: no es una diferencia de certificado.'
            : ConsolidacionVariantes::EXPLICACIONES[$modo];

        return [
            'estado' => 'propuesta', 'aplicable' => true, 'modo' => $modo, 'titulo' => $titulos[$modo] ?? 'Consolidación', 'mismo_par' => true, 'canonico_id' => $consol['canonico_id'], 'otras_ids' => $consol['otras_ids'], 'codigo' => $consol['codigo'],
            'eleccion_manual' => $modo === 'nombre_cosmetico', 'descargas_historicas' => $consol['descargas'], 'bloqueos' => array_column($consol['bloqueos'], 'mensaje'), 'explicacion' => $explicacion,
        ];
    }

    private function documento(Collection $certs, callable $evento): array
    {
        $c = $certs->first();
        if ($c === null) {
            return ['certificados' => []];
        }
        $snap = $this->snap($c);
        $descargas = $this->descargas($certs);
        $correos = $this->correos($certs);

        return [
            'certificados' => [[
                'id' => (int) $c->id,
                'motivo_tecnico' => ucfirst(PresentadorHistorico::motivoDocumento($snap['documento_estado'] ?? null, $snap['documento_detalle'] ?? null)).'.',
                'documento' => PresentadorHistorico::documentoEnmascarado($c->documento),
                'nombre' => PresentadorConciliaciones::nombreEnmascarado($c->nombre_completo),
                'conciliacion' => ['estado' => $c->conciliacion_estado] + PresentadorHistorico::conciliacion($c->conciliacion_estado),
                'estado' => PresentadorHistorico::estadoCertificado($c->estado),
                'evento' => $evento($c->evento_id),
                'descargas' => (int) ($descargas[$c->id] ?? 0),
                'correos' => PresentadorHistorico::indicadorCorreo(($correos[$c->id] ?? collect())->count(), 0),
                'en_grupo_duplicado' => $c->grupo_duplicado !== null,
            ]],
        ];
    }

    private function identidad(Collection $certs, callable $evento): array
    {
        $correos = $this->correos($certs);
        $porGrupo = $certs->groupBy('rol');
        // Cuántos grupos distintos tienen cada correo (para señalar los compartidos: SOLO indicador, nunca prueba de identidad).
        $gruposPorCorreo = [];
        foreach ($porGrupo as $rol => $filas) {
            foreach ($filas->flatMap(fn ($c) => $correos[$c->id] ?? collect())->unique() as $correo) {
                $gruposPorCorreo[$correo][$rol] = true;
            }
        }

        $grupos = [];
        $i = 0;
        foreach ($porGrupo as $rol => $filas) {
            $i++;
            $susCorreos = $filas->flatMap(fn ($c) => $correos[$c->id] ?? collect())->unique()->values();
            $grupos[] = [
                'etiqueta' => 'Grupo '.$i,
                'nombre' => PresentadorConciliaciones::nombreEnmascarado($filas->first()->nombre_completo),
                'certificados' => $filas->count(),
                'estados' => $filas->groupBy('conciliacion_estado')->map(fn ($g, $e) => ['estado' => $e] + PresentadorHistorico::conciliacion($e) + ['n' => $g->count()])->values()->all(),
                'eventos' => $this->eventosAcotados($filas->pluck('evento_id'), $evento),
                'correos' => $susCorreos->map(fn ($x) => ['mascara' => PresentadorConciliaciones::correoEnmascarado($x), 'compartido' => count($gruposPorCorreo[$x] ?? []) > 1])->all(),
            ];
        }

        return [
            'documento' => PresentadorHistorico::documentoEnmascarado($certs->first()?->documento),
            'grupos_conservadores' => count($grupos),
            'grupos' => $grupos,
            'eventos' => $this->eventosAcotados($certs->pluck('evento_id'), $evento),
            'correos_compartidos' => collect($gruposPorCorreo)->filter(fn ($g) => count($g) > 1)->count(),
            'nota' => 'Los grupos se forman solo con el nombre normalizado de forma conservadora (sin tildes, mayúsculas ni signos). No se usa similitud aproximada y un correo compartido no prueba identidad.',
        ];
    }

    /** Un documento puede abarcar cientos de eventos: se muestra el total y solo los primeros. @return array{total:int,items:list<array<string,mixed>>} */
    private function eventosAcotados($ids, callable $evento): array
    {
        $unicos = collect($ids)->unique()->values();

        return ['total' => $unicos->count(), 'items' => $unicos->take(self::EVENTOS_VISIBLES)->map(fn ($id) => $evento($id))->filter()->values()->all()];
    }

    private function plantilla(Conciliacion $caso, Collection $certs, callable $evento): array
    {
        $entrada = $caso->referencia_tipo === 'plantilla_legado' ? DB::table('cf_plantillas_legado as p')->leftJoin('cf_plantillas_legado_contenidos as k', 'k.id', '=', 'p.contenido_id')->where('p.id', (int) $caso->referencia_clave)
            ->first(['p.id', 'p.estado', 'p.extension_original', 'p.renderizable', 'p.motivo_no_renderizable', 'p.notas', 'k.sha256', 'k.mime_real', 'k.ancho_px', 'k.alto_px', 'k.bytes']) : null;

        $eventosDelCaso = $entrada === null ? $certs->pluck('evento_id')->unique() : DB::table('cf_eventos')->where('plantilla_legado_id', $entrada->id)->whereNull('deleted_at')->pluck('id');
        $eventos = DB::table('cf_eventos')->whereIn('id', $eventosDelCaso->all())->orderBy('id')->get(['id', 'nombre', 'anio'])
            ->map(fn ($e) => ['id' => (int) $e->id, 'nombre' => $e->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($e->anio === null ? null : (int) $e->anio)]);
        $porEvento = $certs->groupBy('evento_id')->map(fn ($g, $id) => ['evento' => $evento((int) $id), 'certificados' => $g->count()])->values()->all();

        $mensaje = match ($caso->tipo) {
            Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO => 'Contenido existente, tipo histórico no compatible.',
            Conciliacion::TIPO_PLANTILLA_CANDIDATA => 'Plantilla histórica no localizada. Hay una imagen candidata por revisar; no se ha asociado.',
            default => 'Plantilla histórica no localizada.',
        };

        $r = [
            'mensaje' => $mensaje,
            'estado_plantilla' => $entrada === null ? null : PresentadorHistorico::plantilla($entrada->estado),
            'eventos' => $eventos->values()->all(),
            'certificados_por_evento' => $porEvento,
            'certificados' => $certs->count(),
            'vista_previa' => ['disponible' => false, 'motivo' => 'La vista previa de imágenes históricas se habilitará cuando haya un mecanismo seguro de lectura (fase posterior).'],
        ];

        if ($caso->tipo === Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO && $entrada !== null) {
            $r['contenido'] = [
                'extension_historica' => PresentadorHistorico::nombreArchivo($entrada->extension_original, 24),
                'mime_real' => $entrada->mime_real,
                'sha' => PresentadorHistorico::shaAbreviado($entrada->sha256),
                'dimensiones' => $entrada->ancho_px === null ? null : "{$entrada->ancho_px} × {$entrada->alto_px} px",
                'bytes' => $entrada->bytes === null ? null : PresentadorHistorico::bytes((int) $entrada->bytes),
                'motivo' => PresentadorConciliaciones::motivo($entrada->motivo_no_renderizable),
                'renderizable_potencialmente' => RutasLegado::extensionPorMime($entrada->mime_real) !== null,
                'renderizable_actual' => (bool) $entrada->renderizable,
            ];
        }

        if ($caso->tipo === Conciliacion::TIPO_PLANTILLA_CANDIDATA && $entrada !== null) {
            $evidencia = ConsultaHistorica::evidenciaDe($entrada->notas);
            $sha = json_decode((string) $entrada->notas, true)['evidencia_candidata']['candidata_sha256'] ?? null;
            $cand = $sha === null ? null : DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->first(['id', 'sha256', 'mime_real', 'ancho_px', 'alto_px', 'bytes']);
            $r['candidata'] = [
                'sha' => PresentadorHistorico::shaAbreviado($sha),
                'mime' => $cand->mime_real ?? ($evidencia['mime'] ?? null),
                'dimensiones' => $cand === null || $cand->ancho_px === null ? ($evidencia['dimensiones'] ?? null) : "{$cand->ancho_px} × {$cand->alto_px} px",
                'bytes' => $cand === null ? ($evidencia['bytes'] ?? null) : PresentadorHistorico::bytes((int) $cand->bytes),
                'evidencia_migrador' => $evidencia === null ? null : ['similitud' => $evidencia['similitud'] ?? null, 'nota' => $evidencia['nota']],
                'asociada' => false,
            ];
        }

        return $r;
    }
}
