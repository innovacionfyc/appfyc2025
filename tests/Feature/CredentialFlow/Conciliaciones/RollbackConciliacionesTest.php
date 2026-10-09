<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\RollbackConciliaciones;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Support\Facades\DB;

/** Rollback técnico de la Fase 10A y los dos comandos (detectar / rollback). */
class RollbackConciliacionesTest extends ConciliacionesTestCase
{
    private function revertir(): array
    {
        return app(RollbackConciliaciones::class)->revertir();
    }

    private function conteos(): array
    {
        return [DB::table('cf_conciliaciones')->count(), DB::table('cf_conciliaciones_certificados')->count(), DB::table('cf_conciliaciones_eventos')->count()];
    }

    public function test_revierte_todo_lo_detectado_sin_tocar_el_historico(): void
    {
        $antes = $this->firmaHistorico();
        $this->detectar();
        [$casos, $pivote, $eventos] = $this->conteos();
        $this->assertGreaterThan(0, $casos);

        $r = $this->revertir();

        $this->assertSame(['eventos' => $eventos, 'certificados' => $pivote, 'casos' => $casos], $r);
        $this->assertSame([0, 0, 0], $this->conteos());
        $this->assertSame($antes, $this->firmaHistorico());
    }

    public function test_sin_casos_no_hace_nada(): void
    {
        $this->assertSame(['eventos' => 0, 'certificados' => 0, 'casos' => 0], $this->revertir());
    }

    public function test_despues_del_rollback_se_puede_volver_a_detectar_lo_mismo(): void
    {
        $primera = $this->detectar();
        $firma = $this->casos()->pluck('clave_idempotencia')->sort()->values()->all();
        $this->revertir();

        $segunda = $this->detectar();

        $this->assertSame($primera['total']['nuevos'], $segunda['total']['nuevos']);
        $this->assertSame($firma, $this->casos()->pluck('clave_idempotencia')->sort()->values()->all());
    }

    public function test_se_niega_si_un_caso_tiene_una_decision(): void
    {
        $this->detectar();
        $antes = $this->firmaConciliaciones();
        DB::table('cf_conciliaciones')->where('id', $this->caso(Conciliacion::TIPO_REVISION_DOCUMENTO)->id)->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => 'documento_confirmado', 'resuelto_por' => 1, 'resuelto_at' => now()]);
        $antes = $this->firmaConciliaciones();

        try {
            $this->revertir();
            $this->fail('Debió negarse.');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CASOS_CON_DECISIONES, $e->codigo);
        }

        $this->assertSame($antes, $this->firmaConciliaciones());
    }

    public function test_se_niega_si_solo_cambio_el_estado(): void
    {
        $this->detectar();
        DB::table('cf_conciliaciones')->where('id', $this->caso(Conciliacion::TIPO_CONFLICTO_VARIANTES)->id)->update(['estado' => Conciliacion::DESCARTADO]);

        $this->expectException(RollbackNoPermitido::class);
        $this->revertir();
    }

    public function test_se_niega_si_hay_un_evento_posterior_a_la_deteccion(): void
    {
        $this->detectar();
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $this->caso(Conciliacion::TIPO_CONFLICTO_VARIANTES)->id, 'accion' => 'nota', 'actor_id' => null, 'created_at' => now()]);
        $conteos = $this->conteos();

        try {
            $this->revertir();
            $this->fail('Debió negarse.');
        } catch (RollbackNoPermitido) {
        }

        $this->assertSame($conteos, $this->conteos());
    }

    public function test_se_niega_si_el_evento_tiene_actor_humano(): void
    {
        $this->detectar();
        DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso(Conciliacion::TIPO_IDENTIDAD_AMBIGUA)->id)->update(['actor_id' => $this->admin()->id]);

        $this->expectException(RollbackNoPermitido::class);
        $this->revertir();
    }

    public function test_un_rollback_negado_no_borra_nada_de_nada(): void
    {
        $this->detectar();
        DB::table('cf_conciliaciones')->where('id', $this->caso(Conciliacion::TIPO_PLANTILLA_CANDIDATA)->id)->update(['estado' => Conciliacion::REQUIERE_SOPORTE]);
        $conteos = $this->conteos();
        $historico = $this->firmaHistorico();

        try {
            $this->revertir();
        } catch (RollbackNoPermitido) {
        }

        $this->assertSame($conteos, $this->conteos());
        $this->assertSame($historico, $this->firmaHistorico());
    }

    // ── Comandos ────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_comando_detectar_informa_y_es_idempotente(): void
    {
        $this->artisan('credential-flow:conciliaciones:detectar')->expectsOutputToContain('Detección completada.')->assertExitCode(0);
        $casos = DB::table('cf_conciliaciones')->count();
        $this->assertGreaterThan(0, $casos);

        $this->artisan('credential-flow:conciliaciones:detectar')->expectsOutputToContain('0 nuevos')->assertExitCode(0);
        $this->assertSame($casos, DB::table('cf_conciliaciones')->count());
    }

    public function test_el_comando_detectar_con_simular_no_escribe(): void
    {
        $this->artisan('credential-flow:conciliaciones:detectar', ['--simular' => true])->expectsOutputToContain('SIMULACIÓN')->assertExitCode(0);

        $this->assertSame([0, 0, 0], $this->conteos());
    }

    public function test_el_comando_de_rollback_exige_confirmacion(): void
    {
        $this->detectar();
        $conteos = $this->conteos();

        $this->artisan('credential-flow:conciliaciones:rollback')->assertExitCode(1);
        $this->assertSame($conteos, $this->conteos());

        $this->artisan('credential-flow:conciliaciones:rollback', ['--confirmar' => true])->expectsOutputToContain('revertidas')->assertExitCode(0);
        $this->assertSame([0, 0, 0], $this->conteos());
    }

    public function test_el_comando_de_rollback_se_niega_con_decisiones_y_no_toca_nada(): void
    {
        $this->detectar();
        DB::table('cf_conciliaciones')->where('id', $this->caso(Conciliacion::TIPO_REVISION_DOCUMENTO)->id)->update(['estado' => Conciliacion::RESUELTO]);
        $conteos = $this->conteos();

        $this->artisan('credential-flow:conciliaciones:rollback', ['--confirmar' => true])->expectsOutputToContain('CASOS_CON_DECISIONES')->assertExitCode(1);

        $this->assertSame($conteos, $this->conteos());
    }
}
