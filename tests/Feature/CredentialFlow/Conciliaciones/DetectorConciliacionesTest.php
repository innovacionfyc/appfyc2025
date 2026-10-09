<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ClasificadorVariantes;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Facades\DB;

/** Detector de la Fase 10A: qué detecta, qué NO, idempotencia, pivotes, auditoría inicial y que no toca el histórico. */
class DetectorConciliacionesTest extends ConciliacionesTestCase
{
    // ── Conteos contra lo que realmente hay en el histórico ───────────────────────────────────────────────

    public function test_un_caso_por_grupo_de_variantes_en_pendiente_conciliacion(): void
    {
        $this->detectar();

        // 10B-1.5: el grupo que solo difiere en código NULL contra valor (participantes 11 y 12) ya no es un conflicto de contenido.
        $soloCodigo = DB::table('cf_certificados_legado')->where('id', $this->idCert(11))->value('grupo_duplicado');
        $gruposEsperados = DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_conciliacion')->where('grupo_duplicado', '!=', $soloCodigo)->distinct()->pluck('grupo_duplicado');
        $casos = $this->casos(Conciliacion::TIPO_CONFLICTO_VARIANTES);

        $this->assertGreaterThan(0, $gruposEsperados->count());
        $this->assertCount($gruposEsperados->count(), $casos);
        $this->assertEqualsCanonicalizing($gruposEsperados->all(), $casos->pluck('referencia_clave')->all());
        $this->assertSame(['grupo_duplicado'], $casos->pluck('referencia_tipo')->unique()->values()->all());
        // Cada caso reúne a TODAS las variantes del grupo.
        foreach ($casos as $c) {
            $miembros = DB::table('cf_certificados_legado')->where('grupo_duplicado', $c->referencia_clave)->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
            $this->assertSame($miembros, $this->certsDelCaso($c->id));
            $this->assertGreaterThanOrEqual(2, count($miembros));
        }
    }

    public function test_las_variantes_que_solo_difieren_en_codigo_nulo_contra_valor_no_generan_caso(): void
    {
        $r = $this->detectar();

        $grupo = DB::table('cf_certificados_legado')->where('id', $this->idCert(11))->value('grupo_duplicado');
        $this->assertSame(['pendiente_conciliacion', 'pendiente_conciliacion'], DB::table('cf_certificados_legado')->where('grupo_duplicado', $grupo)->orderBy('id')->pluck('conciliacion_estado')->all());
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('referencia_clave', $grupo)->count());
        $this->assertSame(['solo_codigo_nulo_vs_valor' => 1, 'con_caso_existente' => 0], $r['omitidos']);
        // Los conflictos REALES (nombre, tipo…) siguen generando su caso.
        $this->assertGreaterThan(0, $this->casos(Conciliacion::TIPO_CONFLICTO_VARIANTES)->count());
    }

    public function test_un_caso_de_solo_codigo_que_ya_existia_no_se_toca_ni_se_borra(): void
    {
        $grupo = DB::table('cf_certificados_legado')->where('id', $this->idCert(11))->value('grupo_duplicado');
        $id = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'conflicto_variantes', 'estado' => 'requiere_soporte', 'referencia_tipo' => 'grupo_duplicado', 'referencia_clave' => $grupo, 'motivo_origen' => 'DIF_VERIF',
            'clave_idempotencia' => 'conflicto_variantes:grupo_duplicado:'.$grupo, 'created_at' => now(), 'updated_at' => now()]);
        $antes = md5(json_encode(DB::table('cf_conciliaciones')->find($id)));

        $r = $this->detectar();
        $this->detectar();

        $this->assertSame(['solo_codigo_nulo_vs_valor' => 1, 'con_caso_existente' => 1], $r['omitidos']);
        $this->assertSame($antes, md5(json_encode(DB::table('cf_conciliaciones')->find($id))));
        $this->assertSame(1, DB::table('cf_conciliaciones')->where('referencia_clave', $grupo)->count());
    }

    public function test_el_clasificador_solo_considera_el_caso_de_codigo_nulo_contra_un_unico_valor(): void
    {
        $f = fn (array $codigos) => collect($codigos)->map(fn ($c, $i) => (object) ['id' => $i + 1, 'codigo_legado' => $c]);
        $snap = fn (string $etiquetas) => ['duplicado' => ['etiquetas' => $etiquetas]];

        $this->assertTrue(ClasificadorVariantes::soloDifiereEnCodigoNulo($f([null, '5300']), $snap('DIF_VERIF')));
        $this->assertTrue(ClasificadorVariantes::soloDifiereEnCodigoNulo($f(['5300', null, '5300']), $snap('DIF_VERIF')));
        $this->assertFalse(ClasificadorVariantes::soloDifiereEnCodigoNulo($f([null, '5300']), $snap('DIF_VERIF,DIF_NOMBRE')), 'otra diferencia');
        $this->assertFalse(ClasificadorVariantes::soloDifiereEnCodigoNulo($f(['5300', '5301']), $snap('DIF_VERIF')), 'dos códigos distintos');
        $this->assertFalse(ClasificadorVariantes::soloDifiereEnCodigoNulo($f([null, null]), $snap('DIF_VERIF')), 'ninguno con código');
        $this->assertFalse(ClasificadorVariantes::soloDifiereEnCodigoNulo($f(['5300', '5300']), $snap('DIF_VERIF')), 'todos con código');
        $this->assertFalse(ClasificadorVariantes::soloDifiereEnCodigoNulo($f(['5300']), $snap('DIF_VERIF')), 'una sola fila');

        $this->assertSame(2, ClasificadorVariantes::canonicoDelPar($f([null, '5300'])), 'el canónico es la fila con el código del sistema viejo');
        $this->assertSame(1, ClasificadorVariantes::canonicoDelPar($f([null, null])), 'sin códigos: la de menor id');
    }

    public function test_un_caso_por_certificado_en_revision_de_documento_con_su_motivo_tecnico(): void
    {
        $this->detectar();

        $cert = DB::table('cf_certificados_legado')->where('conciliacion_estado', 'revision_documento')->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $casos = $this->casos(Conciliacion::TIPO_REVISION_DOCUMENTO);

        $this->assertGreaterThan(0, count($cert));
        $this->assertCount(count($cert), $casos);
        $this->assertSame($cert, $casos->map(fn ($c) => $this->certsDelCaso($c->id)[0])->sort()->values()->all());
        $this->assertEqualsCanonicalizing(['DOC_VACIO', 'DOC_LETRAS', 'DOC_SEPARADORES'], $casos->pluck('motivo_origen')->unique()->values()->all());
        $this->assertSame(0, $casos->whereNull('motivo_origen')->count());
    }

    public function test_identidad_ambigua_solo_para_documentos_que_el_portal_no_puede_autenticar(): void
    {
        $this->detectar();

        $casos = $this->casos(Conciliacion::TIPO_IDENTIDAD_AMBIGUA);
        $this->assertEqualsCanonicalizing(['CORREO_CRUZA_GRUPOS', 'DOCUMENTO_INVALIDO'], $casos->pluck('motivo_origen')->all());

        // El que cruza grupos: dos nombres distintos que comparten el mismo correo (no hay forma de saber cuál es quién).
        $cruza = $casos->firstWhere('motivo_origen', 'CORREO_CRUZA_GRUPOS');
        $this->assertSame(Hmac::de('conciliacion_documento', '2000002'), $cruza->referencia_clave);
        $this->assertSame('documento', $cruza->referencia_tipo);
        $this->assertCount(2, $this->certsDelCaso($cruza->id));
        $this->assertEqualsCanonicalizing(['grupo_1', 'grupo_2'], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $cruza->id)->pluck('rol')->all());
    }

    public function test_el_documento_de_una_identidad_ambigua_nunca_se_guarda_en_claro(): void
    {
        $this->detectar();

        $volcado = json_encode([DB::table('cf_conciliaciones')->get(), DB::table('cf_conciliaciones_eventos')->get()]);
        foreach (['2000002', self::DOC_P3, self::NOMBRE_P3, self::CORREO_P3, 'HUGO', 'hugo@example.test'] as $privado) {
            $this->assertStringNotContainsString($privado, $volcado);
        }
    }

    public function test_un_documento_con_varios_nombres_que_si_autentica_no_genera_caso(): void
    {
        // Mismo documento y dos nombres, cada uno con su PROPIO correo y su fila conciliada: el portal sí los distingue → no hay caso.
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->first();
        unset($molde['id']);
        foreach ([['PAZ UNO', 'paz1@example.test'], ['LUZ DOS', 'luz2@example.test']] as [$nombre, $correo]) {
            $id = DB::table('cf_certificados_legado')->insertGetId(['documento' => '6000001', 'documento_clave' => '6000001', 'nombre_completo' => $nombre, 'grupo_duplicado' => null, 'conciliacion_estado' => 'ok'] + $molde);
            DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => true, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->detectar();

        $this->assertSame(0, DB::table('cf_conciliaciones')->where('referencia_clave', Hmac::de('conciliacion_documento', '6000001'))->count());

        // Si en cambio comparten el mismo correo, SÍ hay caso (no hay forma de saber de quién es).
        DB::table('cf_correos')->whereIn('certificado_legado_id', DB::table('cf_certificados_legado')->where('documento_clave', '6000001')->pluck('id'))->update(['correo' => 'comun@example.test', 'correo_normalizado' => 'comun@example.test']);
        $this->detectar();
        $this->assertSame(1, DB::table('cf_conciliaciones')->where('referencia_clave', Hmac::de('conciliacion_documento', '6000001'))->where('motivo_origen', 'CORREO_CRUZA_GRUPOS')->count());
    }

    public function test_plantillas_un_caso_por_entrada_problematica_o_por_evento_sin_plantilla(): void
    {
        $this->detectar();

        $porTipo = $this->casos()->groupBy('tipo')->map->count();
        // Beta (faltante) y Zeta (faltante) y Epsilon (sin imagen) → plantilla_faltante; Gamma → candidata; Delta → tipo inválido.
        $this->assertSame(1, $porTipo[Conciliacion::TIPO_PLANTILLA_CANDIDATA] ?? 0);
        $this->assertSame(1, $porTipo[Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO] ?? 0);
        $this->assertSame(3, $porTipo[Conciliacion::TIPO_PLANTILLA_FALTANTE] ?? 0);

        $candidata = $this->caso(Conciliacion::TIPO_PLANTILLA_CANDIDATA);
        $this->assertSame('plantilla_legado', $candidata->referencia_tipo);
        $this->assertSame('ARCHIVO_FALTANTE_CON_CANDIDATA', $candidata->motivo_origen);
        $this->assertSame($this->idEvento('Curso Gamma'), (int) $candidata->evento_id);
        $this->assertSame([$this->idCert(30)], $this->certsDelCaso($candidata->id));

        $invalido = $this->caso(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO);
        $this->assertSame($this->idEvento('Curso Delta 2023'), (int) $invalido->evento_id);
        $this->assertSame([$this->idCert(31)], $this->certsDelCaso($invalido->id));

        $sinImagen = $this->casos(Conciliacion::TIPO_PLANTILLA_FALTANTE)->firstWhere('referencia_tipo', 'evento');
        $this->assertSame('EVENTO_SIN_PLANTILLA_UTILIZABLE', $sinImagen->motivo_origen);
        $this->assertSame([$this->idCert(33)], $this->certsDelCaso($sinImagen->id));
    }

    public function test_cada_certificado_pendiente_de_plantilla_pertenece_a_algun_caso_de_plantilla(): void
    {
        $this->detectar();

        $pendientes = DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_plantilla')->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();
        $cubiertos = DB::table('cf_conciliaciones_certificados as k')->join('cf_conciliaciones as c', 'c.id', '=', 'k.conciliacion_id')->where('c.tipo', 'like', 'plantilla_%')->pluck('k.certificado_legado_id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        $this->assertGreaterThan(0, count($pendientes));
        $this->assertSame($pendientes, $cubiertos);
    }

    public function test_los_casos_de_un_evento_comparten_el_evento_y_los_de_varios_eventos_lo_dejan_vacio(): void
    {
        // Una entrada de plantilla compartida por dos eventos: un solo caso, sin evento único.
        $this->detectar();
        $beta = $this->idEvento('Curso Beta 2025');
        $plantilla = (int) DB::table('cf_eventos')->where('id', $beta)->value('plantilla_legado_id');
        $this->assertGreaterThan(0, $plantilla);

        $caso = $this->casos(Conciliacion::TIPO_PLANTILLA_FALTANTE)->firstWhere('referencia_clave', (string) $plantilla);
        $this->assertSame($beta, (int) $caso->evento_id);

        DB::table('cf_eventos')->where('id', $this->idEvento('Curso Epsilon 2026'))->update(['plantilla_legado_id' => $plantilla]);
        DB::table('cf_conciliaciones_eventos')->delete();
        DB::table('cf_conciliaciones_certificados')->delete();
        DB::table('cf_conciliaciones')->delete();
        $this->detectar();

        $unificado = $this->casos(Conciliacion::TIPO_PLANTILLA_FALTANTE)->firstWhere('referencia_clave', (string) $plantilla);
        $this->assertNull($unificado->evento_id);
        $this->assertGreaterThanOrEqual(2, count($this->certsDelCaso($unificado->id)));
    }

    // ── Lo que NO se detecta ─────────────────────────────────────────────────────────────────────────────

    public function test_no_hay_casos_para_certificados_ok_ni_duplicados_identicos_consolidados(): void
    {
        $this->detectar();

        $enCasos = DB::table('cf_conciliaciones_certificados')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
        $this->assertGreaterThan(0, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'duplicado_consolidado')->count());
        $this->assertSame([], array_values(array_intersect($enCasos, DB::table('cf_certificados_legado')->whereIn('conciliacion_estado', ['ok', 'duplicado_consolidado'])->pluck('id')->map(fn ($i) => (int) $i)->all())));
    }

    public function test_un_duplicado_identico_con_estado_restrictivo_se_representa_por_su_caso_de_plantilla_o_documento(): void
    {
        $this->detectar();

        // Ningún caso de conflicto para grupos que no estén en pendiente_conciliacion.
        $gruposNoConflicto = DB::table('cf_certificados_legado')->where('conciliacion_estado', '!=', 'pendiente_conciliacion')->whereNotNull('grupo_duplicado')->pluck('grupo_duplicado')->all();
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('tipo', 'conflicto_variantes')->whereIn('referencia_clave', $gruposNoConflicto)->count());

        // Los dos miembros del duplicado idéntico con plantilla pendiente entran en el MISMO caso de plantilla.
        $a = $this->idCert(50);
        $b = $this->idCert(51);
        $this->assertSame(DB::table('cf_certificados_legado')->where('id', $a)->value('grupo_duplicado'), DB::table('cf_certificados_legado')->where('id', $b)->value('grupo_duplicado'));
        $this->assertSame('pendiente_plantilla', DB::table('cf_certificados_legado')->where('id', $a)->value('conciliacion_estado'));
        $caso = DB::table('cf_conciliaciones as c')->join('cf_conciliaciones_certificados as k', 'k.conciliacion_id', '=', 'c.id')->where('k.certificado_legado_id', $a)->first();
        $this->assertSame('plantilla_faltante', $caso->tipo);
        $this->assertContains($b, $this->certsDelCaso($caso->conciliacion_id));
    }

    public function test_las_imagenes_huerfanas_que_no_afectan_a_certificados_no_generan_caso(): void
    {
        $this->detectar();

        $huerfanas = DB::table('cf_plantillas_legado')->whereIn('estado', ['huerfana'])->pluck('id')->map(fn ($i) => (string) $i)->all();
        $this->assertNotEmpty($huerfanas);
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('referencia_tipo', 'plantilla_legado')->whereIn('referencia_clave', $huerfanas)->count());
        $this->assertNotContains('encuesta_sin_vinculo', DB::table('cf_conciliaciones')->pluck('tipo')->all());
    }

    // ── Idempotencia ────────────────────────────────────────────────────────────────────────────────────

    public function test_la_segunda_ejecucion_es_un_no_op_logico(): void
    {
        $primera = $this->detectar();
        $antes = $this->firmaConciliaciones();
        $contadores = [DB::table('cf_conciliaciones')->count(), DB::table('cf_conciliaciones_certificados')->count(), DB::table('cf_conciliaciones_eventos')->count()];

        $segunda = $this->detectar();

        $this->assertGreaterThan(0, $primera['total']['nuevos']);
        $this->assertSame(0, $segunda['total']['nuevos']);
        $this->assertSame(0, $segunda['total']['relaciones_agregadas']);
        $this->assertSame($primera['total']['detectados'], $segunda['total']['existentes']);
        $this->assertSame($contadores, [DB::table('cf_conciliaciones')->count(), DB::table('cf_conciliaciones_certificados')->count(), DB::table('cf_conciliaciones_eventos')->count()]);
        $this->assertSame($antes, $this->firmaConciliaciones());
    }

    public function test_cada_caso_tiene_exactamente_un_evento_detectado_sin_actor(): void
    {
        $this->detectar();
        $this->detectar();

        foreach (DB::table('cf_conciliaciones')->pluck('id') as $id) {
            $eventos = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $id)->get();
            $this->assertCount(1, $eventos);
            $this->assertSame('detectado', $eventos[0]->accion);
            $this->assertSame('abierto', $eventos[0]->estado_nuevo);
            $this->assertNull($eventos[0]->estado_anterior);
            $this->assertNull($eventos[0]->actor_id);
        }
    }

    public function test_un_caso_existente_no_se_toca_pero_se_completan_las_relaciones_que_faltan(): void
    {
        $this->detectar();
        $caso = $this->caso(Conciliacion::TIPO_CONFLICTO_VARIANTES);
        $ids = $this->certsDelCaso($caso->id);
        // Un humano ya trabajó el caso (estado y resolución) y falta una relación.
        DB::table('cf_conciliaciones')->where('id', $caso->id)->update(['estado' => 'requiere_soporte', 'resolucion' => 'pendiente_de_soporte', 'motivo_origen' => 'EDITADO']);
        DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->where('certificado_legado_id', $ids[0])->delete();
        $eventosAntes = DB::table('cf_conciliaciones_eventos')->count();

        $r = $this->detectar();

        $this->assertSame(1, $r['total']['relaciones_agregadas']);
        $this->assertSame($ids, $this->certsDelCaso($caso->id));
        $fresco = DB::table('cf_conciliaciones')->find($caso->id);
        $this->assertSame('requiere_soporte', $fresco->estado);
        $this->assertSame('pendiente_de_soporte', $fresco->resolucion);
        $this->assertSame('EDITADO', $fresco->motivo_origen);
        $this->assertSame($eventosAntes, DB::table('cf_conciliaciones_eventos')->count());
    }

    public function test_la_simulacion_no_escribe_nada_y_cuenta_lo_que_haria(): void
    {
        $r = $this->detectar(true);

        $this->assertSame(0, DB::table('cf_conciliaciones')->count());
        $this->assertSame(0, DB::table('cf_conciliaciones_certificados')->count());
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->count());
        $this->assertGreaterThan(0, $r['total']['nuevos']);

        $real = $this->detectar();
        $this->assertSame($r['total']['nuevos'], $real['total']['nuevos']);
        $this->assertSame($r['total']['relaciones_agregadas'], $real['total']['relaciones_agregadas']);
    }

    public function test_la_clave_de_idempotencia_es_determinista_y_unica(): void
    {
        $this->detectar();
        $claves = DB::table('cf_conciliaciones')->pluck('clave_idempotencia');

        $this->assertSame($claves->count(), $claves->unique()->count());
        foreach (DB::table('cf_conciliaciones')->get() as $c) {
            $this->assertSame($c->tipo.':'.$c->referencia_tipo.':'.$c->referencia_clave, $c->clave_idempotencia);
        }
    }

    // ── No reescribe el histórico ────────────────────────────────────────────────────────────────────────

    public function test_el_detector_no_modifica_ningun_dato_historico(): void
    {
        $antes = $this->firmaHistorico();

        $this->detectar();
        $this->detectar();

        $this->assertSame($antes, $this->firmaHistorico());
    }

    public function test_el_detector_solo_emite_inserciones_y_solo_en_las_tablas_de_conciliacion(): void
    {
        $sentencias = $this->sentencias(fn () => $this->detectar());
        $escritas = [];
        foreach ($sentencias as $sql) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b.*?\b(?:into|from|update)?\s*[`"\[]?(cf_[a-z_]+|movimientos)[`"\]]?/is', $sql, $m)) {
                $escritas[strtolower($m[1])][$m[2]] = true;
            }
        }

        $this->assertArrayNotHasKey('update', $escritas);
        $this->assertArrayNotHasKey('delete', $escritas);
        $this->assertEqualsCanonicalizing(['cf_conciliaciones', 'cf_conciliaciones_certificados', 'cf_conciliaciones_eventos'], array_keys($escritas['insert']));
    }

    // ── Cobertura de relaciones ─────────────────────────────────────────────────────────────────────────

    public function test_un_caso_puede_afectar_a_varios_certificados_y_el_pivote_no_repite_pares(): void
    {
        $this->detectar();

        $this->assertTrue(DB::table('cf_conciliaciones_certificados')->select('conciliacion_id')->groupBy('conciliacion_id')->havingRaw('COUNT(*) > 1')->exists());
        $this->assertSame(0, DB::table('cf_conciliaciones_certificados')->select('conciliacion_id', 'certificado_legado_id')->groupBy('conciliacion_id', 'certificado_legado_id')->havingRaw('COUNT(*) > 1')->count());
    }
}
