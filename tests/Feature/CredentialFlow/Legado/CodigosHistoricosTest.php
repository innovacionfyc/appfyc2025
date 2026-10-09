<?php

namespace Tests\Feature\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\CodigoHistoricoAsignado;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use App\Support\CredentialFlow\Legado\CongeladoNoPermitido;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use App\Support\CredentialFlow\Legado\RendererLegado;
use App\Support\CredentialFlow\Legado\RenderNoPermitido;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Migracion\RollbackCorrida;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use LogicException;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Códigos históricos asignados por Credential Flow (Fase 10B-1.5): modelo, contador, rango, resolución, asignación perezosa e idempotente,
 * duplicados, elegibilidad, congelado, inmutabilidad del histórico y verificación pública. Datos 100 % sintéticos.
 *
 * Pares (evento 1 = plantilla ok, evento 2 = imagen faltante): 60 ok sin código · 61+62 duplicado idéntico sin código (canónico 61) ·
 * 63 con código legado 6001 · 64+65 mismo par, 64 con código 6002 y 65 NULL (variantes de solo-código) · 66 con otro nombre en el mismo
 * documento que 60 · 77 sin plantilla (evento 2) · 78 documento con separadores (revisión).
 */
class CodigosHistoricosTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');

        $p = fn (int $id, int $evento, string $doc, string $nombre, ?int $verif) => [
            'id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => "d{$doc}@example.test", 'id_evento' => $evento, 'num_verificacion' => $verif,
        ];
        $d = $this->datos();
        foreach ([
            $p(60, 1, '8000001', 'SIN CODIGO UNO', null), $p(61, 1, '8000002', 'DUPLICADA DOS', null), $p(62, 1, '8000002', 'DUPLICADA DOS', null),
            $p(63, 1, '8000003', 'CON CODIGO TRES', 6001), $p(64, 1, '8000004', 'VARIANTE CUATRO', 6002), $p(65, 1, '8000004', 'VARIANTE CUATRO', null),
            $p(66, 1, '8000005', 'OTRA PERSONA', null), $p(77, 2, '8000006', 'SIN PLANTILLA', null), $p(78, 1, '8.000.007', 'DOCUMENTO RARO', null),
        ] as $fila) {
            $d['participante'][] = $fila;
        }
        $this->migrarSintetico($d);
    }

    private function cert(int $old): CertificadoLegado
    {
        return CertificadoLegado::findOrFail($this->idCert($old));
    }

    private function codigos(): CodigoHistorico
    {
        return new CodigoHistorico;
    }

    private function congelador(?string $dir = null): CongeladorLegado
    {
        return new CongeladorLegado(new RendererLegado, new ResolutorPlantillaDirectorio($dir ?? $this->dirImagenes));
    }

    private function registros(): int
    {
        return DB::table('cf_codigos_historicos')->count();
    }

    private function contador(): int
    {
        return (int) DB::table('cf_codigo_historico_contador')->value('siguiente');
    }

    /** Lo histórico que esta fase NO puede tocar nunca (todas las columnas de los certificados, snapshots incluidos). */
    private function historico(): string
    {
        return md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($c) => collect((array) $c)->except(['pdf_archivo', 'pdf_hash', 'pdf_bytes', 'materializado_at', 'updated_at', 'update_by', 'intentos_generacion', 'ultimo_error_codigo'])->all())->all()));
    }

    // ── Modelo, contador y rango ─────────────────────────────────────────────────────────────────────────

    public function test_la_migracion_crea_las_dos_tablas_y_el_contador_con_su_unica_fila(): void
    {
        $this->assertTrue(Schema::hasColumns('cf_codigos_historicos', ['id', 'codigo', 'evento_id', 'certificado_canonico_id', 'par_hash', 'asignado_at', 'origen', 'created_at', 'updated_at']));
        $f = DB::table('cf_codigo_historico_contador')->get();
        $this->assertCount(1, $f);
        $this->assertSame([1, 50000, 99999, 50000], [(int) $f[0]->id, (int) $f[0]->inicio, (int) $f[0]->fin, (int) $f[0]->siguiente]);
        // La tabla de certificados NO cambia de forma.
        $this->assertFalse(Schema::hasColumn('cf_certificados_legado', 'codigo_historico'));
    }

    public function test_par_hash_es_estable_distingue_eventos_y_no_contiene_el_documento(): void
    {
        $a = CodigoHistorico::parHash(1, '8000001');

        $this->assertSame($a, CodigoHistorico::parHash(1, '8000001'));
        $this->assertNotSame($a, CodigoHistorico::parHash(2, '8000001'));
        $this->assertNotSame($a, CodigoHistorico::parHash(1, '8000002'));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $a);
        $this->assertStringNotContainsString('8000001', $a);
    }

    public function test_el_registro_no_guarda_el_documento_en_claro(): void
    {
        $this->codigos()->resolverOAsignar($this->cert(60));

        $this->assertStringNotContainsString('8000001', json_encode(DB::table('cf_codigos_historicos')->get()));
        $this->assertStringNotContainsString('SIN CODIGO', json_encode(DB::table('cf_codigos_historicos')->get()));
    }

    // ── resolver(): nunca asigna ─────────────────────────────────────────────────────────────────────────

    public function test_resolver_devuelve_el_codigo_legado_existente_de_cualquier_fila_del_par(): void
    {
        $this->assertSame(['codigo' => '6001', 'origen' => 'legado'], $this->codigos()->resolver($this->cert(63)));
        // Par con una fila codificada y otra NULL: las dos resuelven al código del sistema viejo.
        $this->assertSame(['codigo' => '6002', 'origen' => 'legado'], $this->codigos()->resolver($this->cert(64)));
        $this->assertSame(['codigo' => '6002', 'origen' => 'legado'], $this->codigos()->resolver($this->cert(65)));
    }

    public function test_resolver_devuelve_null_si_no_hay_ninguno_y_no_escribe_nada(): void
    {
        $antes = $this->registros().'|'.$this->contador();

        $sentencias = $this->sentencias(fn () => $this->assertNull($this->codigos()->resolver($this->cert(60))));

        $this->assertSame($antes, $this->registros().'|'.$this->contador());
        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }
    }

    public function test_resolver_devuelve_el_codigo_de_credential_flow_si_existe(): void
    {
        $codigo = $this->codigos()->resolverOAsignar($this->cert(60));

        $this->assertSame(['codigo' => $codigo, 'origen' => 'credential_flow'], $this->codigos()->resolver($this->cert(60)));
    }

    public function test_para_filas_resuelve_un_listado_sin_n_mas_1_y_sin_asignar(): void
    {
        $this->codigos()->resolverOAsignar($this->cert(60));
        $filas = DB::table('cf_certificados_legado')->whereIn('id', array_map(fn ($o) => $this->idCert($o), [60, 61, 62, 63, 64, 65, 66]))->get(['id', 'evento_id', 'documento_clave', 'codigo_legado']);
        $n = $this->registros();

        $consultas = $this->sentencias(function () use ($filas, &$mapa) {
            $mapa = CodigoHistorico::paraFilas($filas);
        });

        $this->assertCount(1, $consultas);
        $this->assertSame($n, $this->registros());
        $this->assertSame('50000', $mapa[$this->idCert(60)]);
        $this->assertSame('6001', $mapa[$this->idCert(63)]);
        $this->assertSame('6002', $mapa[$this->idCert(65)]);
        $this->assertNull($mapa[$this->idCert(66)]);
        $this->assertNull($mapa[$this->idCert(61)]);
    }

    // ── resolverOAsignar() ───────────────────────────────────────────────────────────────────────────────

    public function test_asigna_el_primer_codigo_del_rango_con_su_registro_y_mueve_el_contador(): void
    {
        $c = $this->cert(60);

        $codigo = $this->codigos()->resolverOAsignar($c);

        $this->assertSame('50000', $codigo);
        $r = DB::table('cf_codigos_historicos')->first();
        $this->assertSame(['50000', (int) $c->evento_id, (int) $c->id, 'credential_flow', CodigoHistorico::parHash((int) $c->evento_id, $c->documento_clave)], [$r->codigo, (int) $r->evento_id, (int) $r->certificado_canonico_id, $r->origen, $r->par_hash]);
        $this->assertNotNull($r->asignado_at);
        $this->assertSame(50001, $this->contador());
    }

    public function test_es_idempotente_el_mismo_par_siempre_recibe_el_mismo_codigo(): void
    {
        $a = $this->codigos()->resolverOAsignar($this->cert(60));
        $b = $this->codigos()->resolverOAsignar($this->cert(60));

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->registros());
        $this->assertSame(50001, $this->contador());
    }

    public function test_pares_distintos_reciben_codigos_distintos_y_consecutivos(): void
    {
        $a = $this->codigos()->resolverOAsignar($this->cert(60));
        $b = $this->codigos()->resolverOAsignar($this->cert(66));

        $this->assertSame(['50000', '50001'], [$a, $b]);
        $this->assertSame(50002, $this->contador());
    }

    public function test_el_mismo_documento_en_otro_evento_es_otro_par(): void
    {
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->first();
        unset($molde['id']);
        $otro = DB::table('cf_certificados_legado')->insertGetId(['evento_id' => $this->idEvento('Curso Beta 2025')] + $molde);

        $a = $this->codigos()->resolverOAsignar($this->cert(60));
        $b = $this->codigos()->resolverOAsignar(CertificadoLegado::findOrFail($otro));

        $this->assertNotSame($a, $b);
    }

    public function test_si_el_par_ya_tiene_codigo_legado_se_reutiliza_y_no_se_crea_otro(): void
    {
        $this->assertSame('6001', $this->codigos()->resolverOAsignar($this->cert(63)));
        $this->assertSame('6002', $this->codigos()->resolverOAsignar($this->cert(65)));   // la fila NULL del par reutiliza el código existente

        $this->assertSame(0, $this->registros());
        $this->assertSame(50000, $this->contador());
    }

    // ── Duplicados ───────────────────────────────────────────────────────────────────────────────────────

    public function test_un_duplicado_identico_sin_codigo_comparte_un_solo_codigo_para_el_par(): void
    {
        $canonico = ElegibilidadLegado::canonico($this->cert(62));
        $this->assertSame($this->idCert(61), $canonico->id);

        $a = $this->codigos()->resolverOAsignar($canonico);
        $b = $this->codigos()->resolverOAsignar($this->cert(62));   // aunque se pida desde la fila duplicada

        $this->assertSame($a, $b);
        $this->assertSame(1, $this->registros());
        $this->assertSame($this->idCert(61), (int) DB::table('cf_codigos_historicos')->value('certificado_canonico_id'));
        $this->assertSame($a, $this->codigos()->resolver($this->cert(61))['codigo']);
        $this->assertSame($a, $this->codigos()->resolver($this->cert(62))['codigo']);
    }

    // ── Conflictos y colisiones ──────────────────────────────────────────────────────────────────────────

    public function test_un_par_con_dos_codigos_legados_distintos_se_niega_sin_elegir_ninguno(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(65))->update(['codigo_legado' => '6003']);

        foreach ([fn () => $this->codigos()->resolver($this->cert(64)), fn () => $this->codigos()->resolverOAsignar($this->cert(64))] as $f) {
            try {
                $f();
                $this->fail('Debió negarse.');
            } catch (CodigoHistoricoException $e) {
                $this->assertSame(CodigoHistoricoException::CODIGOS_EN_CONFLICTO, $e->codigo);
            }
        }
        $this->assertSame(0, $this->registros());
    }

    public function test_un_par_con_codigo_legado_y_codigo_asignado_distintos_se_niega(): void
    {
        $c = $this->cert(60);
        DB::table('cf_codigos_historicos')->insert(['codigo' => '50100', 'evento_id' => $c->evento_id, 'certificado_canonico_id' => $c->id, 'par_hash' => CodigoHistorico::parHash((int) $c->evento_id, $c->documento_clave), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cf_certificados_legado')->where('id', $c->id)->update(['codigo_legado' => '7777']);

        $this->expectException(CodigoHistoricoException::class);
        $this->codigos()->resolver($c);
    }

    public function test_una_colision_con_un_codigo_legado_avanza_al_siguiente_sin_pisar_nada(): void
    {
        // Un código legado «escapado» al rango reservado: aunque el contador diga que 50000 está libre, no se usa.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(63))->update(['codigo_legado' => '50000']);

        $codigo = $this->codigos()->resolverOAsignar($this->cert(60));

        $this->assertSame('50001', $codigo);
        $this->assertSame(50002, $this->contador());
        $this->assertSame('50000', DB::table('cf_certificados_legado')->where('id', $this->idCert(63))->value('codigo_legado'));
    }

    public function test_una_colision_con_un_codigo_ya_asignado_avanza_al_siguiente(): void
    {
        $otro = $this->cert(66);
        DB::table('cf_codigos_historicos')->insert(['codigo' => '50000', 'evento_id' => $otro->evento_id, 'certificado_canonico_id' => $otro->id, 'par_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame('50001', $this->codigos()->resolverOAsignar($this->cert(60)));
    }

    // ── Frontera del rango ───────────────────────────────────────────────────────────────────────────────

    public function test_la_frontera_99999_se_asigna_si_esta_libre_y_el_siguiente_falla_controladamente(): void
    {
        DB::table('cf_codigo_historico_contador')->update(['siguiente' => 99999]);

        $this->assertSame('99999', $this->codigos()->resolverOAsignar($this->cert(60)));
        $this->assertSame(100000, $this->contador());

        $antes = $this->registros();
        try {
            $this->codigos()->resolverOAsignar($this->cert(66));
            $this->fail('Debió fallar: rango agotado.');
        } catch (CodigoHistoricoException $e) {
            $this->assertSame('RANGO_CODIGOS_HISTORICOS_AGOTADO', $e->codigo);
        }
        $this->assertSame($antes, $this->registros());
        $this->assertSame(100000, $this->contador());
        // Nunca 6 dígitos: el único código del rango tiene 5.
        $this->assertSame([5], DB::table('cf_codigos_historicos')->pluck('codigo')->map(fn ($c) => strlen($c))->unique()->values()->all());
        // Un par que ya tiene código sigue resolviéndose aunque el rango esté agotado.
        $this->assertSame('99999', $this->codigos()->resolverOAsignar($this->cert(60)));
    }

    public function test_si_los_numeros_del_rango_estan_todos_ocupados_se_agota(): void
    {
        DB::table('cf_codigo_historico_contador')->update(['siguiente' => 99998]);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(63))->update(['codigo_legado' => '99998']);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(66))->update(['codigo_legado' => '99999']);

        $this->expectException(CodigoHistoricoException::class);
        $this->codigos()->resolverOAsignar($this->cert(60));
    }

    // ── Inmutabilidad ────────────────────────────────────────────────────────────────────────────────────

    public function test_un_codigo_asignado_es_inmutable_no_se_modifica_ni_se_borra(): void
    {
        $this->codigos()->resolverOAsignar($this->cert(60));
        $m = CodigoHistoricoAsignado::firstOrFail();

        foreach ([fn () => $m->update(['codigo' => '99999']), fn () => $m->delete(), fn () => CodigoHistoricoAsignado::query()->delete(), fn () => CodigoHistoricoAsignado::where('id', $m->id)->update(['codigo' => '1'])] as $f) {
            try {
                $f();
                $this->fail('Debió negarse.');
            } catch (LogicException) {
            }
        }
        $this->assertSame('50000', DB::table('cf_codigos_historicos')->value('codigo'));
    }

    public function test_la_base_impide_codigos_o_pares_repetidos_y_borrar_lo_referenciado(): void
    {
        $c = $this->cert(60);
        $this->codigos()->resolverOAsignar($c);
        $fila = (array) DB::table('cf_codigos_historicos')->first();
        unset($fila['id']);
        $debeFallar = function (callable $f, string $que) {
            try {
                $f();
                $this->fail($que);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };

        $debeFallar(fn () => DB::table('cf_codigos_historicos')->insert(['par_hash' => str_repeat('c', 64)] + $fila), 'código repetido');
        $debeFallar(fn () => DB::table('cf_codigos_historicos')->insert(['codigo' => '50555'] + $fila), 'par repetido');
        $debeFallar(fn () => DB::table('cf_certificados_legado')->where('id', $c->id)->delete(), 'borrar el certificado canónico de un código');
        $debeFallar(fn () => DB::table('cf_eventos')->where('id', $c->evento_id)->delete(), 'borrar el evento de un código');
    }

    public function test_el_rollback_tecnico_de_la_migracion_se_niega_si_hay_codigos_asignados(): void
    {
        $this->codigos()->resolverOAsignar($this->cert(60));
        $corrida = (int) DB::table('cf_certificados_legado')->value('corrida_id');
        $antes = DB::table('cf_certificados_legado')->count();

        try {
            (new RollbackCorrida)->revertir($corrida);
            $this->fail('Debió negarse.');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CODIGOS_ASIGNADOS, $e->codigo);
        }
        $this->assertSame($antes, DB::table('cf_certificados_legado')->count());
        $this->assertSame(1, $this->registros());
    }

    // ── Elegibilidad ─────────────────────────────────────────────────────────────────────────────────────

    public function test_un_codigo_nulo_por_si_solo_ya_no_es_un_defecto_y_la_consulta_no_asigna_nada(): void
    {
        $this->assertNull($this->cert(60)->codigo_legado);
        $this->assertSame('ok', $this->cert(60)->conciliacion_estado);

        $sentencias = $this->sentencias(fn () => $this->assertNull(ElegibilidadLegado::motivo($this->cert(60))));

        $this->assertSame(0, $this->registros());
        $this->assertSame(50000, $this->contador());
        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }
        $this->assertNull(ElegibilidadLegado::motivo($this->cert(63)));   // con código sigue igual
    }

    public function test_los_demas_bloqueos_siguen_vigentes_aunque_falte_el_codigo(): void
    {
        $this->assertSame(ElegibilidadLegado::PENDIENTE_PLANTILLA, ElegibilidadLegado::motivo($this->cert(77)));
        $this->assertSame(ElegibilidadLegado::REVISION_DOCUMENTO, ElegibilidadLegado::motivo($this->cert(78)));
        $this->assertSame(ElegibilidadLegado::PENDIENTE_CONCILIACION, ElegibilidadLegado::motivo($this->cert(65)));

        DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->update(['estado' => 'revocado']);
        $this->assertSame(ElegibilidadLegado::REVOCADO, ElegibilidadLegado::motivo($this->cert(60)));
        DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->update(['estado' => 'reemplazado']);
        $this->assertSame(ElegibilidadLegado::REEMPLAZADO, ElegibilidadLegado::motivo($this->cert(60)));
        DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->update(['estado' => 'vigente', 'nombre_completo' => ' ']);
        $this->assertSame(ElegibilidadLegado::DATOS_FALTANTES, ElegibilidadLegado::motivo($this->cert(60)), 'faltan datos reales (nombre)');
    }

    // ── Renderer y congelador ────────────────────────────────────────────────────────────────────────────

    public function test_el_renderer_sigue_estricto_y_no_genera_codigos(): void
    {
        $r = new RendererLegado;
        $this->assertTrue(method_exists($r, 'render'));
        $this->assertSame([], array_filter(get_class_methods($r), fn ($m) => str_contains(strtolower($m), 'asignar') || str_contains(strtolower($m), 'codigo')));
        $this->assertStringNotContainsString('CodigoHistorico', (string) file_get_contents((new \ReflectionClass(RendererLegado::class))->getFileName()));
    }

    public function test_la_primera_generacion_asigna_el_codigo_congela_y_la_segunda_reutiliza_todo(): void
    {
        $c = $this->cert(60);

        $a = $this->congelador()->servir($c);
        $codigo = DB::table('cf_codigos_historicos')->value('codigo');
        $b = $this->congelador()->servir($c->fresh());

        $this->assertTrue($a->recienGenerado);
        $this->assertFalse($b->recienGenerado);
        $this->assertSame($a->sha256, $b->sha256);
        $this->assertSame('50000', $codigo);
        $this->assertSame(1, $this->registros());
        $this->assertSame(50001, $this->contador());
        $this->assertNull($c->fresh()->codigo_legado, 'codigo_legado conserva solo lo del sistema viejo');
        $this->assertSame(['codigo' => '50000', 'origen' => 'credential_flow'], $this->codigos()->resolver($c));
    }

    public function test_el_codigo_asignado_es_el_que_se_imprime_y_cambia_el_pdf(): void
    {
        $con = $this->congelador()->servir($this->cert(60));
        // Otro par distinto → otro código → otro PDF (el código es parte del contenido impreso).
        DB::table('cf_certificados_legado')->where('id', $this->idCert(66))->update(['nombre_completo' => 'SIN CODIGO UNO', 'documento' => '8000001']);   // mismo texto impreso, par distinto
        $otro = $this->congelador()->servir($this->cert(66));

        $this->assertNotSame($con->sha256, $otro->sha256);
        $this->assertSame(2, $this->registros());
    }

    public function test_si_el_render_falla_despues_de_asignar_el_codigo_queda_reservado_y_se_reutiliza(): void
    {
        $vacio = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cf_sin_imagenes_'.bin2hex(random_bytes(4));
        mkdir($vacio);
        try {
            $this->congelador($vacio)->servir($this->cert(60));
            $this->fail('Debió fallar el render (imagen ausente).');
        } catch (RenderNoPermitida $e) {
            $this->assertSame(RenderNoPermitido::ARCHIVO_AUSENTE, $e->codigo);
        } catch (RenderNoPermitido $e) {
            $this->assertSame(RenderNoPermitido::ARCHIVO_AUSENTE, $e->codigo);
        } finally {
            @rmdir($vacio);
        }

        // El código ya estaba CONFIRMADO antes del render: queda reservado.
        $this->assertSame('50000', DB::table('cf_codigos_historicos')->value('codigo'));
        $this->assertNull($this->cert(60)->pdf_archivo);

        // El reintento (ahora bien) reutiliza el mismo número: nunca se emite otro.
        $this->congelador()->servir($this->cert(60));
        $this->assertSame(1, $this->registros());
        $this->assertSame(50001, $this->contador());
    }

    public function test_un_duplicado_consolidado_se_genera_desde_su_canonico_con_un_unico_codigo(): void
    {
        $this->assertSame('duplicado_consolidado', $this->cert(62)->conciliacion_estado);

        $a = $this->congelador()->servir($this->cert(62));

        $this->assertSame(1, $this->registros());
        $this->assertSame($this->idCert(61), (int) DB::table('cf_codigos_historicos')->value('certificado_canonico_id'));
        $this->assertNotNull($this->cert(61)->pdf_archivo);
        $this->assertNull($this->cert(62)->pdf_archivo);
        $this->assertSame($a->sha256, $this->congelador()->servir($this->cert(61))->sha256);
    }

    public function test_un_certificado_con_codigo_legado_no_asigna_nada_al_generarse(): void
    {
        $this->congelador()->servir($this->cert(63));

        $this->assertSame(0, $this->registros());
        $this->assertSame(50000, $this->contador());
    }

    public function test_el_par_con_una_fila_con_codigo_y_otra_nula_usa_el_existente(): void
    {
        // Ya resuelto el par (la fila NULL es la canónica y es ok): reutiliza 6002.
        DB::table('cf_certificados_legado')->whereIn('id', [$this->idCert(64), $this->idCert(65)])->update(['conciliacion_estado' => 'ok', 'grupo_duplicado' => null]);

        $this->congelador()->servir($this->cert(65));

        $this->assertSame(0, $this->registros());
        $this->assertSame(['codigo' => '6002', 'origen' => 'legado'], $this->codigos()->resolver($this->cert(65)));
    }

    public function test_un_pdf_congelado_sin_ningun_codigo_es_un_estado_imposible_y_no_se_inventa_otro(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->update(['pdf_archivo' => 'credential-flow/legado/certificados/1/certificado.pdf', 'pdf_hash' => str_repeat('a', 64), 'pdf_bytes' => 10]);

        try {
            $this->congelador()->servir($this->cert(60));
            $this->fail('Debió cortarse con un error controlado.');
        } catch (CodigoHistoricoException $e) {
            $this->assertSame(CodigoHistoricoException::PDF_SIN_CODIGO, $e->codigo);
        }
        $this->assertSame(0, $this->registros());
        $this->assertSame(50000, $this->contador());
    }

    public function test_los_certificados_no_elegibles_no_consumen_codigos(): void
    {
        foreach ([77, 78, 65] as $old) {
            try {
                $this->congelador()->servir($this->cert($old));
                $this->fail('No era elegible.');
            } catch (CongeladoNoPermitido) {
            }
        }

        $this->assertSame(0, $this->registros());
        $this->assertSame(50000, $this->contador());
    }

    // ── Histórico inmutable ──────────────────────────────────────────────────────────────────────────────

    public function test_asignar_y_congelar_no_cambian_codigo_legado_ni_snapshots_ni_ningun_dato_historico(): void
    {
        $antes = $this->historico();
        $snapshots = DB::table('cf_certificados_legado')->orderBy('id')->pluck('snapshot_legado', 'id')->all();
        $legados = DB::table('cf_certificados_legado')->orderBy('id')->pluck('codigo_legado', 'id')->all();

        $this->congelador()->servir($this->cert(60));
        $this->codigos()->resolverOAsignar($this->cert(66));

        $this->assertSame($antes, $this->historico());
        $this->assertSame($snapshots, DB::table('cf_certificados_legado')->orderBy('id')->pluck('snapshot_legado', 'id')->all());
        $this->assertSame($legados, DB::table('cf_certificados_legado')->orderBy('id')->pluck('codigo_legado', 'id')->all());
        $this->assertSame(2, $this->registros());
    }

    public function test_la_asignacion_solo_escribe_en_las_tablas_de_codigos_y_en_movimientos(): void
    {
        $sentencias = $this->sentencias(fn () => $this->codigos()->resolverOAsignar($this->cert(60)));
        $escritas = [];
        foreach ($sentencias as $sql) {
            if (preg_match('/^\s*(insert)\s+into\s+[`"]?(\w+)/i', $sql, $m) || preg_match('/^\s*(update)\s+[`"]?(\w+)/i', $sql, $m) || preg_match('/^\s*(delete)\s+from\s+[`"]?(\w+)/i', $sql, $m)) {
                $escritas[strtolower($m[1]).':'.$m[2]] = true;
            }
        }

        $this->assertEqualsCanonicalizing(['insert:cf_codigos_historicos', 'update:cf_codigo_historico_contador', 'insert:movimientos'], array_keys($escritas));
    }

    public function test_se_audita_la_asignacion_sin_datos_personales(): void
    {
        $this->codigos()->resolverOAsignar($this->cert(60));

        $m = DB::table('movimientos')->where('tipo', 'codigo_historico')->first();
        $this->assertNotNull($m);
        $meta = json_decode($m->metadata, true);
        $this->assertSame(['50000', $this->idCert(60), 'credential_flow'], [$meta['codigo'], $meta['certificado_canonico_id'], $meta['origen']]);
        $this->assertArrayNotHasKey('ip', $meta);
        foreach (['8000001', 'SIN CODIGO', 'example.test'] as $privado) {
            $this->assertStringNotContainsString($privado, json_encode($m));
        }
    }

    // ── Verificación pública ─────────────────────────────────────────────────────────────────────────────

    public function test_un_codigo_asignado_funciona_en_la_verificacion_publica_sin_revelar_su_origen(): void
    {
        $this->congelador()->servir($this->cert(60));
        $legado = $this->get('/verificar/6001');
        $this->app['auth']->forgetGuards();
        $asignado = $this->get('/verificar/50000');

        $asignado->assertOk()->assertSee('Certificado histórico válido')->assertSee('Curso Alfa 2024')->assertSee('50000');
        $asignado->assertDontSee('Credential Flow')->assertDontSee('asignado');
        // Misma forma de respuesta que un código del sistema viejo.
        $this->assertSame($legado->getStatusCode(), $asignado->getStatusCode());
        $this->assertSame(substr_count($legado->getContent(), 'data-dato='), substr_count($asignado->getContent(), 'data-dato='));
        $this->assertStringNotContainsString('SIN CODIGO', $asignado->getContent());
    }

    public function test_un_duplicado_consolidado_verifica_por_el_codigo_de_su_par(): void
    {
        $this->congelador()->servir($this->cert(62));

        $this->get('/verificar/50000')->assertOk()->assertSee('Certificado histórico válido');
    }

    public function test_un_codigo_asignado_sigue_verificando_si_el_certificado_se_revoca_o_se_reemplaza(): void
    {
        $this->congelador()->servir($this->cert(60));

        DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->update(['estado' => 'revocado']);
        $this->get('/verificar/50000')->assertOk()->assertSee('revocado');
        $this->assertSame('50000', DB::table('cf_codigos_historicos')->value('codigo'));

        $this->app['auth']->forgetGuards();
        DB::table('cf_certificados_legado')->where('id', $this->idCert(60))->update(['estado' => 'reemplazado']);
        $this->get('/verificar/50000')->assertOk();
        $this->assertSame(1, $this->registros());
    }

    public function test_un_codigo_no_asignado_del_rango_es_404_uniforme(): void
    {
        $this->congelador()->servir($this->cert(60));
        $a = $this->get('/verificar/50999');
        $this->app['auth']->forgetGuards();
        $b = $this->get('/verificar/12345');

        $this->assertSame(404, $a->getStatusCode());
        $this->assertSame($a->getStatusCode(), $b->getStatusCode());
        $a->assertDontSee('50999');
    }

    public function test_un_codigo_legado_y_uno_asignado_en_pares_distintos_con_el_mismo_numero_es_colision_no_revelada(): void
    {
        $this->congelador()->servir($this->cert(60));
        // Un código legado que llega después con el mismo número que uno asignado (el preflight debe impedirlo): la verificación no elige.
        DB::table('cf_certificados_legado')->where('id', $this->idCert(63))->update(['codigo_legado' => '50000']);

        $this->get('/verificar/50000')->assertNotFound();
    }
}
