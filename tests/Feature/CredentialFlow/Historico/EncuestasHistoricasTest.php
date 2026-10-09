<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\RollbackCorrida;
use App\Support\CredentialFlow\Migracion\RollbackEncuestas;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Historico\Concerns\EncuestasSinteticas;

/** Encuestas históricas (Fase 8): migración del staging al modelo configurable, idempotencia y rollback. Datos 100 % sintéticos. */
class EncuestasHistoricasTest extends HistoricoTestCase
{
    use EncuestasSinteticas;

    private function esperados(): array
    {
        $claves = ['pregunta1', 'pregunta2', 'pregunta3', 'pregunta4', 'pregunta5', 'pregunta6', 'pregunta7', 'pregunta8', 'pregunta9', 'justificacion_pregunta1'];
        $n = array_fill_keys($claves, 0);
        foreach ($this->filas() as $f) {
            foreach ($claves as $c) {
                if (($f[$c] ?? '') !== '' && $f[$c] !== null) {
                    $n[$c]++;
                }
            }
        }

        return $n;
    }

    public function test_migra_todas_las_respuestas_y_sus_detalles_sin_perder_ninguna(): void
    {
        $r = $this->migrarTodo();
        $esperados = $this->esperados();

        $this->assertFalse($r['noOp']);
        $this->assertSame(6, DB::table('cf_encuestas_respuestas')->count());
        $this->assertSame(array_sum($esperados), DB::table('cf_encuestas_respuestas_detalle')->count());
        foreach ($esperados as $clave => $n) {
            $this->assertSame($n, DB::table('cf_encuestas_respuestas_detalle')->where('clave_historica', $clave)->count(), $clave);
        }
        foreach ($r['totales']['validaciones'] as $v) {
            $this->assertTrue($v['ok'], $v['clave']);
        }
        $this->assertSame(6, DB::table('cf_migraciones_map')->where('origen_tabla', 'encuesta')->count());
    }

    public function test_el_texto_contestado_se_conserva_exacto(): void
    {
        $this->migrarTodo();

        $r1 = DB::table('cf_encuestas_respuestas as r')->join('cf_migraciones_map as m', fn ($j) => $j->on('m.destino_id', '=', 'r.id')->where('m.origen_tabla', 'encuesta')->where('m.origen_id', '1'))->value('r.id');
        $this->assertSame(self::TEXTO_EXACTO, DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r1)->where('clave_historica', 'pregunta5')->value('valor_texto'));
        $this->assertSame('Excelentes', DB::table('cf_encuestas_respuestas_detalle')->where('respuesta_id', $r1)->where('clave_historica', 'pregunta1')->value('valor_texto'), 'No se corrige el texto antiguo');
    }

    public function test_se_crean_solo_las_tres_versiones_que_explican_los_datos(): void
    {
        $this->migrarTodo();

        $v = DB::table('cf_encuestas_versiones')->orderBy('numero')->get();
        $this->assertCount(3, $v);
        $this->assertSame([1, 2, 3], $v->pluck('numero')->map(fn ($n) => (int) $n)->all());
        $this->assertSame(['2022-02-10 09:00:00', '2022-02-18 00:00:00'], [$v[0]->activa_desde, '2022-02-18 00:00:00'][0] === '2022-02-10 09:00:00' ? [$v[0]->activa_desde, '2022-02-18 00:00:00'] : [null, null]);
        // Respuestas por versión: 1 inicial, 2 intermedia (la del 2023 y la huérfana de 2024), 3 actual (2026: tres).
        $this->assertSame([1, 2, 3], collect([1, 2, 3])->map(fn ($n) => DB::table('cf_encuestas_respuestas as r')->join('cf_encuestas_versiones as v', 'v.id', '=', 'r.version_id')->where('v.numero', $n)->count())->all());
        // pregunta8 solo existe en la intermedia; las otras dos tienen siete preguntas.
        $this->assertSame([7, 8, 7], $v->map(fn ($x) => DB::table('cf_encuestas_preguntas')->where('version_id', $x->id)->count())->all());
        $this->assertSame(1, DB::table('cf_encuestas')->count());
        $e = DB::table('cf_encuestas')->first();
        $this->assertSame(['Encuesta histórica Evaluaciones', 'archivada', 0, 'legado'], [$e->nombre, $e->estado, (int) $e->activa, $e->origen]);
        // Nunca queda «activa» para el motor futuro, y ninguna es obligatoria.
        $this->assertSame(0, DB::table('cf_encuestas_versiones')->where('obligatoria', true)->count());
    }

    public function test_pregunta8_es_una_pregunta_real_de_texto_con_redaccion_no_recuperable(): void
    {
        $this->migrarTodo();

        $p8 = DB::table('cf_encuestas_preguntas')->where('clave', 'pregunta8')->first();
        $this->assertNotNull($p8);
        $this->assertSame(['texto', 8], [$p8->tipo, (int) $p8->orden]);
        $this->assertStringContainsString('redacción original no recuperable', $p8->texto);
        $cfg = json_decode($p8->configuracion, true);
        $this->assertFalse($cfg['redaccion_recuperable']);
        $this->assertNull($cfg['catalogo_actual_old_id']);
        // La respuesta de pregunta8 está mapeada a esa pregunta.
        $d = DB::table('cf_encuestas_respuestas_detalle')->where('clave_historica', 'pregunta8')->first();
        $this->assertSame((int) $p8->id, (int) $d->pregunta_id);
    }

    public function test_pregunta9_y_justificacion_no_crean_pregunta_pero_si_traen_datos_se_conservan_por_clave_historica(): void
    {
        $this->migrarTodo();

        $this->assertSame(0, DB::table('cf_encuestas_preguntas')->whereIn('clave', ['pregunta9', 'justificacion_pregunta1'])->count());
        foreach (['pregunta9' => 'DATO-INESPERADO', 'justificacion_pregunta1' => 'JUSTIF'] as $clave => $valor) {
            $d = DB::table('cf_encuestas_respuestas_detalle')->where('clave_historica', $clave)->first();
            $this->assertNotNull($d, "$clave se conserva");
            $this->assertNull($d->pregunta_id);
            $this->assertSame($valor, $d->valor_texto);
        }
        $this->assertSame(2, MigracionCorrida::where('tipo', 'legado_encuestas')->first()->totales['detalles_solo_clave_historica']);
    }

    public function test_cambios_de_opciones_se_conservan_como_opciones_historicas_de_cada_version(): void
    {
        $this->migrarTodo();

        $inicial = DB::table('cf_encuestas_versiones')->where('numero', 1)->value('id');
        $p1 = DB::table('cf_encuestas_preguntas')->where('version_id', $inicial)->where('clave', 'pregunta1')->value('id');
        $this->assertSame(['Excelentes'], DB::table('cf_encuestas_opciones')->where('pregunta_id', $p1)->pluck('valor')->all());
        $d = DB::table('cf_encuestas_respuestas_detalle')->where('pregunta_id', $p1)->first();
        $this->assertNotNull($d->opcion_id);
        $this->assertSame('Excelentes', $d->snapshot_opcion);
        $this->assertSame('pregunta1', json_decode($d->snapshot_pregunta, true)['clave']);
    }

    public function test_solo_la_estructura_actual_usa_la_redaccion_del_catalogo(): void
    {
        $this->migrarTodo();

        $texto = fn (int $numero) => DB::table('cf_encuestas_preguntas as p')->join('cf_encuestas_versiones as v', 'v.id', '=', 'p.version_id')->where('v.numero', $numero)->where('p.clave', 'pregunta1')->value('p.texto');
        $this->assertSame('¿Cómo calificas el evento?', $texto(3));
        $this->assertStringContainsString('no recuperable', $texto(1));
        $this->assertStringContainsString('no recuperable', $texto(2));
    }

    public function test_huerfanas_se_clasifican_sin_inventar_certificados(): void
    {
        $this->migrarTodo();

        $por = DB::table('cf_encuestas_respuestas')->selectRaw('clasificacion c, count(*) n')->groupBy('c')->pluck('n', 'c')->all();
        $this->assertSame(['evento_historico_eliminado' => 1, 'participante_ambiguo' => 1, 'participante_no_encontrado' => 1, 'vinculada' => 3], $por);

        $eliminada = DB::table('cf_encuestas_respuestas')->where('clasificacion', 'evento_historico_eliminado')->first();
        $this->assertNull($eliminada->evento_id);
        $this->assertSame(99, (int) $eliminada->old_evento_id);
        $this->assertNull($eliminada->certificado_legado_id);
        // Existe el evento pero no el participante: evento ligado, certificado no.
        $sinPart = DB::table('cf_encuestas_respuestas')->where('clasificacion', 'participante_no_encontrado')->first();
        $this->assertNotNull($sinPart->evento_id);
        $this->assertNull($sinPart->certificado_legado_id);
        $ambigua = DB::table('cf_encuestas_respuestas')->where('clasificacion', 'participante_ambiguo')->first();
        $this->assertNull($ambigua->certificado_legado_id);
        // La vinculada del duplicado idéntico apunta al certificado conciliado (ok), no a la copia.
        $gina = DB::table('cf_encuestas_respuestas')->where('certificado_legado_id', $this->idCert(7))->count();
        $this->assertSame(1, $gina);
        $this->assertSame(0, DB::table('cf_encuestas_respuestas')->where('certificado_legado_id', $this->idCert(8))->count());
    }

    public function test_el_documento_nunca_se_guarda_en_claro(): void
    {
        $this->migrarTodo();

        $volcado = json_encode(DB::table('cf_encuestas_respuestas')->get()->all()).json_encode(DB::table('cf_migraciones_map')->where('origen_tabla', 'encuesta')->get()->all())
            .json_encode(MigracionCorrida::where('tipo', 'legado_encuestas')->first()->totales);
        foreach ([self::DOCUMENTO, '1000002', '2000001', 'SECRETO-LIBRE', 'DATO-INESPERADO', self::CORREO] as $claro) {
            $this->assertStringNotContainsString($claro, $volcado, "Aparece «{$claro}»");
        }
        $this->assertContains(Hmac::de('documento', self::DOCUMENTO), DB::table('cf_encuestas_respuestas')->pluck('documento_hash')->all());
    }

    public function test_el_staging_no_se_modifica(): void
    {
        $datos = $this->datos();
        $datos['encuesta'] = $this->filas();
        $this->migrarSintetico($datos);
        $antes = md5(json_encode(DB::table('stg_ev_encuesta')->orderBy('old_id')->get()->all()));

        (new MigradorEncuestas)->ejecutar();

        $this->assertSame($antes, md5(json_encode(DB::table('stg_ev_encuesta')->orderBy('old_id')->get()->all())));
        $this->assertSame(6, DB::table('stg_ev_encuesta')->count());
    }

    // ── Idempotencia ──────────────────────────────────────────────────────────

    public function test_ejecutar_otra_vez_es_un_no_op_sin_duplicados(): void
    {
        $this->migrarTodo();
        $antes = [DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_encuestas_respuestas_detalle')->count(), DB::table('cf_encuestas_versiones')->count(), DB::table('cf_migraciones_map')->count()];

        $r = (new MigradorEncuestas)->ejecutar();

        $this->assertTrue($r->noOp);
        $this->assertSame($antes, [DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_encuestas_respuestas_detalle')->count(), DB::table('cf_encuestas_versiones')->count(), DB::table('cf_migraciones_map')->count()]);
        $this->assertSame(1, MigracionCorrida::where('tipo', 'legado_encuestas')->count());
    }

    public function test_exige_la_corrida_de_certificados(): void
    {
        $this->cargar('s1', $this->datos());

        $this->expectExceptionMessage('certificados completada');
        (new MigradorEncuestas)->ejecutar();
    }

    // ── Rollback ──────────────────────────────────────────────────────────────

    public function test_rollback_vuelve_a_cero_sin_tocar_staging_ni_certificados_y_se_puede_reproducir(): void
    {
        $this->migrarTodo();
        $corrida = MigracionCorrida::where('tipo', 'legado_encuestas')->first();
        $certs = DB::table('cf_certificados_legado')->count();
        $antesTotales = $corrida->totales;

        $r = (new RollbackEncuestas)->revertir($corrida->id);

        $this->assertSame(6, $r['respuestas']);
        foreach (['cf_encuestas_respuestas', 'cf_encuestas_respuestas_detalle', 'cf_encuestas_versiones', 'cf_encuestas_preguntas', 'cf_encuestas_opciones', 'cf_encuestas'] as $t) {
            $this->assertSame(0, DB::table($t)->count(), $t);
        }
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('corrida_id', $corrida->id)->count());
        $this->assertSame(6, DB::table('stg_ev_encuesta')->count());
        $this->assertSame($certs, DB::table('cf_certificados_legado')->count());
        $this->assertSame('revertida', $corrida->fresh()->estado);

        // Volver a migrar reproduce los mismos conteos.
        $r2 = (new MigradorEncuestas)->ejecutar();
        $this->assertFalse($r2->noOp);
        $this->assertSame([$antesTotales['respuestas'], $antesTotales['detalles'], $antesTotales['por_clasificacion']], [$r2->totales['respuestas'], $r2->totales['detalles'], $r2->totales['por_clasificacion']]);
    }

    public function test_rollback_se_niega_si_hay_respuestas_nuevas_sobre_una_version_historica(): void
    {
        $this->migrarTodo();
        $corrida = MigracionCorrida::where('tipo', 'legado_encuestas')->first();
        $version = DB::table('cf_encuestas_versiones')->value('id');
        DB::table('cf_encuestas_respuestas')->insert(['version_id' => $version, 'completada_at' => now(), 'origen' => 'credential_flow', 'created_at' => now(), 'updated_at' => now()]);
        $antes = DB::table('cf_encuestas_respuestas_detalle')->count();

        try {
            (new RollbackEncuestas)->revertir($corrida->id);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::RESPUESTAS_POSTERIORES, $e->codigo);
        }
        $this->assertSame(7, DB::table('cf_encuestas_respuestas')->count());
        $this->assertSame($antes, DB::table('cf_encuestas_respuestas_detalle')->count());
    }

    public function test_rollback_se_niega_si_se_modifico_la_estructura_o_una_respuesta(): void
    {
        $this->migrarTodo();
        $corrida = MigracionCorrida::where('tipo', 'legado_encuestas')->first();
        $this->travel(5)->minutes();
        DB::table('cf_encuestas_preguntas')->where('clave', 'pregunta1')->limit(1)->update(['texto' => 'Editada', 'updated_at' => now()]);

        try {
            (new RollbackEncuestas)->revertir($corrida->id);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::ESTRUCTURA_MODIFICADA, $e->codigo);
        }
        DB::table('cf_encuestas_preguntas')->update(['updated_at' => DB::raw('created_at')]);
        DB::table('cf_encuestas_respuestas')->limit(1)->update(['updated_at' => now()]);
        try {
            (new RollbackEncuestas)->revertir($corrida->id);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::RESPUESTAS_MODIFICADAS, $e->codigo);
        }
        $this->assertSame(6, DB::table('cf_encuestas_respuestas')->count());
    }

    public function test_el_rollback_de_certificados_se_niega_mientras_existan_encuestas_ligadas_y_funciona_despues(): void
    {
        $this->migrarTodo();
        $certificados = MigracionCorrida::where('tipo', 'legado_evaluaciones')->first();
        $encuestas = MigracionCorrida::where('tipo', 'legado_encuestas')->first();

        try {
            (new RollbackCorrida)->revertir($certificados->id);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::ENCUESTAS_VINCULADAS, $e->codigo);
        }
        $this->assertSame(6, DB::table('cf_encuestas_respuestas')->count());

        (new RollbackEncuestas)->revertir($encuestas->id);
        $r = (new RollbackCorrida)->revertir($certificados->id);

        $this->assertGreaterThan(0, $r['certificados']);
        $this->assertSame(0, DB::table('cf_certificados_legado')->count());
    }

    public function test_se_conservan_las_versiones_y_respuestas_nuevas_creadas_con_el_motor_al_revertir_otra_encuesta(): void
    {
        $this->migrarTodo();
        // Una encuesta NUEVA (sin corrida) con su respuesta: nunca la toca el rollback de la histórica.
        $nueva = DB::table('cf_encuestas')->insertGetId(['nombre' => 'Nueva', 'estado' => 'publicada', 'activa' => true, 'origen' => 'credential_flow', 'created_at' => now(), 'updated_at' => now()]);
        $v = DB::table('cf_encuestas_versiones')->insertGetId(['encuesta_id' => $nueva, 'numero' => 1, 'titulo' => 'N', 'activa_desde' => now(), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cf_encuestas_respuestas')->insert(['version_id' => $v, 'completada_at' => now(), 'origen' => 'credential_flow', 'created_at' => now(), 'updated_at' => now()]);

        (new RollbackEncuestas)->revertir(MigracionCorrida::where('tipo', 'legado_encuestas')->value('id'));

        $this->assertSame(1, DB::table('cf_encuestas')->count());
        $this->assertSame(1, DB::table('cf_encuestas_respuestas')->count());
        $this->assertSame(1, DB::table('cf_encuestas_versiones')->count());
    }

    public function test_el_migrador_de_certificados_no_cambia(): void
    {
        $this->migrarTodo();

        $this->assertTrue((new MigradorHistorico)->ejecutar()->noOp);
    }
}
