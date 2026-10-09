<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Consolidación de los casos DIF_VERIF (Fase 10B-2A): regla, canónico, estados, evidencia inmutable, auditoría, reversión, portal y verificación. */
class ConsolidacionCodigoTest extends ConsolidacionTestCase
{
    private function negado(callable $f, string $codigo): ResolucionNoPermitida
    {
        try {
            $f();
        } catch (ResolucionNoPermitida $e) {
            $this->assertSame($codigo, $e->codigo, $e->getMessage());

            return $e;
        }
        $this->fail('Debió negarse con '.$codigo);
    }

    // ── Identificación y revalidación ────────────────────────────────────────────────────────────────────

    public function test_identifica_los_dif_verif_y_propone_el_canonico_con_el_codigo_y_la_descarga_historica(): void
    {
        $caso = $this->caso(70);
        $a = $this->servicio()->analizar(Conciliacion::find($caso));

        $this->assertTrue($a['aplicable']);
        $this->assertSame([], $a['bloqueos']);
        $this->assertSame($this->idCert(70), $a['canonico_id']);
        $this->assertSame([$this->idCert(71)], $a['otras_ids']);
        $this->assertSame('6101', $a['codigo']);
        $this->assertSame([$this->idCert(70) => 1], $a['descargas']);
    }

    public function test_un_dif_correo_y_un_dif_nombre_no_son_de_solo_codigo_y_no_se_resuelven(): void
    {
        foreach ([74, 9] as $old) {   // DIF_CORREO y DIF_NOMBRE
            $caso = $this->caso($old);
            $antes = $this->evidencia().$this->firmaCaso($caso);

            $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::NO_ES_DIFERENCIA_DE_CODIGO);

            $this->assertSame($antes, $this->evidencia().$this->firmaCaso($caso));
        }
    }

    public function test_un_caso_con_codigo_y_otra_diferencia_a_la_vez_no_se_resuelve(): void
    {
        $caso = $this->caso(76);   // difiere el nombre Y el código

        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::NO_ES_DIFERENCIA_DE_CODIGO);
    }

    /** @return array<string,array{0:string}> */
    public static function diferenciasOcultas(): array
    {
        return ['documento impreso' => ['documento'], 'tipo de documento' => ['tipo'], 'plantilla' => ['plantilla'], 'correos' => ['correos'], 'nombre' => ['nombre'], 'estado del documento' => ['estado_documento']];
    }

    #[DataProvider('diferenciasOcultas')]
    public function test_cada_diferencia_que_las_etiquetas_no_ven_detiene_el_caso_en_la_revalidacion(string $que): void
    {
        $nula = $this->idCert(71);
        match ($que) {
            'documento' => DB::table('cf_certificados_legado')->where('id', $nula)->update(['documento' => '8100001 ']),   // «numérico» para PHP: debe compararse como texto exacto
            'tipo' => DB::table('cf_certificados_legado')->where('id', $nula)->update(['tipo_documento' => 'CE']),
            'plantilla' => DB::table('cf_certificados_legado')->where('id', $nula)->update(['plantilla_legado_id' => null]),
            'correos' => DB::table('cf_correos')->where('certificado_legado_id', $nula)->update(['correo_normalizado' => 'otro@example.test', 'correo' => 'otro@example.test']),
            'nombre' => DB::table('cf_certificados_legado')->where('id', $nula)->update(['nombre_completo' => 'PAR ALFA X']),
            'estado_documento' => DB::table('cf_certificados_legado')->where('id', $nula)->update(['snapshot_legado' => json_encode(['documento_estado' => 'letras'] + json_decode((string) DB::table('cf_certificados_legado')->where('id', $nula)->value('snapshot_legado'), true))]),
        };
        $caso = $this->caso(70);
        $antes = $this->evidencia().$this->firmaCaso($caso);

        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::HAY_OTRAS_DIFERENCIAS);

        $this->assertSame($antes, $this->evidencia().$this->firmaCaso($caso));
    }

    public function test_un_par_con_dos_codigos_o_con_codigo_asignado_por_credential_flow_se_detiene(): void
    {
        $caso = $this->caso(70);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['codigo_legado' => '6999']);
        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::NO_ES_DIFERENCIA_DE_CODIGO);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['codigo_legado' => null]);

        $c = $this->cert(70);
        DB::table('cf_codigos_historicos')->insert(['codigo' => '50000', 'evento_id' => $c->evento_id, 'certificado_canonico_id' => $c->id, 'par_hash' => CodigoHistorico::parHash((int) $c->evento_id, $c->documento_clave), 'created_at' => now(), 'updated_at' => now()]);
        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CODIGO_NO_UNICO);
    }

    public function test_la_evidencia_de_descarga_debe_confirmar_el_canonico(): void
    {
        // E: la fila SIN código también tiene descargas históricas (imposible en el legado) → evidencia incompatible.
        $this->negado(fn () => $this->servicio()->consolidar($this->caso(78), $this->actor(), self::MOTIVO), ResolucionNoPermitida::EVIDENCIA_DE_DESCARGA);
        // F: ninguna descarga histórica → no hay evidencia de que el código se emitiera.
        $this->negado(fn () => $this->servicio()->consolidar($this->caso(80), $this->actor(), self::MOTIVO), ResolucionNoPermitida::EVIDENCIA_DE_DESCARGA);
    }

    public function test_un_certificado_revocado_o_reemplazado_o_un_pdf_en_la_no_canonica_detienen_el_caso(): void
    {
        $caso = $this->caso(70);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['estado' => 'revocado']);
        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['estado' => 'reemplazado']);
        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['estado' => 'vigente', 'pdf_archivo' => 'x.pdf']);
        $e = $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::PDF_CONGELADO);
        $this->assertSame('Este certificado ya tiene un archivo histórico generado y no puede cambiarse de plantilla directamente.', $e->getMessage());
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    public function test_un_pdf_congelado_en_la_canonica_no_cambia_su_significado_y_se_admite(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(70))->update(['pdf_archivo' => 'x.pdf', 'pdf_hash' => str_repeat('a', 64), 'pdf_bytes' => 5]);
        $caso = $this->caso(70);

        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);

        $this->assertSame('x.pdf', $this->cert(70)->pdf_archivo);
        $this->assertSame('ok', $this->cert(70)->conciliacion_estado);
    }

    public function test_el_caso_debe_seguir_abierto_y_el_grupo_no_debe_haber_cambiado(): void
    {
        $caso = $this->caso(70);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['conciliacion_estado' => 'ok']);

        $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);
    }

    public function test_el_motivo_es_obligatorio_y_acotado(): void
    {
        $caso = $this->caso(70);
        foreach (['', '   ', 'corto', str_repeat('x', 501)] as $m) {
            $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), $m), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    public function test_solo_los_casos_de_conflicto_admiten_esta_accion(): void
    {
        app(DetectorConciliaciones::class)->ejecutar();
        $revision = (int) DB::table('cf_conciliaciones')->where('tipo', 'revision_documento')->value('id');

        $this->negado(fn () => $this->servicio()->consolidar($revision, $this->actor(), self::MOTIVO), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
    }

    // ── Resolución: estados, código, canónico y evidencia ───────────────────────────────────────────────

    public function test_consolidar_deja_un_canonico_ok_y_un_duplicado_consolidado_con_el_mismo_codigo_efectivo(): void
    {
        $caso = $this->caso(70);

        $r = $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);

        $this->assertSame([$this->idCert(70), 1, '6101'], [$r['canonico_id'], $r['consolidadas'], $r['codigo']]);
        $this->assertSame('ok', $this->cert(70)->conciliacion_estado);
        $this->assertSame('duplicado_consolidado', $this->cert(71)->conciliacion_estado);
        $codigos = new CodigoHistorico;
        $this->assertSame(['codigo' => '6101', 'origen' => 'legado'], $codigos->resolver(CertificadoLegado::find($this->idCert(70))));
        $this->assertSame(['codigo' => '6101', 'origen' => 'legado'], $codigos->resolver(CertificadoLegado::find($this->idCert(71))));
        $this->assertSame($this->idCert(70), ElegibilidadLegado::canonico(CertificadoLegado::find($this->idCert(71)))->id);
        $this->assertNull(ElegibilidadLegado::motivo(CertificadoLegado::find($this->idCert(70))));
        // El caso queda resuelto y NO se borra.
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'codigo_consolidado', $this->actor()], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $this->assertNotNull($f->resuelto_at);
        $this->assertEqualsCanonicalizing(['canonico', 'consolidada'], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso)->pluck('rol')->all());
    }

    public function test_no_copia_el_codigo_no_crea_codigos_nuevos_y_no_toca_nada_historico(): void
    {
        $antes = $this->evidencia();

        $this->servicio()->consolidar($this->caso(70), $this->actor(), self::MOTIVO);

        $this->assertSame($antes, $this->evidencia());
        $this->assertNull($this->cert(71)->codigo_legado, 'la fila NULL sigue NULL');
        $this->assertSame('6101', $this->cert(70)->codigo_legado);
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(50000, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        $this->assertSame(2, DB::table('cf_certificados_legado')->where('grupo_duplicado', $this->cert(70)->grupo_duplicado)->count(), 'ninguna fila se borra');
    }

    public function test_el_mapa_original_conserva_variante_conflictiva_y_la_resolucion_es_una_fila_aditiva(): void
    {
        $antes = DB::table('cf_migraciones_map')->orderBy('id')->get();
        $originales = $antes->whereIn('destino_id', [$this->idCert(70), $this->idCert(71)])->pluck('relacion')->all();
        $this->assertSame(['variante_conflictiva', 'variante_conflictiva'], $originales);

        $r = $this->servicio()->consolidar($this->caso(70), $this->actor(), self::MOTIVO);

        $despues = DB::table('cf_migraciones_map')->orderBy('id')->get();
        $this->assertSame($antes->count() + 1, $despues->count());
        $this->assertSame($antes->all() == $despues->take($antes->count())->all(), true, 'las filas originales no cambian');
        $nueva = $despues->last();
        $this->assertSame(['conciliacion', 'canonico', 'cf_certificados_legado', $r['canonico_id']], [$nueva->origen_tabla, $nueva->relacion, $nueva->destino_tabla, (int) $nueva->destino_id]);
    }

    public function test_el_estado_derivado_respeta_un_bloqueo_mas_restrictivo(): void
    {
        // Ninguna de las dos variantes tiene plantilla usable: el bloqueo de plantilla es más restrictivo que «ok».
        DB::table('cf_certificados_legado')->whereIn('id', [$this->idCert(70), $this->idCert(71)])->update(['plantilla_legado_id' => null]);

        $r = $this->servicio()->consolidar($this->caso(70), $this->actor(), self::MOTIVO);

        $this->assertEqualsCanonicalizing(['pendiente_plantilla', 'pendiente_plantilla'], array_values($r['estados']));

        // Y con el documento en revisión.
        DB::table('cf_certificados_legado')->whereIn('id', [$this->idCert(72), $this->idCert(73)])->get()->each(function ($c) {
            $s = json_decode($c->snapshot_legado, true);
            $s['documento_estado'] = 'letras';
            DB::table('cf_certificados_legado')->where('id', $c->id)->update(['snapshot_legado' => json_encode($s)]);
        });
        $r2 = $this->servicio()->consolidar($this->caso(72), $this->actor(), self::MOTIVO);
        $this->assertEqualsCanonicalizing(['revision_documento', 'revision_documento'], array_values($r2['estados']));
    }

    public function test_las_descargas_historicas_se_quedan_donde_estaban(): void
    {
        $antes = DB::table('cf_descargas')->orderBy('id')->get()->map(fn ($d) => [$d->id, $d->certificado_legado_id, $d->origen, $d->descargado_at])->all();

        $this->servicio()->consolidar($this->caso(70), $this->actor(), self::MOTIVO);

        $this->assertSame($antes, DB::table('cf_descargas')->orderBy('id')->get()->map(fn ($d) => [$d->id, $d->certificado_legado_id, $d->origen, $d->descargado_at])->all());
        $this->assertSame(0, DB::table('cf_descargas')->where('certificado_legado_id', $this->idCert(71))->count(), 'no se mueven al canónico ni a la otra variante');
    }

    // ── Auditoría ────────────────────────────────────────────────────────────────────────────────────────

    public function test_se_audita_con_evento_append_only_y_movimiento_sin_datos_personales(): void
    {
        $caso = $this->caso(70);
        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);

        $e = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->first();
        $evi = json_decode($e->evidencia, true);
        $this->assertSame(['conflicto_codigo_consolidado', 'abierto', 'resuelto', $this->actor(), self::MOTIVO], [$e->accion, $e->estado_anterior, $e->estado_nuevo, (int) $e->actor_id, $e->motivo]);
        foreach (['regla', 'codigo', 'canonico_id', 'consolidadas_ids', 'descargas_historicas', 'estados_anteriores', 'estados_nuevos', 'roles_anteriores', 'mapa_id'] as $k) {
            $this->assertArrayHasKey($k, $evi);
        }
        foreach (['PAR ALFA', '8100001', 'example.test'] as $privado) {
            $this->assertStringNotContainsString($privado, $e->evidencia);
        }
        $mov = DB::table('movimientos')->where('tipo', 'conciliacion')->latest('id')->first();
        $this->assertSame($this->actor(), (int) $mov->user_id);
        $this->assertArrayNotHasKey('ip', json_decode($mov->metadata, true));
        $this->assertStringNotContainsString('PAR ALFA', $mov->descripcion.$mov->metadata);
    }

    // ── Idempotencia ─────────────────────────────────────────────────────────────────────────────────────

    public function test_resolver_dos_veces_no_duplica_nada_y_avisa_que_ya_esta_resuelto(): void
    {
        $caso = $this->caso(70);
        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);
        $firma = $this->firmaCaso($caso);

        $e = $this->negado(fn () => $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO), ResolucionNoPermitida::CASO_YA_RESUELTO);

        $this->assertSame('Este caso ya fue resuelto.', $e->getMessage());
        $this->assertSame($firma, $this->firmaCaso($caso));
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'conflicto_codigo_consolidado')->count());
    }

    // ── Reversión ────────────────────────────────────────────────────────────────────────────────────────

    public function test_revertir_restaura_estados_roles_y_mapa_y_deja_el_codigo_legado_intacto(): void
    {
        $caso = $this->caso(70);
        $evidencia = $this->evidencia();
        $estados = DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado', 'id')->all();
        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);

        $this->servicio()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.');

        $this->assertSame($estados, DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado', 'id')->all());
        $this->assertSame($evidencia, $this->evidencia());
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['abierto', null, null, null], [$f->estado, $f->resolucion, $f->resuelto_por, $f->resuelto_at]);
        $this->assertSame(['variante', 'variante'], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso)->pluck('rol')->all());
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->count());
        $this->assertSame(['detectado', 'conflicto_codigo_consolidado', 'conflicto_consolidacion_revertida'], DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderBy('id')->pluck('accion')->all());
        // Y se puede volver a consolidar.
        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);
        $this->assertSame('ok', $this->cert(70)->conciliacion_estado);
    }

    public function test_no_se_puede_revertir_con_pdf_descarga_nueva_reemplazo_decision_posterior_o_caso_abierto(): void
    {
        $caso = $this->caso(70);
        $this->negado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);   // abierto
        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);

        DB::table('cf_certificados_legado')->where('id', $this->idCert(70))->update(['pdf_archivo' => 'x.pdf']);
        $this->negado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::PDF_CONGELADO);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(70))->update(['pdf_archivo' => null]);

        $id = DB::table('cf_descargas')->insertGetId(['certificado_legado_id' => $this->idCert(70), 'via' => 'portal', 'origen' => 'credential_flow', 'descargado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->negado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        DB::table('cf_descargas')->where('id', $id)->delete();

        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['estado' => 'reemplazado']);
        $this->negado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(71))->update(['estado' => 'vigente']);

        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'nota', 'actor_id' => $this->actor(), 'created_at' => now()]);
        $this->negado(fn () => $this->servicio()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        $this->assertSame('resuelto', DB::table('cf_conciliaciones')->find($caso)->estado);
    }

    // ── Portal, verificación pública y detector ─────────────────────────────────────────────────────────

    public function test_el_portal_existente_resuelve_el_par_como_un_unico_certificado_disponible(): void
    {
        $acceso = new AccesoPortal;
        $this->assertNull($acceso->alcance('8100001', 'd8100001@example.test'), 'antes: sin certificado habilitante');

        $this->servicio()->consolidar($this->caso(70), $this->actor(), self::MOTIVO);

        $this->assertNotNull($acceso->alcance('8100001', 'd8100001@example.test'));
        $tarjetas = collect($acceso->tarjetas('8100001'));
        $this->assertCount(1, $tarjetas, 'el par se muestra como un único certificado');
        $this->assertSame(['disponible', true, '6101'], [$tarjetas[0]['tipo'], $tarjetas[0]['descargable'], $tarjetas[0]['codigo']]);
    }

    public function test_la_verificacion_publica_sigue_siendo_una_sola_y_pasa_de_en_revision_a_valida(): void
    {
        $this->assertStringContainsString('revisión', $this->get('/verificar/6101')->assertOk()->getContent());

        $this->servicio()->consolidar($this->caso(70), $this->actor(), self::MOTIVO);
        $this->app['auth']->forgetGuards();

        $r = $this->get('/verificar/6101');
        $r->assertOk()->assertSee('Certificado histórico válido')->assertSee('Curso Alfa 2024');
        $this->assertSame(1, substr_count($r->getContent(), 'data-dato="codigo"'));
        $r->assertDontSee('PAR ALFA')->assertDontSee('8100001');
    }

    public function test_el_detector_no_recrea_los_casos_dif_verif_ni_toca_los_resueltos(): void
    {
        $caso = $this->caso(70);
        $this->servicio()->consolidar($caso, $this->actor(), self::MOTIVO);
        $firma = $this->firmaCaso($caso);

        $r = app(DetectorConciliaciones::class)->ejecutar();
        app(DetectorConciliaciones::class)->ejecutar();

        $this->assertSame($firma, $this->firmaCaso($caso));
        $this->assertSame(1, DB::table('cf_conciliaciones')->where('referencia_clave', $this->cert(70)->grupo_duplicado)->count());
        $this->assertSame(0, DB::table('cf_conciliaciones')->where('estado', 'abierto')->whereIn('referencia_clave', DB::table('cf_certificados_legado')->whereIn('id', [$this->idCert(72), $this->idCert(76), $this->idCert(78), $this->idCert(80)])->pluck('grupo_duplicado'))->where('motivo_origen', 'DIF_VERIF')->count());
        $this->assertGreaterThanOrEqual(4, $r['omitidos']['solo_codigo_nulo_vs_valor']);
    }

    public function test_todos_los_dif_verif_aplicables_se_consolidan_con_estados_esperados_y_los_demas_siguen_pendientes(): void
    {
        $casos = [70 => $this->caso(70), 72 => $this->caso(72), 74 => $this->caso(74), 76 => $this->caso(76), 9 => $this->caso(9)];
        $pend = DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_conciliacion')->count();

        foreach ([70, 72] as $old) {
            $this->servicio()->consolidar($casos[$old], $this->actor(), self::MOTIVO);
        }

        $this->assertSame($pend - 4, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_conciliacion')->count());
        $this->assertSame(2, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'duplicado_consolidado')->whereIn('id', [$this->idCert(71), $this->idCert(73)])->count());
        foreach ([74, 76, 9] as $old) {
            $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($casos[$old])->estado);
        }
    }
}
