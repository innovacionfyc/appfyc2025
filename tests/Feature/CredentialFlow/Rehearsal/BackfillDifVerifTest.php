<?php

namespace Tests\Feature\CredentialFlow\Rehearsal;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\BackfillDifVerif;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use Illuminate\Support\Facades\DB;
use RuntimeException;
use Tests\Feature\CredentialFlow\Conciliaciones\ConsolidacionTestCase;

/**
 * Fase 11A: el backfill explícito de los DIF_VERIF (B2). Fixtures de ConsolidacionTestCase: pares A(70/71), B(72/73), E(78/79) y F(80/81) difieren solo en el código;
 * C(74/75) difiere en el correo y D(76/77) en el nombre y el código.
 */
class BackfillDifVerifTest extends ConsolidacionTestCase
{
    private function bf(): BackfillDifVerif
    {
        return app(BackfillDifVerif::class);
    }

    private function gruposDe(array $olds): array
    {
        return collect($olds)->map(fn ($o) => (string) $this->cert($o)->grupo_duplicado)->sort()->values()->all();
    }

    private function casosDif(): array
    {
        return DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_CONFLICTO_VARIANTES)->orderBy('referencia_clave')->pluck('referencia_clave')->all();
    }

    public function test_crea_exactamente_los_pares_que_solo_difieren_en_el_codigo(): void
    {
        $r = $this->bf()->ejecutar();

        $this->assertSame([5, 5, 0, 10], [$r['detectados'], $r['nuevos'], $r['existentes'], $r['certificados']]);
        $this->assertSame($this->gruposDe([11, 70, 72, 78, 80]), $this->casosDif());
        foreach (DB::table('cf_conciliaciones')->get() as $c) {
            $this->assertSame([Conciliacion::TIPO_CONFLICTO_VARIANTES, 'abierto', 'grupo_duplicado', 'DIF_VERIF', 'conflicto_variantes:grupo_duplicado:'.$c->referencia_clave], [$c->tipo, $c->estado, $c->referencia_tipo, $c->motivo_origen, $c->clave_idempotencia]);
            $this->assertSame(['variante', 'variante'], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $c->id)->pluck('rol')->all());
        }
        // C (correo) y D (nombre + código) NO son DIF_VERIF.
        $this->assertNotContains((string) $this->cert(74)->grupo_duplicado, $this->casosDif());
        $this->assertNotContains((string) $this->cert(76)->grupo_duplicado, $this->casosDif());
    }

    public function test_es_idempotente_y_el_simulacro_no_escribe(): void
    {
        $sim = $this->bf()->ejecutar(true);
        $this->assertSame([5, 5, true, 0], [$sim['detectados'], $sim['nuevos'], $sim['simulado'], DB::table('cf_conciliaciones')->count()]);

        $this->bf()->ejecutar();
        $firma = md5(json_encode([DB::table('cf_conciliaciones')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones_certificados')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones_eventos')->orderBy('id')->get()->all()]));

        $r = $this->bf()->ejecutar();

        $this->assertSame([0, 5], [$r['nuevos'], $r['existentes']]);
        $this->assertSame($firma, md5(json_encode([DB::table('cf_conciliaciones')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones_certificados')->orderBy('id')->get()->all(), DB::table('cf_conciliaciones_eventos')->orderBy('id')->get()->all()])));
    }

    public function test_el_caso_es_igual_en_forma_al_que_creaba_el_detector_antiguo_y_consolidacion_lo_acepta(): void
    {
        $antiguo = $this->caso(70);
        $forma = fn (int $id) => [
            collect((array) DB::table('cf_conciliaciones')->find($id))->only(['tipo', 'estado', 'evento_id', 'referencia_tipo', 'referencia_clave', 'clave_idempotencia'])->all(),
            DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $id)->orderBy('certificado_legado_id')->get(['certificado_legado_id', 'rol'])->map(fn ($x) => (array) $x)->all(),
        ];
        $esperado = $forma($antiguo);
        // El backfill debe reconocer el caso antiguo como existente (misma clave), no duplicarlo.
        $r = $this->bf()->ejecutar();
        $this->assertSame([5, 4, 1], [$r['detectados'], $r['nuevos'], $r['existentes']]);
        $this->assertSame($esperado, $forma($antiguo));

        $a = $this->servicio()->analizar(Conciliacion::find($antiguo));
        $this->assertTrue($a['aplicable']);
        $nuevo = (int) DB::table('cf_conciliaciones')->where('referencia_clave', $this->cert(72)->grupo_duplicado)->value('id');
        $b = $this->servicio()->analizar(Conciliacion::find($nuevo));
        $this->assertTrue($b['aplicable']);
        $this->assertSame([], $b['bloqueos']);
        $this->assertSame('6102', $b['codigo']);
    }

    public function test_el_detector_general_despues_del_backfill_no_duplica_ni_cambia_los_casos(): void
    {
        $this->bf()->ejecutar();
        $antes = DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_CONFLICTO_VARIANTES)->orderBy('id')->get(['id', 'clave_idempotencia', 'estado'])->all();

        app(DetectorConciliaciones::class)->ejecutar();

        $despues = DB::table('cf_conciliaciones')->where('tipo', Conciliacion::TIPO_CONFLICTO_VARIANTES)->orderBy('id')->get(['id', 'clave_idempotencia', 'estado'])->all();
        foreach ($antes as $fila) {
            $this->assertContains($fila->clave_idempotencia, collect($despues)->pluck('clave_idempotencia')->all());
        }
        $this->assertSame(count($despues), collect($despues)->pluck('clave_idempotencia')->unique()->count(), 'sin duplicados por clave');
        $this->assertSame(collect($antes)->pluck('id')->all(), collect($despues)->whereIn('clave_idempotencia', collect($antes)->pluck('clave_idempotencia'))->pluck('id')->all());
    }

    public function test_el_detector_general_por_si_solo_no_crea_dif_verif(): void
    {
        app(DetectorConciliaciones::class)->ejecutar();

        $this->assertSame(0, DB::table('cf_conciliaciones')->where('motivo_origen', 'DIF_VERIF')->count());
    }

    public function test_revertir_borra_solo_lo_creado_por_el_backfill_y_conserva_lo_gestionado(): void
    {
        $antiguo = $this->caso(80);   // caso previo (no creado por el backfill)
        $this->bf()->ejecutar();
        $gestionado = (int) DB::table('cf_conciliaciones')->where('referencia_clave', $this->cert(72)->grupo_duplicado)->value('id');
        DB::table('cf_conciliaciones')->where('id', $gestionado)->update(['estado' => 'en_revision']);
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $gestionado, 'accion' => 'nota', 'created_at' => now()]);

        $r = $this->bf()->revertir();

        $this->assertSame([3, 1], [$r['revertidos'], $r['conservados']]);
        $this->assertNotNull(DB::table('cf_conciliaciones')->find($antiguo), 'el caso previo (otro origen) no se toca');
        $this->assertNotNull(DB::table('cf_conciliaciones')->find($gestionado), 'un caso ya gestionado no se borra');
        $this->assertSame(2, DB::table('cf_conciliaciones')->count());
        // Rehacer deja el mismo conjunto de claves.
        $this->bf()->ejecutar();
        $this->assertSame($this->gruposDe([11, 70, 72, 78, 80]), $this->casosDif());
    }

    public function test_los_certificados_no_se_modifican(): void
    {
        $antes = $this->evidencia();
        $estados = DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado', 'id')->all();

        $this->bf()->ejecutar();

        $this->assertSame($antes, $this->evidencia());
        $this->assertSame($estados, DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado', 'id')->all());
    }

    public function test_el_evento_detectado_lleva_el_origen_y_no_hay_datos_personales(): void
    {
        $this->bf()->ejecutar();

        $ev = DB::table('cf_conciliaciones_eventos')->where('accion', 'detectado')->get();
        $this->assertCount(5, $ev);
        foreach ($ev as $e) {
            $this->assertSame(BackfillDifVerif::ORIGEN, json_decode((string) $e->evidencia, true)['origen']);
            $this->assertStringNotContainsString('@', (string) $e->evidencia);
        }
    }

    public function test_se_niega_si_la_app_key_no_coincide_con_la_de_los_datos(): void
    {
        $id = DB::table('cf_migraciones_corridas')->where('tipo', 'legado_evaluaciones')->where('estado', 'completada')->orderByDesc('id')->value('id');
        $this->assertNotNull($id);
        $totales = json_decode((string) DB::table('cf_migraciones_corridas')->where('id', $id)->value('totales'), true);
        $totales['huella_clave'] = str_repeat('0', 64);
        DB::table('cf_migraciones_corridas')->where('id', $id)->update(['totales' => json_encode($totales)]);

        try {
            $this->bf()->ejecutar();
            $this->fail('Debía negarse.');
        } catch (RuntimeException $e) {
            $this->assertSame('APP_KEY_NO_COINCIDE_CON_DATOS_DERIVADOS', $e->getMessage());
        }
        $this->assertSame(0, DB::table('cf_conciliaciones')->count());
    }

    public function test_el_comando_exige_la_cifra_esperada(): void
    {
        $this->artisan('credential-flow:conciliaciones:backfill-dif-verif', ['--simular' => true, '--esperado' => 40])->assertExitCode(1);
        $this->artisan('credential-flow:conciliaciones:backfill-dif-verif', ['--esperado' => 5])->assertExitCode(0);
        $this->artisan('credential-flow:conciliaciones:backfill-dif-verif', ['--esperado' => 5])->assertExitCode(0);
        $this->assertSame(5, DB::table('cf_conciliaciones')->count());
        $this->artisan('credential-flow:conciliaciones:backfill-dif-verif', ['--revertir' => true])->assertExitCode(0);
        $this->assertSame(0, DB::table('cf_conciliaciones')->count());
    }
}
