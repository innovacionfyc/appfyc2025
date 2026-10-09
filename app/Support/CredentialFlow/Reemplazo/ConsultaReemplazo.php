<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Conciliaciones\PresentadorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Generacion\GeneracionCredencialException;
use App\Support\CredentialFlow\Historico\PresentadorHistorico;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Legado\FormatoLegado;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Verificacion\UrlVerificacion;
use Illuminate\Support\Facades\DB;

/**
 * Datos de la pantalla «Emitir certificado corregido» (Fase 10B-2B-2B). SOLO LECTURA: no escribe nada. El documento histórico llega enmascarado; el
 * valor que se propone imprimir sí va completo (el formulario autorizado lo necesita para que el administrador lo apruebe). Nunca correos.
 */
final class ConsultaReemplazo
{
    public const AVISO_INMUTABLE = 'Esta acción no modifica el certificado histórico. Creará una nueva emisión moderna y marcará el anterior como reemplazado.';

    private const CATEGORIAS = [
        'DOC_WHITESPACE_CAMBIA_IMPRESION' => ['etiqueta' => 'Espacios o caracteres invisibles en el documento', 'ayuda' => 'El documento tiene un espacio de no separación u otro carácter invisible al inicio o al final. Es una diferencia puramente tipográfica: las letras y los dígitos son los mismos.'],
        'DOC_SEPARADORES' => ['etiqueta' => 'Documento con separadores', 'ayuda' => 'El documento se cargó con puntos u otros separadores. El sistema histórico lo habría impreso truncado.'],
        'DOC_OTRO' => ['etiqueta' => 'Documento con signos no estándar', 'ayuda' => 'El documento incluye signos poco habituales (por ejemplo un guion). Conviene confirmar que se trata del mismo número.'],
        ReglasValorAprobado::CAT_DIF_NOMBRE => ['etiqueta' => 'Nombres distintos para el mismo certificado', 'ayuda' => 'Las variantes históricas de este certificado imprimían nombres distintos. El sistema NO elige el correcto: tú apruebas exactamente el nombre que se imprimirá, con la evidencia que lo respalda. El documento no cambia y la identidad de la persona se decide por separado.'],
        'DOC_VACIO' => ['etiqueta' => 'Documento vacío', 'ayuda' => 'El certificado histórico no tiene documento. Solo puede emitirse un certificado corregido si se aporta, desde una fuente externa registrada, el documento correcto. No se infiere ni se abre acceso al portal: la entrega es administrativa.'],
        'DOC_LETRAS' => ['etiqueta' => 'Documento con letras y dígitos', 'ayuda' => 'El documento mezcla letras y dígitos. No existe una corrección automática segura: solo puede emitirse con un valor confirmado y la evidencia que lo respalde.'],
    ];

    private const REGLAS = [
        ReglasValorAprobado::DOC_SIN_NBSP => ['etiqueta' => 'Quitar el espacio de no separación', 'ayuda' => 'Se imprimirá el documento sin el carácter invisible; letras y dígitos no cambian.'],
        ReglasValorAprobado::DOC_CON_SEPARADORES => ['etiqueta' => 'Imprimir el valor aprobado (con separadores)', 'ayuda' => 'Se imprimirá exactamente el valor que apruebes abajo, que debe conservar las mismas letras y dígitos del documento histórico.'],
        ReglasValorAprobado::DOC_SIN_SIGNO => ['etiqueta' => 'Imprimir solo letras y dígitos (sin signos)', 'ayuda' => 'Se quitarán los signos del documento histórico. Exige confirmación reforzada: asegúrate de que el signo no era parte del número.'],
        ReglasValorAprobado::DOC_SIN_CAMBIO => ['etiqueta' => 'Imprimir el documento sin cambios', 'ayuda' => 'El documento histórico ya es imprimible: se imprime exactamente igual. Solo cambia el nombre aprobado.'],
        ReglasValorAprobado::DOC_MANUAL => ['etiqueta' => 'Valor confirmado manualmente (con evidencia)', 'ayuda' => 'Solo con evidencia externa del valor correcto. Describe la evidencia: queda registrada (solo su huella) en la auditoría.'],
    ];

    public function __construct(private readonly ReemplazoHistorico $servicio) {}

    /** @return array<string,mixed> */
    public function pantalla(Conciliacion $caso): array
    {
        $cert = $this->servicio->certificadoDelCaso($caso);
        $fila = DB::table('cf_certificados_legado')->where('id', $cert->id)->first();
        $modelo = CertificadoLegado::query()->findOrFail($cert->id);
        $categoria = (string) $caso->motivo_origen;
        $snap = json_decode((string) $fila->snapshot_legado, true) ?: [];
        $evento = DB::table('cf_eventos')->where('id', $fila->evento_id)->first(['nombre', 'anio']);

        $impresion = FormatoLegado::documento((string) $fila->documento);
        $clave = (string) $fila->documento_clave;
        $otros = DB::table('cf_certificados_legado')->where('documento_clave', $clave)->where('id', '!=', $fila->id)->get(['documento', 'nombre_completo']);
        $nombreBase = mb_strtolower(trim((string) $fila->nombre_completo));
        $reglas = array_map(fn (array $p) => $p + self::REGLAS[$p['regla']], ReglasValorAprobado::propuestas($categoria, $fila));
        $clon = CreadorPlantillaReemplazo::existentePara($modelo);
        $dif = $categoria === ReglasValorAprobado::CAT_DIF_NOMBRE;

        return [
            'caso' => ['id' => (int) $caso->id, 'estado' => $caso->estado, 'estado_info' => PresentadorConciliaciones::estado($caso->estado), 'tipo_info' => PresentadorConciliaciones::tipo($caso->tipo)],
            'aviso' => self::AVISO_INMUTABLE,
            'modo' => $dif ? 'dif_nombre' : 'documento',
            'dif_nombre' => $dif ? $this->difNombre($caso, $fila) : null,
            // Paso 1: evidencia (lo estrictamente necesario; sin correos).
            'evidencia' => [
                'categoria' => ['codigo' => $categoria] + (self::CATEGORIAS[$categoria] ?? ['etiqueta' => $categoria, 'ayuda' => '']),
                'documento_historico' => PresentadorHistorico::documentoEnmascarado($fila->documento),
                'tipo_documento' => $fila->tipo_documento,
                'motivo_tecnico' => ucfirst(PresentadorHistorico::motivoDocumento($snap['documento_estado'] ?? null, $snap['documento_detalle'] ?? null)).'.',
                'comportamiento_historico' => trim((string) $impresion) === ''
                    ? 'En el sistema histórico este documento se habría impreso en blanco.'
                    : 'En el sistema histórico este documento se habría impreso alterado ('.PresentadorHistorico::documentoEnmascarado($impresion).').',
                'estructura' => ['caracteres_historico' => mb_strlen((string) $fila->documento), 'caracteres_clave' => mb_strlen($clave), 'misma_clave_solo_letras_digitos' => Texto::claveDocumento((string) $fila->documento) === $clave],
                'corroboracion' => [
                    'otros_registros' => $otros->count(),
                    'con_documento_limpio' => $otros->filter(fn ($o) => (string) $o->documento === $clave)->count(),
                    'mismo_nombre' => $otros->filter(fn ($o) => mb_strtolower(trim((string) $o->nombre_completo)) === $nombreBase)->count(),
                ],
                'descargas_historicas' => DB::table('cf_descargas')->where('certificado_legado_id', $fila->id)->count(),
                'evento' => $evento === null ? null : ['nombre' => $evento->nombre, 'anio_etiqueta' => PresentadorHistorico::anio($evento->anio === null ? null : (int) $evento->anio)],
                'estado_certificado' => PresentadorHistorico::estadoCertificado($fila->estado),
                'bitacora' => $caso->eventos()->get(['accion', 'estado_nuevo', 'actor_id', 'created_at'])->map(fn ($e) => [
                    'accion' => $e->accion, 'estado_nuevo' => $e->estado_nuevo, 'actor' => $e->actor_id === null ? 'Detección automática' : 'Administración', 'fecha' => $e->created_at?->toDateTimeString(),
                ])->all(),
            ],
            // Paso 2: dato aprobado.
            'valor' => [
                'reglas' => $reglas,
                'nombre_impreso' => $dif ? null : (string) $fila->nombre_completo,
                'exige_evidencia_manual' => in_array($categoria, ['DOC_LETRAS', 'DOC_VACIO'], true),
                'evidencia_min' => ReglasValorAprobado::EVIDENCIA_MIN, 'evidencia_max' => $dif ? ReglasValorAprobado::EVIDENCIA_NOMBRE_MAX : ReglasValorAprobado::EVIDENCIA_MAX,
            ],
            // Pasos 3 y 4: plantilla y diseño.
            'plantilla' => [
                'clon' => $clon === null ? null : $this->infoPlantilla($clon),
                'puede_preparar' => $clon === null,
                'otras' => Plantilla::query()->whereNull('origen_legado_sha256')->orderBy('nombre')->limit(30)->get()->map(fn (Plantilla $p) => $this->infoPlantilla($p))->all(),
            ],
            'motivo' => ['min' => ResolucionPlantillas::MOTIVO_MIN, 'max' => ResolucionPlantillas::MOTIVO_MAX],
            'rutas' => [
                'caso' => route('credential-flow.historico.casos.show', $caso->id),
                'clon' => route('credential-flow.historico.casos.reemplazo.clon', $caso->id),
                'diseno' => route('credential-flow.historico.casos.reemplazo.diseno', $caso->id),
                'preview' => route('credential-flow.historico.casos.reemplazo.preview', $caso->id),
                'emitir' => route('credential-flow.historico.casos.reemplazo.emitir', $caso->id),
            ],
        ];
    }

    /**
     * DIF_NOMBRE: las variantes del certificado lógico para que el administrador ELIJA una (o escriba otra confirmada con evidencia). Los nombres en claro
     * solo salen aquí (pantalla de administración, `no-store`); no hay ninguna variante preseleccionada ni «sugerida». La identidad va aparte, solo con ids.
     *
     * @return array<string,mixed>
     */
    private function difNombre(Conciliacion $caso, object $fila): array
    {
        $variantes = DB::table('cf_certificados_legado as c')->join('cf_conciliaciones_certificados as k', 'k.certificado_legado_id', '=', 'c.id')->where('k.conciliacion_id', $caso->id)
            ->orderBy('c.id')->get(['c.id', 'c.nombre_completo', 'c.codigo_legado']);
        $descargas = DB::table('cf_descargas')->whereIn('certificado_legado_id', $variantes->pluck('id'))->groupBy('certificado_legado_id')->selectRaw('certificado_legado_id, COUNT(1) n')->pluck('n', 'certificado_legado_id');
        $normalizados = $variantes->map(fn ($v) => NombreConservador::normalizar((string) $v->nombre_completo));
        $identidad = ReemplazoHistorico::identidadDe((string) $fila->documento_clave);

        return [
            'variantes' => $variantes->map(fn ($v) => [
                'id' => (int) $v->id, 'nombre' => (string) $v->nombre_completo, 'nombre_enmascarado' => PresentadorConciliaciones::nombreEnmascarado($v->nombre_completo),
                'es_raiz' => (int) $v->id === (int) $fila->id, 'descargas' => (int) ($descargas[$v->id] ?? 0), 'tiene_codigo' => $v->codigo_legado !== null,
            ])->values()->all(),
            'diferencia' => $variantes->count() >= 2 ? EvidenciaIdentidad::clase($normalizados[0], $normalizados[1]) : null,
            'documento_sin_cambio' => PresentadorHistorico::documentoEnmascarado($fila->documento),
            'identidad' => [
                'casos' => array_map(fn (array $c) => $c + ['url' => route('credential-flow.historico.casos.show', $c['id'])], $identidad['casos']),
                'decisiones_vigentes' => count(array_filter($identidad['decisiones'], fn (array $d) => $d['estado'] === 'vigente')),
                'aviso' => 'Aprobar el nombre NO decide quién es la persona ni autoriza ningún correo. La identidad se resuelve por separado, en su propio caso.',
            ],
        ];
    }

    /**
     * Resultado de un reemplazo ya emitido (para el detalle del caso resuelto): referencia a la emisión, enlace de administración y de verificación.
     *
     * @return array<string,mixed>|null
     */
    public function resultado(Conciliacion $caso): ?array
    {
        $cubierto = $caso->resolucion === CoberturaCanonica::RESOLUCION;
        if ($caso->resolucion !== ReemplazoHistorico::RESOLUCION && ! $cubierto) {
            return null;
        }
        if ($cubierto) {
            // Caso de una variante cubierto por el reemplazo de su certificado principal: la emisión es la de la raíz del certificado lógico.
            $certId = (int) DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->value('certificado_legado_id');
            $emisionId = CertificadoLogico::reemplazada($certId)?->reemplazado_por_emision_id;
        } else {
            $emisionId = DB::table('cf_certificados_legado as c')->join('cf_conciliaciones_certificados as k', 'k.certificado_legado_id', '=', 'c.id')->where('k.conciliacion_id', $caso->id)->whereNotNull('c.reemplazado_por_emision_id')->value('c.reemplazado_por_emision_id');
        }
        $e = $emisionId === null ? null : DB::table('cf_emisiones')->where('id', $emisionId)->first(['id', 'codigo', 'estado', 'version', 'emitido_at']);
        if ($e === null) {
            return null;
        }
        $v = EmisionVigente::desdeEmision((int) $e->id);

        return [
            'cubierto_por_canonico' => $cubierto,
            'estado' => $e->estado, 'version' => (int) $e->version, 'codigo' => $e->codigo, 'emitido_at' => (string) $e->emitido_at,
            'url_pdf' => route('credential-flow.emisiones.descargar', $e->id), 'url_verificacion' => UrlVerificacion::para($e->codigo),
            'vigente_actual' => $v['vigente'] === null ? null : ['codigo' => $v['vigente']->codigo, 'es_la_misma' => (int) $v['vigente']->id === (int) $e->id],
        ];
    }

    /**
     * Relación del caso con su certificado LÓGICO (10B-2B-2C.1): si el certificado del caso es una VARIANTE de un grupo de duplicados, el reemplazo se
     * gestiona desde el caso del certificado principal (se enlaza). Solo ids y estados: nada personal. Null si el caso no tiene un certificado lógico de
     * varios registros.
     *
     * @return array<string,mixed>|null
     */
    public static function logico(Conciliacion $caso): ?array
    {
        if ($caso->tipo !== Conciliacion::TIPO_REVISION_DOCUMENTO) {
            return null;
        }
        $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        if (count($ids) !== 1) {
            return null;
        }
        $l = CertificadoLogico::de($ids[0]);
        if (count($l['miembros']) < 2) {
            return null;
        }
        $ref = CertificadoLogico::referencia($ids[0]);
        $principal = $ref['caso_raiz'];

        return [
            'es_variante' => $l['raiz'] !== $ids[0],
            'registros' => count($l['miembros']),
            'reemplazado' => CertificadoLogico::reemplazada($ids[0]) !== null,
            'mensaje' => $l['raiz'] !== $ids[0]
                ? 'Este registro pertenece al mismo certificado que otro registro histórico. El reemplazo se gestiona desde su certificado principal.'
                : 'Este certificado agrupa '.count($l['miembros']).' registros históricos del mismo certificado. El reemplazo se emite una sola vez, desde este registro.',
            'caso_principal' => $l['raiz'] === $ids[0] || $principal === null ? null : [
                'id' => $principal['id'], 'estado' => $principal['estado'], 'url' => route('credential-flow.historico.casos.show', $principal['id']),
                'referencia' => $principal['resolucion'] === ReemplazoHistorico::RESOLUCION ? 'Reemplazo emitido' : 'Sin reemplazo todavía',
            ],
        ];
    }

    /** @return array<string,mixed> */
    private function infoPlantilla(Plantilla $p): array
    {
        $d = is_array($p->diseno) ? $p->diseno : null;
        $campos = $d === null ? [] : collect($d['elements'] ?? [])->where('type', DisenoSchema::TIPO_TEXTO)->pluck('field')->filter()->unique()->values()->all();
        $esClon = $p->origen_legado_sha256 !== null;
        $meta = (array) $p->origen_legado_meta;

        return [
            'id' => (int) $p->id, 'nombre' => $p->nombre, 'es_clon' => $esClon, 'tiene_diseno' => $d !== null,
            'diseno_confirmado' => $esClon ? CreadorPlantillaReemplazo::disenoConfirmado($p) : $d !== null,
            'campos_usados' => $campos, 'requiere_fecha' => in_array('fecha', $campos, true), 'requiere_intensidad' => in_array('intensidad_horaria', $campos, true),
            'imprime_evento' => in_array('evento', $campos, true),
            'advertencias' => (array) ($meta['advertencias'] ?? []),
            'meta' => $esClon ? [
                'algoritmo' => $meta['algoritmo'] ?? null, 'ancho_px' => $meta['ancho_px_origen'] ?? null, 'alto_px' => $meta['alto_px_origen'] ?? null, 'mime' => $meta['mime'] ?? null,
                'bytes_origen' => $meta['bytes_origen'] ?? null, 'bytes_pdf' => $meta['bytes_derivado'] ?? null, 'generado_at' => $meta['generado_at'] ?? null,
                'sha_origen' => PresentadorHistorico::shaAbreviado($meta['sha256_origen'] ?? null),
            ] : null,
            'url_editor' => route('credential-flow.plantillas.editor', $p->id),
        ];
    }

    /** Pre-comprobación usada por el detalle del caso (sin cargar la pantalla). */
    public function elegible(Conciliacion $caso): array
    {
        return $this->servicio->elegible($caso);
    }

    /** Mapea un error técnico del reemplazo a un mensaje claro para el administrador (sin códigos internos ni rastros). */
    public static function mensaje(\Throwable $e): string
    {
        if ($e instanceof ResolucionNoPermitida) {
            return match ($e->codigo) {
                ResolucionNoPermitida::CASO_YA_RESUELTO => 'Este caso ya fue resuelto.',
                ResolucionNoPermitida::YA_REEMPLAZADO => 'Este certificado ya fue reemplazado.',
                ResolucionNoPermitida::CAMPO_SIN_VALOR => 'Falta completar un dato requerido por la plantilla.',
                ResolucionNoPermitida::DATO_NO_IMPRIMIBLE => 'El diseño o los datos incluyen texto que la fuente de la plantilla no puede imprimir. Revisa el texto o el diseño.',
                ResolucionNoPermitida::PREVIEW_DESACTUALIZADO => 'Los datos cambiaron. Genere nuevamente la vista previa.',
                ResolucionNoPermitida::PREVIEW_REQUERIDO => 'Genere la vista previa antes de emitir el reemplazo.',
                ResolucionNoPermitida::DISENO_NO_CONFIRMADO => 'Revisa el diseño de la plantilla moderna y confírmalo antes de emitir.',
                ResolucionNoPermitida::ACTOR_NO_AUTORIZADO => 'No tienes permiso para emitir reemplazos.',
                default => $e->getMessage(),
            };
        }
        if ($e instanceof GeneracionCredencialException) {
            return match ($e->codigo) {
                GeneracionCredencialException::CARACTER_NO_SOPORTADO => 'El diseño contiene texto no compatible con la fuente de la plantilla.',
                GeneracionCredencialException::CAMPO_SIN_VALOR => 'Falta completar un dato requerido por la plantilla.',
                default => 'No se pudo generar el certificado con esta plantilla. Revisa el diseño.',
            };
        }
        if ($e instanceof EmisionException) {
            return $e->getMessage();
        }

        return 'No se pudo completar la acción. No se cambió nada.';
    }
}
