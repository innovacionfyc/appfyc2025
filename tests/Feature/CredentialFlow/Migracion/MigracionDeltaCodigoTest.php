<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Migracion\DeltaRechazado;
use App\Support\CredentialFlow\Migracion\MigradorDelta;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\RollbackDelta;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Fase 11F-C2: el delta acepta UNA transición sobre un participante ya migrado: `num_verificacion` NULL/vacío → código (el sistema
 * viejo lo asigna en la primera descarga). Todo lo demás sigue siendo FILAS_CAMBIADAS. Todo sintético.
 *
 * Base: el staging de las pruebas más 3 participantes sueltos (20–22, evento 1) sin código → 9 candidatos sin código y sin grupo
 * (2, 3, 4, 5, 6, 15, 20, 21, 22), la forma de las 9 filas del ensayo de la ventana real.
 */
class MigracionDeltaCodigoTest extends HistoricoTestCase
{
    private const SIN_CODIGO = [2, 3, 4, 5, 6, 15, 20, 21, 22];

    private const TABLAS = ['cf_descargas', 'cf_correos', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_eventos', 'cf_migraciones_map',
        'cf_conciliaciones', 'cf_conciliaciones_certificados', 'cf_codigos_historicos', 'cf_migraciones_corridas'];

    private string $dir = '';

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** @return array<int,array<string,mixed>> */
    private function imagenes(): array
    {
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deltacod_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($this->dir.'/ALFA 2024.png', $png);
        file_put_contents($this->dir.'/DELTA 2023', $png);
        file_put_contents($this->dir.'/ORFANA.png', $png.'o');
        file_put_contents($this->dir.'/GAMMA_.png', $png.'g');
        $r = (new EscanerImagenes)->escanear($this->dir);
        File::deleteDirectory($this->dir);

        return $r;
    }

    private function persona(int $id, int $evento, string $doc, string $nombre, ?int $verif = null): array
    {
        return ['id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => strtolower(str_replace(' ', '', $nombre)).'@example.test',
            'id_evento' => $evento, 'num_verificacion' => $verif];
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function datosBase(): array
    {
        $d = $this->datos();
        $d['participante'][] = $this->persona(20, 1, '4000020', 'SOLO VEINTE');
        $d['participante'][] = $this->persona(21, 1, '4000021', 'SOLO VEINTIUNO');
        $d['participante'][] = $this->persona(22, 1, '4000022', 'SOLO VEINTIDOS');

        return $d;
    }

    private function base(): void
    {
        $this->cargar('s1', $this->datosBase(), $this->imagenes());
        (new MigradorHistorico)->ejecutar();
    }

    /**
     * Snapshot s2 = el base + 1 participante nuevo + los cambios indicados (old_id → campos).
     *
     * @param  array<int,array<string,mixed>>  $cambios
     */
    private function snapshot(array $cambios): array
    {
        $d = $this->datosBase();
        $d['participante'][] = $this->persona(30, 1, '5000030', 'NUEVO TREINTA', 9999);
        foreach ($cambios as $id => $campos) {
            $d = $this->conCambio($d, 'participante', $id, $campos);
        }
        $this->cargar('s2', $d, $this->imagenes());

        return $d;
    }

    private function cert(int $old): int
    {
        return (int) DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', (string) $old)->value('destino_id');
    }

    private function codigoDe(int $old): ?string
    {
        $c = DB::table('cf_certificados_legado')->where('id', $this->cert($old))->value('codigo_legado');

        return $c === null ? null : (string) $c;
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        $r = [];
        foreach (self::TABLAS as $t) {
            $r[$t] = DB::table($t)->count();
        }

        return $r;
    }

    private function delta()
    {
        return (new MigradorDelta)->ejecutar();
    }

    private function filaCert(int $old): array
    {
        return (array) DB::table('cf_certificados_legado')->where('id', $this->cert($old))->first();
    }

    /** Aplica el escenario, carga el snapshot y exige que el delta lo rechace por `$motivo` sin escribir nada. */
    private function exigirRechazo(array $cambios, string $motivo, ?callable $preparar = null): void
    {
        $this->base();
        if ($preparar !== null) {
            $preparar();
        }
        $this->snapshot($cambios);
        $antes = $this->conteos();
        $codigoAntes = $this->codigoDe(6);

        try {
            $this->delta();
            $this->fail('Debía rechazar el delta por '.$motivo);
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::FILAS_CAMBIADAS, $e->codigo);
            $this->assertGreaterThanOrEqual(1, $e->detalle['cambios_de_codigo_rechazados'][$motivo] ?? 0, json_encode($e->detalle));
        }
        $this->assertSame($antes, $this->conteos());
        $this->assertSame($codigoAntes, $this->codigoDe(6));
    }

    // ── 1. permitido ──────────────────────────────────────────────────────────

    public function test_null_a_codigo_valido_se_acepta_y_solo_cambia_el_codigo_del_certificado(): void
    {
        $this->base();
        $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $antes = $this->filaCert(6);
        $otros = array_map(fn ($o) => $this->filaCert($o), [2, 3]);

        $r = $this->delta();

        $this->assertFalse($r->noOp);
        $this->assertSame('31006', $this->codigoDe(6));
        $despues = $this->filaCert(6);
        // Solo cambian el código y updated_at: nada más del certificado.
        $cambiados = array_keys(array_diff_assoc($despues, $antes));
        $this->assertContains('codigo_legado', $cambiados);
        $this->assertSame([], array_diff($cambiados, ['codigo_legado', 'updated_at']));
        $this->assertSame($antes['snapshot_legado'], $despues['snapshot_legado']);
        $this->assertSame($otros, array_map(fn ($o) => $this->filaCert($o), [2, 3]));
        $this->assertSame(1, $r->totales['codigos_legado_actualizados']);
        // Auditable: una fila de mapa propia, con corrida, valores y destino.
        $m = DB::table('cf_migraciones_map')->where('origen_tabla', MigradorDelta::MAPA_CODIGO)->where('origen_id', '6')->first();
        $this->assertSame($r->corridaId, (int) $m->corrida_id);
        $this->assertSame($this->cert(6), (int) $m->destino_id);
        $d = json_decode((string) $m->detalle, true);
        $this->assertNull($d['codigo_anterior']);
        $this->assertSame('31006', $d['codigo_nuevo']);
        // El participante base no se re-mapea ni se crea otro certificado.
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '6')->count());
        $this->assertSame(1 + 0, DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->count());   // solo el nuevo (30)
    }

    public function test_las_nueve_transiciones_del_ensayo_se_aceptan(): void
    {
        $this->base();
        $cambios = [];
        foreach (self::SIN_CODIGO as $i => $old) {
            $cambios[$old] = ['num_verificacion' => 31001 + $i];
        }
        $this->snapshot($cambios);

        $r = $this->delta();

        $this->assertSame(9, $r->totales['codigos_legado_actualizados']);
        foreach (self::SIN_CODIGO as $i => $old) {
            $this->assertSame((string) (31001 + $i), $this->codigoDe($old));
        }
        $this->assertSame(9, DB::table('cf_migraciones_map')->where('corrida_id', $r->corridaId)->where('origen_tabla', MigradorDelta::MAPA_CODIGO)->count());
        // No se emitió ningún código moderno ni se creó nada fuera de lo esperado.
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
    }

    // ── 2–3. otras transiciones del código ────────────────────────────────────

    public function test_codigo_a_otro_codigo_se_rechaza(): void
    {
        $this->exigirRechazo([1 => ['num_verificacion' => 31001]], 'CODIGO_PREVIO_NO_VACIO');
    }

    public function test_codigo_a_null_se_rechaza(): void
    {
        $this->exigirRechazo([1 => ['num_verificacion' => null]], 'CODIGO_PREVIO_NO_VACIO');   // el valor anterior (100) no es vacío
    }

    // ── 4–7. cambios adicionales ──────────────────────────────────────────────

    /** @return array<string,array{0:array<string,mixed>}> */
    public static function camposAdicionales(): array
    {
        return [
            'nombre' => [['nombre' => 'NOMBRE CAMBIADO']],
            'documento' => [['documento' => '9999999']],
            'correo' => [['correo' => 'otro@example.test']],
            'evento' => [['id_evento' => 2]],
        ];
    }

    #[DataProvider('camposAdicionales')]
    public function test_cambio_adicional_junto_al_codigo_se_rechaza(array $extra): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006] + $extra], 'CAMPOS_ADICIONALES');
    }

    // ── 8–10. unicidad y rango ────────────────────────────────────────────────

    public function test_codigo_duplicado_en_otro_participante_legacy_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 100]], 'CODIGO_DUPLICADO_LEGACY');   // el participante 1 ya tiene 100
    }

    public function test_colision_con_certificado_migrado_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'CODIGO_COLISIONA_CERTIFICADO', function () {
            DB::table('cf_certificados_legado')->where('id', $this->cert(3))->update(['codigo_legado' => '31006']);
        });
    }

    public function test_colision_con_codigo_moderno_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'CODIGO_COLISIONA_MODERNO', function () {
            $c = $this->cert(3);
            DB::table('cf_codigos_historicos')->insert([
                'codigo' => '31006', 'evento_id' => DB::table('cf_certificados_legado')->where('id', $c)->value('evento_id'), 'certificado_canonico_id' => $c,
                'par_hash' => str_repeat('b', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_codigo_dentro_del_rango_reservado_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 50500]], 'RANGO_RESERVADO');
    }

    // ── 11–17. el certificado base debe estar limpio ──────────────────────────

    public function test_certificado_que_ya_tiene_codigo_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'CERTIFICADO_YA_TIENE_CODIGO', function () {
            DB::table('cf_certificados_legado')->where('id', $this->cert(6))->update(['codigo_legado' => '77']);
        });
    }

    public function test_certificado_con_codigo_moderno_asignado_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'CODIGO_MODERNO_ASIGNADO', function () {
            $c = $this->cert(6);
            DB::table('cf_codigos_historicos')->insert([
                'codigo' => '60001', 'evento_id' => DB::table('cf_certificados_legado')->where('id', $c)->value('evento_id'), 'certificado_canonico_id' => $c,
                'par_hash' => str_repeat('c', 64), 'created_at' => now(), 'updated_at' => now(),
            ]);
        });
    }

    public function test_certificado_con_pdf_congelado_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'PDF_CONGELADO', function () {
            DB::table('cf_certificados_legado')->where('id', $this->cert(6))->update(['pdf_archivo' => 'x.pdf', 'pdf_hash' => str_repeat('a', 64), 'materializado_at' => now()]);
        });
    }

    public function test_certificado_visible_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'VISIBLE_EN_PORTAL', function () {
            DB::table('cf_certificados_legado')->where('id', $this->cert(6))->update(['visible_portal' => true]);
        });
    }

    public function test_certificado_con_emision_moderna_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'EMISION_MODERNA', function () {
            DB::table('cf_certificados_legado')->where('id', $this->cert(6))->update(['intentos_generacion' => 1]);
        });
    }

    public function test_certificado_modificado_despues_de_la_base_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'MODIFICADO_POSTERIORMENTE', function () {
            DB::table('cf_certificados_legado')->where('id', $this->cert(6))->update(['updated_at' => now()->addDay()->format('Y-m-d H:i:s')]);
        });
    }

    public function test_mapeo_incorrecto_se_rechaza(): void
    {
        $this->exigirRechazo([6 => ['num_verificacion' => 31006]], 'MAPEO_INCORRECTO', function () {
            DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '6')->update(['destino_id' => $this->cert(3)]);
        });
    }

    // ── 18. caso de conciliación abierto ──────────────────────────────────────

    public function test_caso_de_conciliacion_abierto_no_bloquea_y_queda_intacto(): void
    {
        $this->base();
        $cert = $this->cert(6);
        $caso = DB::table('cf_conciliaciones')->insertGetId([
            'tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'evento_id' => null, 'referencia_tipo' => 'certificado', 'referencia_clave' => (string) $cert,
            'motivo_origen' => 'GRUPO_SIN_VIA_CORREO_COMPARTIDO', 'clave_idempotencia' => 'test:caso:'.$cert, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $cert, 'rol' => 'afectado', 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00']);
        $casoAntes = (array) DB::table('cf_conciliaciones')->where('id', $caso)->first();
        $vinculoAntes = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso)->get()->map(fn ($x) => (array) $x)->all();
        $this->snapshot([6 => ['num_verificacion' => 31006]]);

        $this->delta();

        $this->assertSame('31006', $this->codigoDe(6));
        $this->assertSame($casoAntes, (array) DB::table('cf_conciliaciones')->where('id', $caso)->first());
        $this->assertSame($vinculoAntes, DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso)->get()->map(fn ($x) => (array) $x)->all());
        $this->assertSame(1, DB::table('cf_conciliaciones')->count());
    }

    // ── 23. el resto de filas CAMBIADA sigue deteniendo ───────────────────────

    public function test_otra_fila_cambiada_sigue_siendo_hard_stop_aunque_haya_codigos_validos(): void
    {
        $this->base();
        $this->snapshot([6 => ['num_verificacion' => 31006], 1 => ['nombre' => 'ANA UNO CAMBIADA']]);
        $antes = $this->conteos();

        try {
            $this->delta();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::FILAS_CAMBIADAS, $e->codigo);
            $this->assertSame(['participante' => 1], $e->detalle['cambiadas']);
        }
        $this->assertSame($antes, $this->conteos());
        $this->assertNull($this->codigoDe(6));
    }

    // ── 20–22. rollback e idempotencia ────────────────────────────────────────

    public function test_rollback_devuelve_el_codigo_exactamente_a_null(): void
    {
        $this->base();
        $this->snapshot([6 => ['num_verificacion' => 31006], 3 => ['num_verificacion' => 31003]]);
        $antes6 = $this->filaCert(6);
        $antes3 = $this->filaCert(3);
        $conteoBase = $this->conteos();
        $r = $this->delta();

        $out = (new RollbackDelta)->revertirLegado($r->corridaId);

        $this->assertSame(2, $out['codigos']);
        $this->assertSame($antes6, $this->filaCert(6));
        $this->assertSame($antes3, $this->filaCert(3));
        $conteoBase['cf_migraciones_corridas'] += 1;
        $this->assertSame($conteoBase, $this->conteos());
        $this->assertSame(MigracionCorrida::ESTADO_REVERTIDA, MigracionCorrida::findOrFail($r->corridaId)->estado);
    }

    /** @return array<string,array{0:array<string,mixed>,1:string}> */
    public static function actividadPosterior(): array
    {
        return [
            'codigo modificado' => [['codigo_legado' => '88888'], RollbackNoPermitido::CODIGO_ACTUALIZADO_MODIFICADO],
            'pdf congelado' => [['pdf_archivo' => 'x.pdf', 'pdf_hash' => 'a'], RollbackNoPermitido::PDF_CONGELADO],
            'visible' => [['visible_portal' => true], RollbackNoPermitido::CERTIFICADOS_MODIFICADOS],
            'emision' => [['intentos_generacion' => 2], RollbackNoPermitido::CERTIFICADOS_MODIFICADOS],
        ];
    }

    #[DataProvider('actividadPosterior')]
    public function test_rollback_con_actividad_posterior_se_bloquea_sin_tocar_nada(array $cambio, string $esperado): void
    {
        $this->base();
        $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $r = $this->delta();
        if (isset($cambio['pdf_hash'])) {
            $cambio['pdf_hash'] = str_repeat('a', 64);
        }
        DB::table('cf_certificados_legado')->where('id', $this->cert(6))->update($cambio);
        $antes = $this->conteos();
        $filaAntes = $this->filaCert(6);

        try {
            (new RollbackDelta)->revertirLegado($r->corridaId);
            $this->fail('Debía bloquear');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame($esperado, $e->codigo);
        }
        $this->assertSame($antes, $this->conteos());
        $this->assertSame($filaAntes, $this->filaCert(6));
        $this->assertSame(MigracionCorrida::ESTADO_COMPLETADA, MigracionCorrida::findOrFail($r->corridaId)->estado);
    }

    public function test_rollback_se_bloquea_si_el_codigo_moderno_se_asigno_despues(): void
    {
        $this->base();
        $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $r = $this->delta();
        $c = $this->cert(6);
        DB::table('cf_codigos_historicos')->insert([
            'codigo' => '60002', 'evento_id' => DB::table('cf_certificados_legado')->where('id', $c)->value('evento_id'), 'certificado_canonico_id' => $c,
            'par_hash' => str_repeat('d', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);

        try {
            (new RollbackDelta)->revertirLegado($r->corridaId);
            $this->fail('Debía bloquear');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CODIGOS_ASIGNADOS, $e->codigo);
        }
        $this->assertSame('31006', $this->codigoDe(6));
    }

    public function test_rollback_se_bloquea_si_el_caso_de_conciliacion_cambio_despues(): void
    {
        $this->base();
        $cert = $this->cert(6);
        $caso = DB::table('cf_conciliaciones')->insertGetId([
            'tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'evento_id' => null, 'referencia_tipo' => 'certificado', 'referencia_clave' => (string) $cert,
            'motivo_origen' => 'TEST', 'clave_idempotencia' => 'test:caso:'.$cert, 'created_at' => '2026-01-01 00:00:00', 'updated_at' => '2026-01-01 00:00:00',
        ]);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $cert, 'rol' => 'afectado', 'created_at' => now(), 'updated_at' => now()]);
        $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $r = $this->delta();
        DB::table('cf_conciliaciones')->where('id', $caso)->update(['estado' => 'resuelto', 'updated_at' => '2026-12-01 00:00:00']);

        try {
            (new RollbackDelta)->revertirLegado($r->corridaId);
            $this->fail('Debía bloquear');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CASOS_POSTERIORES, $e->codigo);
        }
        $this->assertSame('31006', $this->codigoDe(6));
    }

    public function test_reejecutar_el_mismo_snapshot_y_un_snapshot_identico_son_no_op(): void
    {
        $this->base();
        $datos = $this->snapshot([6 => ['num_verificacion' => 31006], 3 => ['num_verificacion' => 31003]]);
        $r = $this->delta();
        $antes = $this->conteos();

        $otra = $this->delta();   // mismo snapshot: ya incorporado
        $this->assertTrue($otra->noOp);
        $this->assertSame($antes, $this->conteos());

        $this->cargar('s3', $datos, $this->imagenes());   // otro dump con el mismo contenido: sin cambios nuevos
        $tercera = $this->delta();
        $this->assertTrue($tercera->noOp);
        $this->assertSame($antes, $this->conteos());
        $this->assertSame('31006', $this->codigoDe(6));
        $this->assertSame(1, DB::table('cf_migraciones_corridas')->where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->where('estado', MigracionCorrida::ESTADO_COMPLETADA)->count());
        $this->assertSame($r->corridaId, (int) MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->value('id'));
    }

    public function test_simular_valida_y_no_escribe_nada(): void
    {
        $this->base();
        $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $antes = $this->conteos();

        $r = (new MigradorDelta)->ejecutar(simular: true);

        $this->assertSame(1, $r->totales['codigos_legado_actualizados']);
        $this->assertSame($antes, $this->conteos());
        $this->assertNull($this->codigoDe(6));
    }

    public function test_rollback_mixto_revierte_el_codigo_base_y_elimina_lo_nuevo_sin_tocar_la_base(): void
    {
        $this->base();
        $baseAntes = DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
        $mapaBaseAntes = DB::table('cf_migraciones_map')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all();
        $conteos = $this->conteos();
        // A) certificado BASE NULL → código y B) certificado NUEVO (participante 30) con código legado, en la MISMA corrida.
        $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $r = $this->delta();
        $nuevo = DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->first();
        $this->assertSame('9999', (string) $nuevo->codigo_legado);
        $this->assertSame('31006', $this->codigoDe(6));

        $out = (new RollbackDelta)->revertirLegado($r->corridaId);

        $this->assertSame(1, $out['codigos']);
        $this->assertSame(1, $out['certificados']);
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->count());
        $this->assertSame($baseAntes, DB::table('cf_certificados_legado')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all(), 'la base #1 queda exactamente igual');
        $this->assertSame($mapaBaseAntes, DB::table('cf_migraciones_map')->orderBy('id')->get()->map(fn ($x) => (array) $x)->all());
        $conteos['cf_migraciones_corridas'] += 1;
        $this->assertSame($conteos, $this->conteos());
    }

    public function test_snapshot_posterior_con_el_codigo_ya_incorporado_no_se_rechaza_y_solo_procesa_lo_nuevo(): void
    {
        $this->base();
        $d = $this->snapshot([6 => ['num_verificacion' => 31006]]);
        $this->delta();

        // Snapshot #3: el mismo código ya persistido + una fila NUEVA; sin cambios en el participante sincronizado.
        $d['participante'][] = $this->persona(31, 1, '5000031', 'NUEVO TREINTA Y UNO', 8888);
        $this->cargar('s3', $d, $this->imagenes());

        $r = $this->delta();

        $this->assertFalse($r->noOp);
        $this->assertSame(0, $r->totales['codigos_legado_actualizados']);
        $this->assertSame('31006', $this->codigoDe(6));
        $this->assertSame(1, DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->count());
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('origen_tabla', MigradorDelta::MAPA_CODIGO)->count(), 'el código ya incorporado no se vuelve a registrar');
    }

    // ── 24. paridad con migrar todo el snapshot final de una vez ──────────────

    public function test_paridad_base_mas_delta_equivale_a_migrar_el_snapshot_final(): void
    {
        $cambios = [6 => ['num_verificacion' => 31006], 3 => ['num_verificacion' => 31003], 20 => ['num_verificacion' => 31020]];
        $huella = function () {
            $r = [];
            foreach ([2, 3, 6, 20, 21, 30] as $old) {
                $c = DB::table('cf_certificados_legado')->where('id', $this->cert($old))->first();
                $snap = json_decode((string) $c->snapshot_legado, true);
                unset($snap['migracion']['delta'], $snap['migracion']['hash_fila'], $snap['codigo_legado'], $snap['duplicado']['grupo_staging']);
                $r[$old] = [
                    'codigo' => $c->codigo_legado, 'conciliacion' => $c->conciliacion_estado, 'estado' => $c->estado, 'documento_clave' => $c->documento_clave,
                    'evento' => DB::table('cf_eventos')->where('id', $c->evento_id)->value('nombre'), 'grupo' => $c->grupo_duplicado !== null, 'snapshot' => $snap,
                ];
            }

            return $r + ['total' => DB::table('cf_certificados_legado')->count(), 'codigos' => DB::table('cf_certificados_legado')->whereNotNull('codigo_legado')->count()];
        };

        // A) base + delta con las actualizaciones de código.
        $this->base();
        $d = $this->snapshot($cambios);
        $this->delta();
        $conDelta = $huella();

        // B) el snapshot final migrado de una vez, en una aplicación limpia.
        $this->tearDown();
        $this->setUp();
        $this->cargar('s2', $d, $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        $todoJunto = $huella();

        $this->assertSame($todoJunto, $conDelta);
        $this->assertSame('31006', (string) $conDelta[6]['codigo']);
    }
}
