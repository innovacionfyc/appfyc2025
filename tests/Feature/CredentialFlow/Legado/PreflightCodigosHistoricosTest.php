<?php

namespace Tests\Feature\CredentialFlow\Legado;

use App\Support\CredentialFlow\Legado\CodigoHistorico;
use App\Support\CredentialFlow\Legado\PreflightCodigosHistoricos;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/** Preflight del cutover (Fase 10B-1.5): solo lectura; detecta cualquier código legado dentro del rango reservado y las incoherencias. */
class PreflightCodigosHistoricosTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $d = $this->datos();
        foreach ([1 => 5237, 7 => 5300, 8 => 5300] as $id => $codigo) {
            $d = $this->conCambio($d, 'participante', $id, ['num_verificacion' => $codigo]);
        }
        $this->migrarSintetico($d);
    }

    private function falla(): array
    {
        return collect((new PreflightCodigosHistoricos)->verificar()['comprobaciones'])->where('ok', false)->pluck('nombre')->all();
    }

    public function test_un_estado_limpio_pasa_todas_las_comprobaciones(): void
    {
        $r = (new PreflightCodigosHistoricos)->verificar();

        $this->assertTrue($r['ok'], json_encode($r['comprobaciones']));
        $this->assertGreaterThanOrEqual(8, count($r['comprobaciones']));
        $this->artisan('credential-flow:codigos-historicos:preflight')->expectsOutputToContain('PREFLIGHT DE CÓDIGOS HISTÓRICOS: OK')->assertExitCode(0);
    }

    public function test_un_codigo_legado_dentro_del_rango_reservado_hace_fallar_el_preflight(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['codigo_legado' => '50001']);

        $this->assertSame(['ningún código legado dentro del rango reservado'], $this->falla());
        $this->artisan('credential-flow:codigos-historicos:preflight')->expectsOutputToContain('FALLÓ')->assertExitCode(1);
    }

    public function test_un_codigo_asignado_fuera_de_rango_o_que_choca_con_uno_legado_falla(): void
    {
        $c = DB::table('cf_certificados_legado')->where('id', $this->idCert(3))->first();
        DB::table('cf_codigos_historicos')->insert(['codigo' => '5237', 'evento_id' => $c->evento_id, 'certificado_canonico_id' => $c->id, 'par_hash' => CodigoHistorico::parHash((int) $c->evento_id, $c->documento_clave), 'created_at' => now(), 'updated_at' => now()]);

        $falla = $this->falla();
        $this->assertContains('todos los códigos asignados están en el rango', $falla);
        $this->assertContains('ningún código asignado coincide con uno legado', $falla);
    }

    public function test_un_par_con_codigo_legado_y_asignado_a_la_vez_falla(): void
    {
        $c = DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->first();
        DB::table('cf_codigos_historicos')->insert(['codigo' => '50020', 'evento_id' => $c->evento_id, 'certificado_canonico_id' => $c->id, 'par_hash' => CodigoHistorico::parHash((int) $c->evento_id, $c->documento_clave), 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cf_codigo_historico_contador')->update(['siguiente' => 50021]);

        $this->assertSame(['ningún par con código legado y código asignado a la vez'], $this->falla());
    }

    public function test_un_contador_por_debajo_de_lo_asignado_o_un_rango_insuficiente_falla(): void
    {
        $c = DB::table('cf_certificados_legado')->where('id', $this->idCert(3))->first();
        DB::table('cf_codigos_historicos')->insert(['codigo' => '50020', 'evento_id' => $c->evento_id, 'certificado_canonico_id' => $c->id, 'par_hash' => CodigoHistorico::parHash((int) $c->evento_id, $c->documento_clave), 'created_at' => now(), 'updated_at' => now()]);
        $this->assertContains('el contador está por encima de lo asignado', $this->falla());

        DB::table('cf_codigo_historico_contador')->update(['siguiente' => 99999, 'inicio' => 99990]);
        DB::table('cf_codigos_historicos')->delete();
        $this->assertContains('el rango alcanza para los pares sin código', $this->falla());
    }

    public function test_sin_contador_falla_y_el_preflight_nunca_escribe(): void
    {
        $sentencias = $this->sentencias(fn () => (new PreflightCodigosHistoricos)->verificar());
        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete)\b/i', $sql);
        }

        DB::table('cf_codigo_historico_contador')->delete();
        $this->assertSame(['contador de una sola fila'], $this->falla());
    }
}
