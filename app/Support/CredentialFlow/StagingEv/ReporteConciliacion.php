<?php

namespace App\Support\CredentialFlow\StagingEv;

use Illuminate\Support\Facades\DB;

/**
 * Reporte de conciliación del staging. NO contiene datos personales: solo conteos, distribuciones de valores que no
 * identifican a nadie (tipo de documento, extensión, año) e ids internos (ids viejos de evento, grupos de duplicados).
 * Nunca imprime nombres, documentos, correos, tokens ni textos de encuesta.
 */
final class ReporteConciliacion
{
    private const VIGENTE = "estado_fila <> 'ausente_en_origen'";

    /** @return array<string,mixed> */
    public function generar(): array
    {
        $snap = DB::table('stg_ev_snapshots')->orderByDesc('id')->first();

        return [
            'snapshot' => $snap === null ? null : [
                'etiqueta' => $snap->etiqueta,
                'dump_archivo' => $snap->dump_archivo,
                'dump_sha256' => $snap->dump_sha256,
                'tomado_at' => $snap->tomado_at,
                'servidor_origen' => $snap->servidor_origen,
                'esquema_sha256' => $snap->esquema_sha256,
                'inventario_imagenes_sha256' => $snap->inventario_imagenes_sha256,
                'norm_version' => (int) $snap->norm_version,
                'conteos_origen' => json_decode($snap->conteos, true)['origen'] ?? [],
            ],
            'huella_contenido' => $this->huella(),
            'huella_derivada' => $this->huellaDerivada(),
            'entidades' => $this->entidades(),
            'participantes' => $this->participantes(),
            'correos' => $this->correos(),
            'duplicados' => $this->duplicados(),
            'eventos' => $this->eventos(),
            'imagenes' => $this->imagenes(),
            'codigo_legado' => $this->codigoLegado(),
            'descargas' => $this->descargas(),
            'tokens' => $this->tokens(),
            'encuestas' => $this->encuestas(),
        ];
    }

    /** SHA-256 del contenido vigente (entidad|clave|hash_fila, ordenado): idéntico entre cargas del mismo snapshot. */
    private function huella(): string
    {
        $lineas = [];
        foreach (['evento', 'preguntas', 'opciones', 'participante', 'token', 'descargas', 'encuesta'] as $e) {
            foreach (DB::table("stg_ev_{$e}")->whereRaw(self::VIGENTE)->orderBy('old_id')->select(['old_id', 'hash_fila'])->cursor() as $f) {
                $lineas[] = "{$e}|{$f->old_id}|{$f->hash_fila}";
            }
        }
        foreach (DB::table('stg_ev_imagenes')->whereRaw(self::VIGENTE)->orderBy('ruta_relativa')->select(['ruta_relativa', 'hash_fila'])->cursor() as $f) {
            $lineas[] = "imagenes|{$f->ruta_relativa}|{$f->hash_fila}";
        }

        return hash('sha256', implode("\n", $lineas));
    }

    /**
     * SHA-256 de lo DERIVADO de las filas vigentes de participantes y eventos (estados de documento y correo, firma de correos,
     * grupo de duplicados, validación y motivos). A diferencia de huella_contenido (que solo mira el origen y por tanto no
     * cambia si cambia una regla), esta cambia cuando cambia cualquier regla de normalización o validación.
     */
    private function huellaDerivada(): string
    {
        $clasif = DB::table('stg_ev_duplicados')->pluck('clasificacion', 'id')->all();
        $lineas = [];
        foreach (DB::table('stg_ev_participante')->whereRaw(self::VIGENTE)->orderBy('old_id')
            ->select(['old_id', 'documento_estado', 'documento_detalle', 'documento_normalizado_ws', 'documento_clave', 'correo_estado', 'correos_candidatos', 'correos_validos', 'correos_firma', 'grupo_duplicado_id', 'validacion', 'motivo'])->cursor() as $f) {
            $lineas[] = implode('|', ['p', $f->old_id, $f->documento_estado, $f->documento_detalle, (int) $f->documento_normalizado_ws, $f->documento_clave, $f->correo_estado,
                $f->correos_candidatos, $f->correos_validos, $f->correos_firma, $f->grupo_duplicado_id === null ? '' : ($clasif[$f->grupo_duplicado_id] ?? ''), $f->validacion, $f->motivo]);
        }
        foreach (DB::table('stg_ev_evento')->whereRaw(self::VIGENTE)->orderBy('old_id')
            ->select(['old_id', 'imagen_estado', 'anio_deducido', 'anio_origen', 'participantes_count', 'validacion', 'motivo'])->cursor() as $f) {
            $lineas[] = implode('|', ['e', $f->old_id, $f->imagen_estado, $f->anio_deducido, $f->anio_origen, $f->participantes_count, $f->validacion, $f->motivo]);
        }

        return hash('sha256', implode("\n", $lineas));
    }

    /** @return array<string,array<string,int>> */
    private function entidades(): array
    {
        $r = [];
        foreach (['evento', 'preguntas', 'opciones', 'participante', 'token', 'descargas', 'encuesta', 'imagenes'] as $e) {
            $t = DB::table("stg_ev_{$e}");
            $r[$e] = [
                'total' => (clone $t)->count(),
                'vigentes' => (clone $t)->whereRaw(self::VIGENTE)->count(),
                'nueva' => (clone $t)->where('estado_fila', 'nueva')->count(),
                'igual' => (clone $t)->where('estado_fila', 'igual')->count(),
                'cambiada' => (clone $t)->where('estado_fila', 'cambiada')->count(),
                'ausente_en_origen' => (clone $t)->where('estado_fila', 'ausente_en_origen')->count(),
                'validacion_ok' => (clone $t)->whereRaw(self::VIGENTE)->where('validacion', 'ok')->count(),
                'validacion_advertencia' => (clone $t)->whereRaw(self::VIGENTE)->where('validacion', 'advertencia')->count(),
                'validacion_error' => (clone $t)->whereRaw(self::VIGENTE)->where('validacion', 'error')->count(),
            ];
        }
        $r['historial_versiones'] = ['total' => DB::table('stg_ev_historial')->count()];

        return $r;
    }

    /** @return array<string,mixed> */
    private function participantes(): array
    {
        $p = fn () => DB::table('stg_ev_participante')->whereRaw(self::VIGENTE);
        $conMotivo = fn (string $codigo) => $p()->where(function ($q) use ($codigo) {
            $q->where('motivo', $codigo)->orWhere('motivo', 'like', $codigo.',%')->orWhere('motivo', 'like', '%,'.$codigo)->orWhere('motivo', 'like', '%,'.$codigo.',%');
        })->count();

        $detalle = $p()->where('documento_estado', 'anomalo')->selectRaw('documento_detalle d, COUNT(*) n')->groupBy('documento_detalle')->orderBy('documento_detalle')->pluck('n', 'd')->all();
        $tipos = $p()->selectRaw('tipo_documento t, COUNT(*) n')->groupBy('tipo_documento')->orderByDesc('n')->get()
            ->mapWithKeys(fn ($x) => [($x->t ?? '(sin tipo)') => (int) $x->n])->all();

        return [
            'total' => $p()->count(),
            'documento' => [
                'valido' => $p()->where('documento_estado', 'valido')->count(),
                'vacio' => $p()->where('documento_estado', 'vacio')->count(),
                'anomalo' => $p()->where('documento_estado', 'anomalo')->count(),
                'anomalo_detalle' => array_map('intval', $detalle),
                'REVISION_DOCUMENTO' => $conMotivo('REVISION_DOCUMENTO'),
                'DOCUMENTO_NORMALIZADO_WHITESPACE' => $conMotivo('DOCUMENTO_NORMALIZADO_WHITESPACE'),
                'con_whitespace_en_el_original' => $p()->whereNotNull('documento_whitespace')->count(),
                'whitespace_por_tipo' => array_map('intval', $p()->whereNotNull('documento_whitespace')->selectRaw('documento_whitespace w, COUNT(*) n')->groupBy('documento_whitespace')->orderBy('documento_whitespace')->pluck('n', 'w')->all()),
                'documentos_distintos' => $p()->where('documento_clave', '<>', '')->distinct()->count('documento_clave'),
            ],
            'correo' => [
                'valido' => $p()->where('correo_estado', 'valido')->count(),
                'multiple' => $p()->where('correo_estado', 'multiple')->count(),
                'invalido' => $p()->where('correo_estado', 'invalido')->count(),
                'sin_correo' => $p()->where('correo_estado', 'sin_correo')->count(),
            ],
            'tipo_documento' => array_map('intval', $tipos),
            'con_codigo_legado' => $p()->whereNotNull('num_verificacion')->count(),
            'sin_codigo_legado' => $p()->whereNull('num_verificacion')->count(),
            'en_eventos_inexistentes' => $p()->where('tiene_evento', false)->count(),
        ];
    }

    /**
     * Estadísticas de correos candidatos (sin direcciones): cuántas filas tienen uno, varios o ninguno, y con cuántos correos
     * válidos distintos termina asociado cada documento.
     *
     * @return array<string,mixed>
     */
    private function correos(): array
    {
        $p = fn () => DB::table('stg_ev_participante')->whereRaw(self::VIGENTE);
        $c = fn () => DB::table('stg_ev_participante_correos');
        $conMultiples = $p()->where('correos_candidatos', '>', 1);

        $porDocumento = DB::table('stg_ev_participante_correos as c')->join('stg_ev_participante as p', 'p.old_id', '=', 'c.participante_old_id')
            ->whereRaw('p.'.self::VIGENTE)->where('c.estado', 'valido')->where('p.documento_clave', '<>', '')
            ->selectRaw('p.documento_clave d, COUNT(DISTINCT c.correo_normalizado) n')->groupBy('p.documento_clave')->get();
        $dist = ['1' => 0, '2' => 0, '3' => 0, '4_o_mas' => 0];
        $max = 0;
        foreach ($porDocumento as $x) {
            $n = (int) $x->n;
            $max = max($max, $n);
            $dist[$n >= 4 ? '4_o_mas' : (string) $n]++;
        }
        $documentos = (int) $p()->where('documento_clave', '<>', '')->distinct()->count('documento_clave');

        return [
            'filas_con_correo_vacio' => $p()->where('correos_candidatos', 0)->count(),
            'filas_con_un_unico_candidato_valido' => $p()->where('correos_candidatos', 1)->where('correos_validos', 1)->count(),
            'filas_con_un_unico_candidato_invalido' => $p()->where('correos_candidatos', 1)->where('correos_validos', 0)->count(),
            'filas_con_multiples_candidatos' => (clone $conMultiples)->count(),
            'multiples_todos_validos' => (clone $conMultiples)->whereColumn('correos_candidatos', 'correos_validos')->count(),
            'multiples_mezcla_valido_e_invalido' => (clone $conMultiples)->where('correos_validos', '>=', 1)->whereColumn('correos_candidatos', '>', 'correos_validos')->count(),
            'multiples_sin_ninguno_valido' => (clone $conMultiples)->where('correos_validos', 0)->count(),
            'filas_con_separador_en_el_original' => $p()->where(fn ($q) => $q->where('correo_original', 'like', '%;%')->orWhere('correo_original', 'like', '%,%'))->count(),
            'candidatas_total' => $c()->count(),
            'candidatas_validas' => $c()->where('estado', 'valido')->count(),
            'candidatas_invalidas' => $c()->where('estado', 'invalido')->count(),
            'correos_validos_distintos' => (int) $c()->where('estado', 'valido')->distinct()->count('correo_normalizado'),
            'filas_con_correo_utilizable' => $p()->whereNotNull('correo_normalizado')->count(),
            'documentos_distintos' => $documentos,
            'documentos_con_algun_correo_valido' => $porDocumento->count(),
            'documentos_con_1_correo_valido' => $dist['1'],
            'documentos_con_mas_de_un_correo_valido' => $dist['2'] + $dist['3'] + $dist['4_o_mas'],
            'documentos_segun_cantidad_de_correos_validos' => $dist,
            'maximo_correos_validos_por_documento' => $max,
        ];
    }

    /** @return array<string,mixed> */
    private function duplicados(): array
    {
        $g = fn () => DB::table('stg_ev_duplicados');
        $etiquetas = [];
        foreach (['DIF_NOMBRE', 'DIF_TIPO', 'DIF_DOCUMENTO', 'DIF_CORREO', 'DIF_VERIF'] as $e) {
            $etiquetas[$e] = $g()->where('clasificacion', 'conflictivo')->where(function ($q) use ($e) {
                $q->where('etiquetas', $e)->orWhere('etiquetas', 'like', $e.',%')->orWhere('etiquetas', 'like', '%,'.$e)->orWhere('etiquetas', 'like', '%,'.$e.',%');
            })->count();
        }
        $subtipos = $g()->whereNotNull('subtipo')->selectRaw('subtipo s, COUNT(*) n')->groupBy('subtipo')->orderBy('subtipo')->pluck('n', 's')->all();

        return [
            'grupos' => $g()->count(),
            'filas_en_grupos' => (int) $g()->sum('filas'),
            'identicos' => $g()->where('clasificacion', 'identico')->count(),
            'conflictivos' => $g()->where('clasificacion', 'conflictivo')->count(),
            'filas_sobrantes_si_se_consolidaran_los_identicos' => (int) $g()->where('clasificacion', 'identico')->sum(DB::raw('filas - 1')),
            'etiquetas_en_conflictivos' => $etiquetas,
            'subtipos' => array_map('intval', $subtipos),
            'grupos_de_mas_de_2_filas' => $g()->where('filas', '>', 2)->count(),
            'ids_grupos_conflictivos' => $g()->where('clasificacion', 'conflictivo')->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all(),
        ];
    }

    /** @return array<string,mixed> */
    private function eventos(): array
    {
        $e = fn () => DB::table('stg_ev_evento')->whereRaw(self::VIGENTE);
        $estados = $e()->selectRaw('imagen_estado s, COUNT(*) n')->groupBy('imagen_estado')->pluck('n', 's')->all();
        $anios = $e()->whereNotNull('anio_deducido')->selectRaw('anio_deducido a, COUNT(*) n')->groupBy('anio_deducido')->orderBy('anio_deducido')->pluck('n', 'a')->all();
        $pendientes = $e()->whereNotNull('imagen_estado')->where('imagen_estado', '<>', 'ok');

        return [
            'total' => $e()->count(),
            'imagen_ok' => (int) ($estados['ok'] ?? 0),
            'sin_imagen' => (int) ($estados['sin_imagen'] ?? 0),
            'archivo_faltante' => (int) ($estados['archivo_faltante'] ?? 0),
            'extension_invalida' => (int) ($estados['extension_invalida'] ?? 0),
            'no_renderizable' => (int) ($estados['no_renderizable'] ?? 0),
            'imagen_sin_evaluar' => (int) ($estados[''] ?? 0) + (int) $e()->whereNull('imagen_estado')->count(),
            'pendientes_de_plantilla' => (clone $pendientes)->count(),
            'participantes_en_eventos_pendientes' => (int) (clone $pendientes)->sum('participantes_count'),
            'ids_eventos_pendientes' => (clone $pendientes)->orderBy('old_id')->pluck('old_id')->map(fn ($i) => (int) $i)->all(),
            'anio_por_evento' => array_map('intval', $anios),
            'anio_no_deducible' => $e()->whereNull('anio_deducido')->where(fn ($q) => $q->whereNull('anio_origen')->orWhere('anio_origen', '<>', 'ambiguo'))->count(),
            'anio_ambiguo' => $e()->whereNull('anio_deducido')->where('anio_origen', 'ambiguo')->count(),
            'eventos_sin_participantes' => $e()->where('participantes_count', 0)->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function imagenes(): array
    {
        $i = fn () => DB::table('stg_ev_imagenes')->whereRaw(self::VIGENTE);
        $motivos = $i()->where('renderizable_fpdf', false)->selectRaw('motivo_no_renderizable m, COUNT(*) n')->groupBy('motivo_no_renderizable')->orderBy('motivo_no_renderizable')->pluck('n', 'm')->all();
        $ext = $i()->selectRaw('extension e, COUNT(*) n')->groupBy('extension')->orderByDesc('n')->get()->mapWithKeys(fn ($x) => [($x->e ?? '(sin extensión)') => (int) $x->n])->all();
        $mime = $i()->selectRaw('mime_real m, COUNT(*) n')->groupBy('mime_real')->orderByDesc('n')->get()->mapWithKeys(fn ($x) => [($x->m ?? '(desconocido)') => (int) $x->n])->all();

        return [
            'total' => $i()->count(),
            'bytes' => (int) $i()->sum('bytes'),
            'sha256_distintos' => $i()->distinct()->count('sha256'),
            'renderizables_fpdf' => $i()->where('renderizable_fpdf', true)->count(),
            'no_renderizables_fpdf' => $i()->where('renderizable_fpdf', false)->count(),
            'no_renderizables_motivos' => array_map('intval', $motivos),
            'huerfanas' => $i()->where('huerfana', true)->count(),
            'enlazadas_a_evento' => $i()->where('huerfana', false)->count(),
            'candidatas_revision' => $i()->where('candidata_revision', true)->count(),
            // Identificador ESTABLE (SHA-256 del archivo): el id de la fila depende del AUTO_INCREMENT y cambia tras limpiar y recargar.
            'candidatas_pares' => $i()->where('candidata_revision', true)->orderBy('sha256')->orderBy('candidata_old_evento_id')->get(['sha256', 'candidata_old_evento_id'])
                ->map(fn ($x) => ['sha256' => (string) $x->sha256, 'old_evento_id' => (int) $x->candidata_old_evento_id])->all(),
            'por_extension' => $ext,
            'por_mime_real' => $mime,
            'nombres_raros' => $i()->whereRaw("nombre_original LIKE ' %' OR nombre_original LIKE '% ' OR nombre_original LIKE '%  %' OR nombre_original LIKE '% .%'")->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function codigoLegado(): array
    {
        $p = fn () => DB::table('stg_ev_participante')->whereRaw(self::VIGENTE);
        $codigos = $p()->whereNotNull('num_verificacion');

        // Se calcula en PHP (y no con SQL) para ser independiente del motor de base de datos.
        $porCodigo = [];
        foreach ($p()->whereNotNull('num_verificacion')->orderBy('old_id')->select(['num_verificacion', 'old_evento_id', 'documento_clave'])->cursor() as $f) {
            $porCodigo[$f->num_verificacion][$f->old_evento_id.'|'.$f->documento_clave] = true;
        }
        $compartidos = count(array_filter($porCodigo, fn (array $g) => count($g) > 1));
        $filasPorCodigo = (clone $codigos)->selectRaw('num_verificacion c, COUNT(*) n')->groupBy('num_verificacion')->having('n', '>', 1)->get();

        $min = (int) $p()->min('num_verificacion');
        $max = (int) $p()->max('num_verificacion');
        $distintos = count($porCodigo);

        $sinCodigoConDescarga = $p()->whereNull('num_verificacion')->where('descargas_count', '>', 0)->count();
        $conCodigoSinDescarga = $p()->whereNotNull('num_verificacion')->where('descargas_count', 0)->count();

        // Hipótesis: el código se asigna en la PRIMERA descarga y es el consecutivo global. Si es así, ordenando los
        // participantes por su primera descarga, el código nunca baja.
        $violaciones = 0;
        $anterior = null;
        $comparados = 0;
        foreach (DB::table('stg_ev_descargas as d')->join('stg_ev_participante as p', 'p.old_id', '=', 'd.old_participante_id')
            ->where('d.es_primera', true)->whereRaw('d.'.self::VIGENTE)->whereRaw('p.'.self::VIGENTE)->whereNotNull('p.num_verificacion')
            ->orderBy('d.fecha')->orderBy('d.old_id')->select(['p.num_verificacion as c'])->cursor() as $f) {
            $comparados++;
            if ($anterior !== null && $f->c < $anterior) {
                $violaciones++;
            }
            $anterior = max($anterior ?? 0, $f->c);
        }

        return [
            'con_codigo' => $codigos->count(),
            'sin_codigo' => $p()->whereNull('num_verificacion')->count(),
            'minimo' => $min,
            'maximo' => $max,
            'distintos' => $distintos,
            'huecos_en_el_rango' => $distintos === 0 ? 0 : ($max - $min + 1) - $distintos,
            'codigos_repetidos_en_varias_filas' => $filasPorCodigo->count(),
            'codigos_compartidos_entre_distintos_participantes' => $compartidos,
            'filas_con_codigo_en_grupo_duplicado' => $p()->whereNotNull('num_verificacion')->whereNotNull('grupo_duplicado_id')->count(),
            'con_descarga_y_sin_codigo' => $sinCodigoConDescarga,
            'con_codigo_y_sin_descarga' => $conCodigoSinDescarga,
            'con_codigo_y_sin_descarga_fuera_de_duplicados' => $p()->whereNotNull('num_verificacion')->where('descargas_count', 0)->whereNull('grupo_duplicado_id')->count(),
            'orden_por_primera_descarga' => ['comparados' => $comparados, 'violaciones_de_orden' => $violaciones],
            'hipotesis_codigo_se_asigna_en_la_primera_descarga' => ($sinCodigoConDescarga === 0 && $compartidos === 0 && $comparados > 0 && $violaciones <= max(1, (int) floor($comparados * 0.01)))
                ? 'CONSISTENTE_CON_LOS_DATOS' : 'NO_CONFIRMADA',
        ];
    }

    /** @return array<string,mixed> */
    private function descargas(): array
    {
        $d = fn () => DB::table('stg_ev_descargas')->whereRaw(self::VIGENTE);
        $porParticipante = $d()->whereNotNull('old_participante_id')->selectRaw('old_participante_id p, COUNT(*) n')->groupBy('old_participante_id')->get();
        $dist = ['1' => 0, '2' => 0, '3_o_mas' => 0];
        $max = 0;
        foreach ($porParticipante as $x) {
            $n = (int) $x->n;
            $max = max($max, $n);
            $dist[$n === 1 ? '1' : ($n === 2 ? '2' : '3_o_mas')]++;
        }

        return [
            'total' => $d()->count(),
            'sin_participante' => $d()->where('participante_existe', false)->count(),
            'evento_no_coincide_con_el_del_participante' => $d()->where('evento_coincide', false)->count(),
            'participantes_con_descarga' => $porParticipante->count(),
            'participantes_segun_cantidad_de_descargas' => $dist,
            'maximo_descargas_de_un_participante' => $max,
            'primeras_descargas_marcadas' => $d()->where('es_primera', true)->count(),
            'fecha_minima' => $d()->min('fecha'),
            'fecha_maxima' => $d()->max('fecha'),
        ];
    }

    /** @return array<string,mixed> */
    private function tokens(): array
    {
        $t = fn () => DB::table('stg_ev_token')->whereRaw(self::VIGENTE);
        $porParticipante = $t()->whereNotNull('old_participante_id')->selectRaw('old_participante_id p, COUNT(*) n')->groupBy('old_participante_id')->get();

        return [
            'total' => $t()->count(),
            'fecha_minima' => $t()->min('fecha_creacion'),
            'fecha_maxima' => $t()->max('fecha_creacion'),
            'participantes_distintos' => $porParticipante->count(),
            'participantes_con_mas_de_un_token' => $porParticipante->filter(fn ($x) => (int) $x->n > 1)->count(),
            'huerfanos_sin_participante' => $t()->where('participante_existe', false)->count(),
            'codigos_repetidos' => $t()->where('codigo_repetido', true)->count(),
            'longitudes_de_codigo' => array_map('intval', $t()->selectRaw('codigo_longitud l, COUNT(*) n')->groupBy('codigo_longitud')->orderBy('codigo_longitud')->pluck('n', 'l')->all()),
            'destino_futuro_NO_MIGRAR' => $t()->where('destino_futuro', 'NO_MIGRAR')->count(),
        ];
    }

    /** @return array<string,mixed> */
    private function encuestas(): array
    {
        $e = fn () => DB::table('stg_ev_encuesta')->whereRaw(self::VIGENTE);
        // Se calcula en PHP (una sola pasada) para no depender de REGEXP, que no existe igual en todos los motores.
        $textos = [];
        $ids = [];
        foreach (DB::table('stg_ev_opciones')->whereRaw(self::VIGENTE)->get(['old_id', 'opcion']) as $o) {
            $ids[(string) $o->old_id] = true;
            if ($o->opcion !== null) {
                $textos[$o->opcion] = true;
            }
        }
        $preguntas = [];
        $distintos = [];
        foreach (range(1, 9) as $k) {
            $preguntas["pregunta{$k}"] = ['no_nulos' => 0, 'vacios' => 0, 'con_texto' => 0, 'valores_distintos' => 0, 'solo_digitos' => 0, 'igual_al_texto_de_alguna_opcion' => 0, 'igual_al_id_de_alguna_opcion' => 0];
            $distintos[$k] = [];
        }
        foreach ($e()->orderBy('id')->select(array_map(fn ($k) => "pregunta{$k}", range(1, 9)))->cursor() as $fila) {
            foreach (range(1, 9) as $k) {
                $c = "pregunta{$k}";
                $valor = $fila->{$c};
                if ($valor === null) {
                    continue;
                }
                $preguntas[$c]['no_nulos']++;
                $distintos[$k][$valor] = true;
                if (trim($valor) === '') {
                    $preguntas[$c]['vacios']++;

                    continue;
                }
                $preguntas[$c]['con_texto']++;
                $digitos = ctype_digit($valor);
                $preguntas[$c]['solo_digitos'] += $digitos ? 1 : 0;
                $preguntas[$c]['igual_al_texto_de_alguna_opcion'] += isset($textos[$valor]) ? 1 : 0;
                $preguntas[$c]['igual_al_id_de_alguna_opcion'] += ($digitos && isset($ids[$valor])) ? 1 : 0;
            }
        }
        foreach (range(1, 9) as $k) {
            $preguntas["pregunta{$k}"]['valores_distintos'] = count($distintos[$k]);
        }

        return [
            'total' => $e()->count(),
            'evento_existe' => $e()->where('evento_existe', true)->count(),
            'evento_inexistente' => $e()->where('evento_existe', false)->count(),
            'sin_participante' => $e()->where('participante_existe', false)->count(),
            'sin_participante_con_evento_existente' => $e()->where('participante_existe', false)->where('evento_existe', true)->count(),
            'sin_participante_y_evento_inexistente' => $e()->where('participante_existe', false)->where('evento_existe', false)->count(),
            'eventos_distintos' => $e()->distinct()->count('old_evento_id'),
            'documentos_distintos' => $e()->where('documento_clave', '<>', '')->distinct()->count('documento_clave'),
            'fecha_minima' => $e()->min('fecha'),
            'fecha_maxima' => $e()->max('fecha'),
            'justificacion_pregunta1' => [
                'no_nulos' => $e()->whereNotNull('justificacion_pregunta1')->count(),
                'con_texto' => $e()->whereRaw("TRIM(justificacion_pregunta1) <> ''")->count(),
            ],
            'preguntas' => $preguntas,
            'catalogo' => [
                'preguntas' => DB::table('stg_ev_preguntas')->whereRaw(self::VIGENTE)->count(),
                'opciones' => DB::table('stg_ev_opciones')->whereRaw(self::VIGENTE)->count(),
            ],
        ];
    }

    /** Texto legible del reporte (sin PII). @param array<string,mixed> $r */
    public static function aTexto(array $r): string
    {
        return implode("\n", self::lineas($r))."\n";
    }

    /**
     * @param  array<string,mixed>  $nodo
     * @return array<int,string>
     */
    private static function lineas(array $nodo, string $sangria = ''): array
    {
        $salida = [];
        foreach ($nodo as $clave => $valor) {
            if (is_array($valor) && $valor !== [] && ! array_is_list($valor)) {
                $salida[] = $sangria.$clave.':';
                array_push($salida, ...self::lineas($valor, $sangria.'  '));
            } elseif (is_array($valor)) {
                $salida[] = $sangria.$clave.': '.($valor === [] ? '(ninguno)' : json_encode($valor, JSON_UNESCAPED_UNICODE));
            } else {
                $salida[] = $sangria.$clave.': '.($valor === null ? '—' : (is_bool($valor) ? ($valor ? 'sí' : 'no') : (string) $valor));
            }
        }

        return $salida;
    }
}
