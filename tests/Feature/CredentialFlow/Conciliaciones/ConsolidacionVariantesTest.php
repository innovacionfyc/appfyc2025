<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\ConsolidacionVariantes;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Legado\RendererLegado;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;

/** Consolidación de DIF_CORREO y de la variación cosmética de nombre (Fase 10B-2B-1). */
class ConsolidacionVariantesTest extends VariantesTestCase
{
    private function correo(int $caso, ?string $m = null): array
    {
        return $this->variantes()->consolidar($caso, $this->actor(), $m ?? self::MOTIVO, ConsolidacionVariantes::MODO_CORREO);
    }

    private function nombre(int $caso, ?int $canonico, ?string $m = null): array
    {
        return $this->variantes()->consolidar($caso, $this->actor(), $m ?? self::MOTIVO, ConsolidacionVariantes::MODO_NOMBRE, $canonico);
    }

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

    // ── Clasificación ────────────────────────────────────────────────────────────────────────────────────

    public function test_clasifica_cada_caso_en_su_modo_y_separa_los_nombres_realmente_distintos(): void
    {
        $modo = fn (int $old) => $this->variantes()->analizar(Conciliacion::find($this->casoDe($old)));

        foreach ([90, 92, 94, 98] as $old) {
            $a = $modo($old);
            $this->assertSame([ConsolidacionVariantes::MODO_CORREO, true, []], [$a['modo'], $a['aplicable'], $a['bloqueos']], (string) $old);
        }
        $this->assertSame([ConsolidacionVariantes::MODO_NOMBRE, true], [$modo(102)['modo'], $modo(102)['aplicable']]);
        $real = $modo(104);
        $this->assertSame([false, true, 'NOMBRE_REALMENTE_DISTINTO'], [$real['aplicable'], $real['nombre_real'], $real['bloqueos'][0]['codigo']]);
        // Cambia el correo Y el nombre: no es «solo correo» ni «solo nombre».
        $mixto = $modo(100);
        $this->assertSame([null, false], [$mixto['modo'], $mixto['aplicable']]);
    }

    // ── DIF_CORREO ───────────────────────────────────────────────────────────────────────────────────────

    public function test_sin_codigo_ni_descarga_el_canonico_es_el_menor_old_id_y_la_otra_queda_consolidada(): void
    {
        $caso = $this->casoDe(90);

        $r = $this->correo($caso);

        $this->assertSame([$this->idCert(90), 1, ConsolidacionVariantes::MODO_CORREO], [$r['canonico_id'], $r['consolidadas'], $r['modo']]);
        $this->assertSame(['ok', 'duplicado_consolidado'], [$this->cert(90)->conciliacion_estado, $this->cert(91)->conciliacion_estado]);
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'correo_consolidado', $this->actor()], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $this->assertNotNull($f->resuelto_at);
        $this->assertSame($this->idCert(90), ElegibilidadLegado::canonico(CertificadoLegado::find($this->idCert(91)))->id);
        $this->assertEqualsCanonicalizing(['canonico', 'consolidada'], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso)->pluck('rol')->all());
    }

    public function test_con_codigo_legado_y_descarga_historica_esa_variante_es_la_canonica_aunque_no_sea_la_menor(): void
    {
        $this->assertTrue($this->idCert(92) < $this->idCert(93));
        $caso = $this->casoDe(92);

        $r = $this->correo($caso);

        $this->assertSame($this->idCert(93), $r['canonico_id']);
        $this->assertSame(['duplicado_consolidado', 'ok'], [$this->cert(92)->conciliacion_estado, $this->cert(93)->conciliacion_estado]);
        $this->assertSame('6201', (new CodigoHistorico)->resolver(CertificadoLegado::find($this->idCert(92)))['codigo']);
    }

    public function test_un_grupo_de_cuatro_deja_un_canonico_y_tres_consolidadas(): void
    {
        $r = $this->correo($this->casoDe(94));

        $this->assertSame($this->idCert(94), $r['canonico_id']);
        $this->assertSame(3, $r['consolidadas']);
        $this->assertEqualsCanonicalizing(['ok', 'duplicado_consolidado', 'duplicado_consolidado', 'duplicado_consolidado'], collect([94, 95, 96, 97])->map(fn ($o) => $this->cert($o)->conciliacion_estado)->all());
        foreach ([95, 96, 97] as $o) {
            $this->assertSame($this->idCert(94), ElegibilidadLegado::canonico(CertificadoLegado::find($this->idCert($o)))->id);
        }
    }

    public function test_un_correo_valido_frente_a_uno_invalido_tambien_se_consolida(): void
    {
        $this->correo($this->casoDe(98));

        $this->assertSame(['ok', 'duplicado_consolidado'], [$this->cert(98)->conciliacion_estado, $this->cert(99)->conciliacion_estado]);
    }

    public function test_los_correos_el_mapa_original_las_descargas_y_el_codigo_no_se_tocan_y_no_se_crean_codigos(): void
    {
        $antes = $this->evidencia();
        $mapaOriginal = DB::table('cf_migraciones_map')->count();

        $this->correo($this->casoDe(90));
        $this->correo($this->casoDe(92));
        $this->correo($this->casoDe(94));

        $this->assertSame($antes, $this->evidencia());
        $this->assertSame($mapaOriginal + 3, DB::table('cf_migraciones_map')->count());   // una fila aditiva por caso; las originales (variante_conflictiva) intactas
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(50000, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        $this->assertSame(3, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->where('relacion', 'canonico')->count());
    }

    public function test_todos_los_correos_historicos_siguen_autenticando_con_el_portal_existente(): void
    {
        $acceso = new AccesoPortal;
        foreach (['a1@example.test', 'a2@example.test'] as $correo) {
            $this->assertNull($acceso->alcance('8200001', $correo), 'antes: ningún certificado habilitante');
        }

        $this->correo($this->casoDe(90));

        foreach (['a1@example.test', 'a2@example.test'] as $correo) {
            $this->assertNotNull($acceso->alcance('8200001', $correo), $correo);
        }
        $tarjetas = collect($acceso->tarjetas('8200001'));
        $this->assertCount(1, $tarjetas, 'un único certificado lógico');
        $this->assertSame(['disponible', true, null], [$tarjetas[0]['tipo'], $tarjetas[0]['descargable'], $tarjetas[0]['codigo']]);
    }

    public function test_la_verificacion_usa_el_codigo_existente_o_el_perezoso_de_la_primera_descarga(): void
    {
        // Con código legado (B): «en revisión» antes, válido después.
        $this->assertStringContainsString('revisión', $this->get('/verificar/6201')->assertOk()->getContent());
        $this->correo($this->casoDe(92));
        $this->app['auth']->forgetGuards();
        $r = $this->get('/verificar/6201');
        $r->assertOk()->assertSee('Certificado histórico válido');
        $this->assertSame(1, substr_count($r->getContent(), 'data-dato="codigo"'));

        // Sin código (A): la consolidación NO crea ninguno; el primero se asigna al generar el PDF.
        $this->correo($this->casoDe(90));
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        (new CongeladorLegado(new RendererLegado, new ResolutorPlantillaDirectorio($this->dirImagenes)))->servir(CertificadoLegado::find($this->idCert(91)));   // desde la duplicada: resuelve al canónico
        $this->assertSame(1, DB::table('cf_codigos_historicos')->count());
        $codigo = (string) DB::table('cf_codigos_historicos')->value('codigo');
        $this->assertSame($this->idCert(90), (int) DB::table('cf_codigos_historicos')->value('certificado_canonico_id'));
        $this->app['auth']->forgetGuards();
        $this->get('/verificar/'.$codigo)->assertOk()->assertSee('Certificado histórico válido');
    }

    public function test_el_estado_derivado_respeta_un_bloqueo_mas_restrictivo(): void
    {
        DB::table('cf_certificados_legado')->whereIn('id', [$this->idCert(90), $this->idCert(91)])->update(['plantilla_legado_id' => null]);

        $r = $this->correo($this->casoDe(90));

        $this->assertEqualsCanonicalizing(['pendiente_plantilla', 'pendiente_plantilla'], array_values($r['estados']));
    }

    // ── Rechazos ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_se_rechaza_lo_que_no_es_solo_correo(): void
    {
        $antes = $this->evidencia();
        $this->negado(fn () => $this->correo($this->casoDe(100)), ResolucionNoPermitida::NO_ES_DIFERENCIA_DE_CODIGO);   // correo y nombre
        $this->negado(fn () => $this->correo($this->casoDe(102)), ResolucionNoPermitida::TIPO_NO_ADMITIDO);               // modo nombre usado como correo
        $this->negado(fn () => $this->correo($this->casoDe(104)), ResolucionNoPermitida::NOMBRE_REALMENTE_DISTINTO);
        $this->assertSame($antes, $this->evidencia());
        $this->assertSame('abierto', $this->estadoCaso($this->casoDe(100)));
    }

    /** @return array<string,array{0:string}> */
    public static function diferenciasOcultas(): array
    {
        return ['documento impreso' => ['documento'], 'tipo de documento' => ['tipo'], 'plantilla' => ['plantilla'], 'nombre exacto' => ['nombre'], 'estado del documento' => ['estado_documento']];
    }

    #[DataProvider('diferenciasOcultas')]
    public function test_otra_diferencia_material_oculta_a_las_etiquetas_detiene_el_caso(string $que): void
    {
        $segunda = $this->idCert(91);
        match ($que) {
            'documento' => DB::table('cf_certificados_legado')->where('id', $segunda)->update(['documento' => '8200001 ']),   // «numérico» para PHP: debe compararse como texto exacto
            'tipo' => DB::table('cf_certificados_legado')->where('id', $segunda)->update(['tipo_documento' => 'CE']),
            'plantilla' => DB::table('cf_certificados_legado')->where('id', $segunda)->update(['plantilla_legado_id' => null]),
            'nombre' => DB::table('cf_certificados_legado')->where('id', $segunda)->update(['nombre_completo' => 'PAR CORREO A ']),
            'estado_documento' => DB::table('cf_certificados_legado')->where('id', $segunda)->update(['snapshot_legado' => json_encode(['documento_estado' => 'letras'] + json_decode((string) DB::table('cf_certificados_legado')->where('id', $segunda)->value('snapshot_legado'), true))]),
        };
        $caso = $this->casoDe(90);
        $antes = $this->evidencia().$this->firmaCaso($caso);

        $this->negado(fn () => $this->correo($caso), ResolucionNoPermitida::HAY_OTRAS_DIFERENCIAS);

        $this->assertSame($antes, $this->evidencia().$this->firmaCaso($caso));
    }

    public function test_evidencia_incompatible_revocado_reemplazado_y_pdf_en_la_no_canonica_detienen_el_caso(): void
    {
        // Una variante SIN código con descargas históricas (imposible en el legado).
        $caso = $this->casoDe(92);
        DB::table('cf_descargas')->insert(['certificado_legado_id' => $this->idCert(92), 'via' => 'portal', 'origen' => 'legado_importado', 'descargado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(92))->update(['codigo_legado' => null]);
        $this->negado(fn () => $this->correo($caso), ResolucionNoPermitida::EVIDENCIA_DE_DESCARGA);

        $caso2 = $this->casoDe(90);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(91))->update(['estado' => 'revocado']);
        $this->negado(fn () => $this->correo($caso2), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(91))->update(['estado' => 'reemplazado']);
        $this->negado(fn () => $this->correo($caso2), ResolucionNoPermitida::CERTIFICADOS_CAMBIARON);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(91))->update(['estado' => 'vigente', 'pdf_archivo' => 'x.pdf']);
        $e = $this->negado(fn () => $this->correo($caso2), ResolucionNoPermitida::PDF_CONGELADO);
        $this->assertSame('Este certificado ya tiene un archivo histórico generado y no puede cambiarse de plantilla directamente.', $e->getMessage());
        // El PDF congelado en la CANÓNICA no cambia su significado: se admite.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(91))->update(['pdf_archivo' => null]);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(90))->update(['pdf_archivo' => 'x.pdf', 'pdf_hash' => str_repeat('a', 64), 'pdf_bytes' => 1]);
        $this->correo($caso2);
        $this->assertSame('ok', $this->cert(90)->conciliacion_estado);
    }

    public function test_el_motivo_es_obligatorio_y_acotado(): void
    {
        $caso = $this->casoDe(90);
        foreach (['', '   ', 'corto', str_repeat('x', 501)] as $m) {
            $this->negado(fn () => $this->correo($caso, $m), ResolucionNoPermitida::TIPO_NO_ADMITIDO);
        }
        $this->assertSame('abierto', $this->estadoCaso($caso));
    }

    // ── Variación cosmética de nombre ───────────────────────────────────────────────────────────────────

    public function test_la_variacion_cosmetica_exige_elegir_la_canonica_y_no_modifica_ningun_nombre(): void
    {
        $caso = $this->casoDe(102);
        $nombres = DB::table('cf_certificados_legado')->orderBy('id')->pluck('nombre_completo', 'id')->all();

        $this->negado(fn () => $this->nombre($caso, null), ResolucionNoPermitida::CANONICO_INVALIDO);   // sin elegir
        $this->negado(fn () => $this->nombre($caso, $this->idCert(90)), ResolucionNoPermitida::CANONICO_INVALIDO);   // una variante de otro caso
        $this->assertSame('abierto', $this->estadoCaso($caso));

        // El administrador elige la MAYOR (no la de menor old_id): su elección manda.
        $r = $this->nombre($caso, $this->idCert(103));

        $this->assertSame($this->idCert(103), $r['canonico_id']);
        $this->assertSame(['duplicado_consolidado', 'ok'], [$this->cert(102)->conciliacion_estado, $this->cert(103)->conciliacion_estado]);
        $this->assertSame($nombres, DB::table('cf_certificados_legado')->orderBy('id')->pluck('nombre_completo', 'id')->all(), 'ningún nombre cambia');
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'nombre_cosmetico_consolidado'], [$f->estado, $f->resolucion]);
        $evento = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->first();
        $this->assertSame('conflicto_nombre_cosmetico_consolidado', $evento->accion);
        $this->assertTrue(json_decode($evento->evidencia, true)['canonico_elegido_por_admin']);
        $this->assertStringNotContainsString('PEREZ', mb_strtoupper($evento->evidencia));
        $this->assertSame($this->idCert(103), ElegibilidadLegado::canonico(CertificadoLegado::find($this->idCert(102)))->id);
    }

    public function test_los_nombres_realmente_distintos_no_se_consolidan_ni_con_eleccion(): void
    {
        $caso = $this->casoDe(104);
        $antes = $this->evidencia().$this->firmaCaso($caso);

        $this->negado(fn () => $this->nombre($caso, $this->idCert(104)), ResolucionNoPermitida::NOMBRE_REALMENTE_DISTINTO);

        $this->assertSame($antes, $this->evidencia().$this->firmaCaso($caso));
    }

    public function test_el_modo_nombre_exige_los_mismos_correos_y_ninguna_otra_diferencia(): void
    {
        DB::table('cf_correos')->where('certificado_legado_id', $this->idCert(103))->update(['correo_normalizado' => 'otro@example.test', 'correo' => 'otro@example.test']);

        $this->negado(fn () => $this->nombre($this->casoDe(102), $this->idCert(102)), ResolucionNoPermitida::HAY_OTRAS_DIFERENCIAS);
    }

    // ── Idempotencia y reversión ─────────────────────────────────────────────────────────────────────────

    public function test_repetir_no_duplica_nada_y_avisa_que_ya_esta_resuelto(): void
    {
        foreach ([[90, 'correo'], [102, 'nombre']] as [$old, $modo]) {
            $caso = $this->casoDe($old);
            $modo === 'correo' ? $this->correo($caso) : $this->nombre($caso, $this->idCert(102));
            $firma = $this->firmaCaso($caso);

            $e = $this->negado(fn () => $modo === 'correo' ? $this->correo($caso) : $this->nombre($caso, $this->idCert(103)), ResolucionNoPermitida::CASO_YA_RESUELTO);

            $this->assertSame('Este caso ya fue resuelto.', $e->getMessage());
            $this->assertSame($firma, $this->firmaCaso($caso));
        }
    }

    public function test_la_auditoria_lleva_evidencia_minima_sin_datos_personales(): void
    {
        $caso = $this->casoDe(94);
        $this->correo($caso);

        $e = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->first();
        $evi = json_decode($e->evidencia, true);
        $this->assertSame(['conflicto_correo_consolidado', 'abierto', 'resuelto', $this->actor(), self::MOTIVO], [$e->accion, $e->estado_anterior, $e->estado_nuevo, (int) $e->actor_id, $e->motivo]);
        foreach (['regla', 'canonico_id', 'consolidadas_ids', 'descargas_historicas', 'estados_anteriores', 'estados_nuevos', 'roles_anteriores', 'mapa_id'] as $k) {
            $this->assertArrayHasKey($k, $evi);
        }
        foreach (['PAR CUATRO', '8200003', 'example.test'] as $privado) {
            $this->assertStringNotContainsString($privado, $e->evidencia);
        }
        $mov = DB::table('movimientos')->where('tipo', 'conciliacion')->latest('id')->first();
        $this->assertArrayNotHasKey('ip', json_decode($mov->metadata, true));
    }

    public function test_revertir_restaura_todo_y_se_puede_volver_a_consolidar(): void
    {
        $caso = $this->casoDe(94);
        $estados = $this->estados();
        $evidencia = $this->evidencia();
        $this->correo($caso);

        $this->variantes()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.');

        $this->assertSame($estados, $this->estados());
        $this->assertSame($evidencia, $this->evidencia());
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->count());
        $this->assertSame(['abierto', null], [$this->estadoCaso($caso), DB::table('cf_conciliaciones')->find($caso)->resolucion]);
        $this->assertSame(['variante', 'variante', 'variante', 'variante'], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso)->pluck('rol')->all());
        $this->assertSame('conflicto_consolidacion_revertida', DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'));
        $this->correo($caso);
        $this->assertSame('ok', $this->cert(94)->conciliacion_estado);
    }

    public function test_no_se_puede_revertir_con_pdf_descarga_nueva_reemplazo_decision_posterior_o_caso_abierto(): void
    {
        $caso = $this->casoDe(90);
        $this->negado(fn () => $this->variantes()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        $this->correo($caso);

        DB::table('cf_certificados_legado')->where('id', $this->idCert(90))->update(['pdf_archivo' => 'x.pdf']);
        $this->negado(fn () => $this->variantes()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::PDF_CONGELADO);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(90))->update(['pdf_archivo' => null]);

        $id = DB::table('cf_descargas')->insertGetId(['certificado_legado_id' => $this->idCert(90), 'via' => 'portal', 'origen' => 'credential_flow', 'descargado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);
        $this->negado(fn () => $this->variantes()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        DB::table('cf_descargas')->where('id', $id)->delete();

        DB::table('cf_certificados_legado')->where('id', $this->idCert(91))->update(['estado' => 'reemplazado']);
        $this->negado(fn () => $this->variantes()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(91))->update(['estado' => 'vigente']);

        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'nota', 'actor_id' => $this->actor(), 'created_at' => now()]);
        $this->negado(fn () => $this->variantes()->revertir($caso, $this->actor(), 'Revierto la consolidación de prueba.'), ResolucionNoPermitida::NO_REVERSIBLE);
        $this->assertSame('resuelto', $this->estadoCaso($caso));
    }

    public function test_el_detector_no_recrea_los_casos_consolidados_ni_cambia_su_historial(): void
    {
        $caso = $this->casoDe(90);
        $this->correo($caso);
        $firma = $this->firmaCaso($caso);

        app(DetectorConciliaciones::class)->ejecutar();
        app(DetectorConciliaciones::class)->ejecutar();

        $this->assertSame($firma, $this->firmaCaso($caso));
        $this->assertSame(1, DB::table('cf_conciliaciones')->where('referencia_clave', $this->cert(90)->grupo_duplicado)->count());
    }

    public function test_el_resultado_acumulado_de_los_casos_consolidables(): void
    {
        $pendientes = DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_conciliacion')->count();
        $this->correo($this->casoDe(90));
        $this->correo($this->casoDe(92));
        $this->correo($this->casoDe(94));
        $this->correo($this->casoDe(98));
        $this->nombre($this->casoDe(102), $this->idCert(102));

        // 2 + 2 + 4 + 2 + 2 filas resueltas; siguen pendientes el de correo y nombre juntos (100/101) y los nombres reales (104/105).
        $this->assertSame($pendientes - 12, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_conciliacion')->count());
        $this->assertSame(6, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'duplicado_consolidado')->whereIn('id', array_map(fn ($o) => $this->idCert($o), [91, 92, 95, 96, 97, 99, 103]))->count() - 1 + 0 + 0);
    }
}
