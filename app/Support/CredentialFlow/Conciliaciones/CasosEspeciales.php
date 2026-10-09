<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\DecisionIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\GruposSinVia;
use Illuminate\Support\Facades\DB;

/**
 * CLASIFICACIÓN (solo lectura) de los casos que NO se resuelven de forma automática (Fase 10B-3C-4). No escribe nada, no concede acceso y no inventa estados:
 * reutiliza `requiere_soporte`, `descartado`, `resuelto` y las decisiones terminales (`requiere_soporte`, `no_resoluble`) y solo CALCULA, con el motivo real del caso,
 * a dónde debe ir cada uno y qué falta.
 *
 * Principio: un caso sin evidencia suficiente NO se «resuelve» técnicamente; termina en soporte, no resoluble, pendiente de evidencia externa, dependencia técnica
 * (una plantilla) o descartado. Jamás se infiere identidad: ni por parecido de nombres, ni copiando el correo de un grupo hermano, ni inventando un documento, ni
 * usando descargas, códigos o tokens como prueba. Un caso está RESUELTO solo cuando hay un resultado verificable (emisión de reemplazo, consolidación, `identidad_aplicada`):
 * una nota de un administrador no lo resuelve.
 *
 * `destino` (conceptual, sin PII):
 *  resuelto · descartado · descartable · recuperable_por_decision · recuperable_masivo · recuperable_por_reemplazo · recuperable_por_consolidacion ·
 *  requiere_soporte · no_resoluble · pendiente_evidencia_externa · dependencia_tecnica
 */
final class CasosEspeciales
{
    public const RESUELTO = 'resuelto';

    public const DESCARTADO = 'descartado';

    public const DESCARTABLE = 'descartable';

    public const RECUP_DECISION = 'recuperable_por_decision';

    public const RECUP_MASIVO = 'recuperable_masivo';

    public const RECUP_REEMPLAZO = 'recuperable_por_reemplazo';

    public const RECUP_CONSOLIDACION = 'recuperable_por_consolidacion';

    public const SOPORTE = 'requiere_soporte';

    public const NO_RESOLUBLE = 'no_resoluble';

    public const PEND_EXTERNA = 'pendiente_evidencia_externa';

    public const DEP_TECNICA = 'dependencia_tecnica';

    public const ETIQUETAS = [
        'fila_residual' => 'Grupo residual: su correo ya se autorizó a otro grupo',
        'nombres_distintos' => 'Nombres realmente distintos con un correo compartido',
        'sin_correo' => 'Grupo sin correo histórico',
        'disputa_correo' => 'Grupos en disputa por el mismo único correo',
        'documento_invalido' => 'Documento inválido o vacío',
        'documento_vacio_con_nombre' => 'Documento vacío con nombre',
        'documento_vacio_sin_evidencia' => 'Documento vacío sin nombre ni evidencia',
        'documento_texto_no_identificador' => 'El «documento» es solo texto',
        'dependencia_tecnica' => 'Depende de resolver la plantilla',
        'masivo' => 'Documento con cientos de certificados',
        'dif_nombre' => 'Nombre diferente en el mismo evento',
        'dif_nombre_real' => 'Nombres realmente distintos del mismo certificado',
        'correo_compartido' => 'Grupo sin vía: correo compartido',
        'identidad_recuperable' => 'Identidad ambigua recuperable con una decisión',
        'documento_recuperable' => 'Documento corregible con un certificado nuevo',
        'variantes_consolidables' => 'Variantes consolidables',
        'variantes_mixtas' => 'Variantes con varias diferencias',
        'otro' => 'Otro',
    ];

    /**
     * @return array{categoria:string,etiqueta:string,especial:bool,destino:string,dependencia:string,falta:?string,siguiente:?string,admite_evidencia:bool,solo_terminales:bool,evidencia_registrada:bool}
     */
    public function clasificar(Conciliacion $caso): array
    {
        $c = $this->categoria($caso);
        $evidencia = EvidenciaExterna::tieneVigente((int) $caso->id);
        $destino = match (true) {
            $caso->estado === Conciliacion::RESUELTO => self::RESUELTO,
            $caso->estado === Conciliacion::DESCARTADO => self::DESCARTADO,
            default => $this->destinoAbierto($caso, $c, $evidencia),
        };
        $cerrado = in_array($caso->estado, [Conciliacion::RESUELTO, Conciliacion::DESCARTADO], true);

        return [
            'categoria' => $c['categoria'], 'etiqueta' => self::ETIQUETAS[$c['categoria']] ?? self::ETIQUETAS['otro'], 'especial' => $c['especial'], 'destino' => $destino, 'dependencia' => $c['dependencia'],
            'falta' => $cerrado ? null : $c['falta'], 'siguiente' => $cerrado ? null : $c['siguiente'], 'admite_evidencia' => ! $cerrado && $c['admite_evidencia'],
            'solo_terminales' => $c['solo_terminales'], 'evidencia_registrada' => $evidencia,
        ];
    }

    /** @param array<string,mixed> $c */
    private function destinoAbierto(Conciliacion $caso, array $c, bool $evidencia): string
    {
        // Una decisión terminal vigente manda: es la decisión explícita del administrador.
        $terminal = $caso->tipo === Conciliacion::TIPO_IDENTIDAD_AMBIGUA
            ? DB::table('cf_decisiones_identidad')->where('conciliacion_id', $caso->id)->where('estado', DecisionIdentidad::VIGENTE)->whereIn('tipo', DecisionIdentidad::TERMINALES)->value('tipo') : null;
        if ($terminal === DecisionIdentidad::NO_RESOLUBLE) {
            return self::NO_RESOLUBLE;
        }
        if ($terminal === DecisionIdentidad::REQUIERE_SOPORTE) {
            return self::SOPORTE;
        }
        if ($c['destino'] === self::PEND_EXTERNA && $evidencia) {
            return self::SOPORTE;   // ya llegó evidencia: lo que sigue es una decisión administrativa explícita, en soporte
        }

        return $c['destino'];
    }

    /**
     * @return array{categoria:string,especial:bool,destino:string,dependencia:string,falta:?string,siguiente:?string,admite_evidencia:bool,solo_terminales:bool}
     */
    private function categoria(Conciliacion $caso): array
    {
        $def = fn (string $categoria, bool $especial, string $destino, string $dependencia, ?string $falta = null, ?string $siguiente = null, bool $evidencia = false, bool $terminales = false) => [
            'categoria' => $categoria, 'especial' => $especial, 'destino' => $destino, 'dependencia' => $dependencia, 'falta' => $falta, 'siguiente' => $siguiente, 'admite_evidencia' => $evidencia, 'solo_terminales' => $terminales,
        ];
        $motivo = (string) $caso->motivo_origen;

        switch ($caso->tipo) {
            case Conciliacion::TIPO_PLANTILLA_CANDIDATA:
            case Conciliacion::TIPO_PLANTILLA_FALTANTE:
            case Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO:
                return $def('dependencia_tecnica', true, self::DEP_TECNICA, 'tecnica', 'Resolver la plantilla del evento (aprobar la candidata, aportar la imagen o confirmar el tipo).', 'Usar la acción de plantilla de este caso. No requiere ninguna decisión de identidad.');
            case Conciliacion::TIPO_REVISION_DOCUMENTO:
                $e = (new GestionCaso)->evaluar($caso);
                if ($motivo === 'DOC_VACIO') {
                    return $e['accion'] === GestionCaso::DESCARTE
                        ? $def('documento_vacio_sin_evidencia', true, self::DESCARTABLE, 'ninguna', 'Nada: no hay nombre, correo, código, descarga ni encuesta.', 'Descartar el caso (el certificado histórico permanece intacto y bloqueado).')
                        : $def('documento_vacio_con_nombre', true, self::PEND_EXTERNA, 'externa', 'El documento correcto de la persona, aportado por una fuente externa. No se infiere.', 'Marcar «requiere soporte». Con el documento correcto se puede emitir un certificado corregido manualmente (sin abrir acceso al portal).', true);
                }
                if ($e['accion'] === GestionCaso::SOPORTE) {
                    return $def('documento_texto_no_identificador', true, self::PEND_EXTERNA, 'externa', 'Un documento de identidad real, aportado por una fuente externa.', 'Marcar «requiere soporte».', true);
                }

                return $def('documento_recuperable', false, self::RECUP_REEMPLAZO, 'administrativa');
            case Conciliacion::TIPO_CONFLICTO_VARIANTES:
                $motivos = array_map('trim', explode(',', $motivo));
                if ($motivos === ['DIF_NOMBRE'] && (new GestionCaso)->evaluar($caso)['accion'] === GestionCaso::SOPORTE) {
                    return $def('dif_nombre_real', false, self::RECUP_REEMPLAZO, 'administrativa');
                }

                return count($motivos) === 1 ? $def('variantes_consolidables', false, self::RECUP_CONSOLIDACION, 'administrativa') : $def('variantes_mixtas', false, self::SOPORTE, 'administrativa');
            case Conciliacion::TIPO_IDENTIDAD_AMBIGUA:
                return $this->identidad($caso, $motivo, $def);
        }

        return $def('otro', false, self::SOPORTE, 'administrativa');
    }

    /** @return array<string,mixed> */
    private function identidad(Conciliacion $caso, string $motivo, callable $def): array
    {
        if ($motivo === 'DOCUMENTO_INVALIDO') {
            return $def('documento_invalido', true, self::SOPORTE, 'externa', 'El documento correcto de cada persona, aportado por una fuente externa. El portal no puede autenticar un documento vacío ni se infiere.',
                'Mantener «requiere soporte». Con el documento correcto, el caso de cada fila admite un certificado corregido manual; el portal NO crea ninguna identidad.', true, true);
        }
        if ($motivo === GruposSinVia::MOTIVO_COMPARTIDO) {
            return $def('correo_compartido', false, self::RECUP_DECISION, 'administrativa');
        }
        if ($motivo === GruposSinVia::MOTIVO_SIN_CORREO) {
            return $def('sin_correo', true, self::PEND_EXTERNA, 'externa', 'Un correo propio del grupo y evidencia externa de que pertenece a la persona. No se copia el correo de otro grupo.',
                'Mantener «requiere soporte». Con esa evidencia haría falta una asociación moderna de correo (no implementada en esta fase).', true, true);
        }
        if ($motivo === GruposSinVia::MOTIVO_EVIDENCIA) {
            $sub = $this->subcausa($caso);

            return match ($sub) {
                'correo_autorizado_a_otro_grupo' => $def('fila_residual', true, self::PEND_EXTERNA, 'externa', 'Evidencia externa de que este grupo es la misma persona o de que tiene un correo propio. Su correo ya se autorizó a otro grupo.',
                    'Mantener «requiere soporte». No comparte el scope de la autorización del otro grupo.', true, true),
                'disputa_mismo_unico_correo' => $def('disputa_correo', true, self::PEND_EXTERNA, 'externa', 'Evidencia externa de quién es el titular del único correo. Un correo autoriza un solo grupo.',
                    'Mantener «requiere soporte». Si se autoriza el correo a un grupo, el otro sigue bloqueado.', true, true),
                default => $def('nombres_distintos', true, self::PEND_EXTERNA, 'externa', 'Evidencia externa de que los grupos son la misma persona. El parecido de nombres no basta.',
                    'Mantener «requiere soporte» o marcarlo «no resoluble».', true, true),
            };
        }

        // Caso base `CORREO_CRUZA_GRUPOS`: por los riesgos medidos en sus grupos.
        $ev = EvidenciaIdentidad::de($caso);
        $r = $ev['riesgos'];
        if ($r['nombres_distintos']) {
            return $def('nombres_distintos', true, self::PEND_EXTERNA, 'externa', 'Evidencia externa de que los grupos son la misma persona. El parecido de nombres no basta.', 'Marcar «requiere soporte» o «no resoluble»; no se abre acceso.', true, true);
        }
        if ($r['masivo']) {
            return $def('masivo', false, self::RECUP_MASIVO, 'administrativa');
        }
        if ($r['mismo_evento']) {
            return $def('dif_nombre', false, self::RECUP_REEMPLAZO, 'administrativa');
        }

        return $def('identidad_recuperable', false, self::RECUP_DECISION, 'administrativa');
    }

    /** Subcausa del grupo objetivo de un caso `GRUPO_SIN_VIA_EVIDENCIA_EXTERNA` (según el análisis de hoy). */
    private function subcausa(Conciliacion $caso): string
    {
        // La subcausa se guarda al detectar el caso (no depende de que los interruptores estén encendidos después).
        $guardada = json_decode((string) DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'detectado')->orderBy('id')->value('evidencia'), true)['subcausa'] ?? null;
        if (is_string($guardada) && $guardada !== '') {
            return $guardada;
        }
        $clave = (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso->id)->orderBy('k.id')->value('k.documento_clave');
        if ($clave === '') {
            return 'nombre_distinto_o_mismo_evento';
        }
        $sub = collect(app(GruposSinVia::class)->analizarDocumento($clave, true))->first(fn ($x) => $x['causa'] === 'evidencia_externa')['subcausa'] ?? null;

        return $sub ?? 'nombre_distinto_o_mismo_evento';
    }

    /**
     * Datos de la pantalla del caso especial (sin PII). Null si el caso se resuelve con las acciones normales.
     *
     * @return array<string,mixed>|null
     */
    public function paraPantalla(Conciliacion $caso): ?array
    {
        $c = $this->clasificar($caso);
        if (! $c['especial']) {
            return null;
        }
        $abierto = in_array($caso->estado, [Conciliacion::ABIERTO, Conciliacion::REQUIERE_SOPORTE], true);

        return [
            'titulo' => 'Este caso no puede resolverse automáticamente.',
            'categoria' => $c['categoria'], 'etiqueta' => $c['etiqueta'], 'destino' => $c['destino'], 'destino_etiqueta' => self::etiquetaDestino($c['destino']), 'dependencia' => $c['dependencia'],
            'motivo' => PresentadorConciliaciones::motivo($caso->motivo_origen), 'falta' => $c['falta'], 'siguiente' => $c['siguiente'], 'solo_terminales' => $c['solo_terminales'],
            'evidencias' => EvidenciaExterna::delCaso((int) $caso->id),
            'puede_registrar_evidencia' => $abierto && $c['admite_evidencia'],
            'puede_reabrir' => $this->puedeReabrir($caso),
            'fuentes' => EvidenciaExterna::FUENTES, 'resumen_min' => EvidenciaExterna::RESUMEN_MIN, 'resumen_max' => EvidenciaExterna::RESUMEN_MAX, 'motivo_min' => ResolucionPlantillas::MOTIVO_MIN, 'motivo_max' => ResolucionPlantillas::MOTIVO_MAX,
            'rutas' => [
                'evidencia' => route('credential-flow.historico.casos.especial.evidencia', $caso->id),
                'reabrir' => route('credential-flow.historico.casos.especial.reabrir', $caso->id),
                'invalidar' => collect(EvidenciaExterna::delCaso((int) $caso->id))->where('estado', 'vigente')->mapWithKeys(fn ($e) => [$e['id'] => route('credential-flow.historico.casos.especial.invalidar-evidencia', [$caso->id, $e['id']])])->all(),
            ],
        ];
    }

    public static function etiquetaDestino(string $destino): string
    {
        return [
            self::RESUELTO => 'Resuelto', self::DESCARTADO => 'Descartado', self::DESCARTABLE => 'Descartable (sin datos útiles)', self::RECUP_DECISION => 'Recuperable con una decisión', self::RECUP_MASIVO => 'Recuperable con autorización masiva',
            self::RECUP_REEMPLAZO => 'Recuperable con un certificado corregido', self::RECUP_CONSOLIDACION => 'Recuperable con una consolidación', self::SOPORTE => 'Requiere soporte', self::NO_RESOLUBLE => 'No resoluble con los datos actuales',
            self::PEND_EXTERNA => 'Pendiente de evidencia externa', self::DEP_TECNICA => 'Depende de resolver la plantilla',
        ][$destino] ?? $destino;
    }

    /**
     * ¿Se puede reabrir un caso en soporte? Solo los gestionados por `GestionCaso` (documentales / DIF_NOMBRE), cuando llegó evidencia externa VIGENTE DESPUÉS de la
     * transición a soporte. Las decisiones terminales de identidad se deshacen revocándolas (ya existe).
     */
    public function puedeReabrir(Conciliacion $caso): bool
    {
        if ($caso->estado !== Conciliacion::REQUIERE_SOPORTE || $caso->tipo === Conciliacion::TIPO_IDENTIDAD_AMBIGUA) {
            return false;
        }
        $eventos = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->orderBy('id')->get(['id', 'accion', 'evidencia']);
        $soporte = $eventos->where('accion', GestionCaso::ACCION_SOPORTE)->last();
        if ($soporte === null) {
            return false;
        }
        $invalidadas = $eventos->where('accion', EvidenciaExterna::ACCION_INVALIDADA)->map(fn ($e) => (int) (json_decode((string) $e->evidencia, true)['evidencia_id'] ?? 0))->all();

        return $eventos->where('accion', EvidenciaExterna::ACCION_REGISTRADA)->contains(fn ($e) => $e->id > $soporte->id && ! in_array((int) $e->id, $invalidadas, true));
    }

    /**
     * FOTO GLOBAL (sin PII): todos los casos por estado real, por categoría y por destino conceptual, más los grupos sin vía medidos con el read-model de 3C-1.
     *
     * @return array<string,mixed>
     */
    public function foto(): array
    {
        $casos = Conciliacion::query()->orderBy('id')->get();
        $porEstado = [];
        $porDestino = [];
        $porCategoria = [];
        $porTipo = [];
        $cruzada = [];
        foreach ($casos as $caso) {
            $c = $this->clasificar($caso);
            $porEstado[$caso->estado] = ($porEstado[$caso->estado] ?? 0) + 1;
            $porDestino[$c['destino']] = ($porDestino[$c['destino']] ?? 0) + 1;
            $porCategoria[$c['categoria']] = ($porCategoria[$c['categoria']] ?? 0) + 1;
            $porTipo[$caso->tipo] = ($porTipo[$caso->tipo] ?? 0) + 1;
            $cruzada[$caso->tipo.' / '.$c['categoria']][$c['destino']] = ($cruzada[$caso->tipo.' / '.$c['categoria']][$c['destino']] ?? 0) + 1;
        }
        ksort($cruzada);

        return [
            'casos' => $casos->count(), 'por_estado' => $porEstado, 'por_destino' => $porDestino, 'por_categoria' => $porCategoria, 'por_tipo' => $porTipo, 'categoria_destino' => $cruzada,
            'certificados' => [
                'por_estado' => DB::table('cf_certificados_legado')->selectRaw('conciliacion_estado, count(*) n')->groupBy('conciliacion_estado')->pluck('n', 'conciliacion_estado')->all(),
                'reemplazados' => DB::table('cf_certificados_legado')->whereNotNull('reemplazado_por_emision_id')->count(),
            ],
            'identidad_aplicada' => $casos->where('resolucion', 'identidad_aplicada')->count(),
            'decisiones_vigentes' => DB::table('cf_decisiones_identidad')->where('estado', 'vigente')->count(),
            'grupos_sin_via' => $this->gruposSinVia(),
        ];
    }

    /** Destino de los casos de identidad ORIGINALES (los bloqueados por completo + el documento inválido), sin aplicar ninguna decisión. @return array<string,int> */
    public function identidadesOriginales(): array
    {
        $r = [];
        foreach (Conciliacion::query()->where('tipo', Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->where(fn ($q) => $q->whereNull('motivo_origen')->orWhere('motivo_origen', 'not like', 'GRUPO_SIN_VIA_%'))->orderBy('id')->get() as $caso) {
            $c = $this->clasificar($caso);
            $r[$c['categoria'].' → '.$c['destino']] = ($r[$c['categoria'].' → '.$c['destino']] ?? 0) + 1;
        }
        ksort($r);

        return $r;
    }

    /**
     * Grupos sin vía de documentos parciales (read-model de 3C-1) con el destino final de cada uno.
     *
     * @return array<string,mixed>
     */
    public function gruposSinVia(): array
    {
        $rep = app(GruposSinVia::class)->reporte();
        $destinos = [];
        foreach ($rep['items'] as $i) {
            $k = $i['causa'] === 'evidencia_externa' ? 'evidencia_externa' : $i['causa'];
            $destinos[$k] = ($destinos[$k] ?? 0) + 1;
        }
        ksort($destinos);

        return ['grupos_sin_via' => $rep['grupos_sin_via'], 'grupos_accesibles' => $rep['grupos_accesibles'], 'documentos_parciales' => $rep['accesibles_sin_caso'], 'por_causa' => $destinos,
            'destino' => ['correo_compartido' => 'recuperable con correo_autorizado (3C-1)', 'evidencia_externa' => 'pendiente de evidencia externa', 'sin_correo' => 'pendiente de evidencia externa (sin correo)', 'sin_fila_habilitante' => 'dependencia técnica (plantilla)']];
    }
}
