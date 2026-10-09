<?php

namespace Tests\Feature\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/** Administración (Fase 10B-1.5): el detalle del certificado dice de dónde viene el código y la búsqueda por código encuentra los dos orígenes. */
class CodigosHistoricosAdminTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $p = fn (int $id, int $evento, string $doc, string $nombre, ?int $verif) => [
            'id' => $id, 'tipo_documento' => 'CC', 'documento' => $doc, 'nombre' => $nombre, 'correo' => "d{$doc}@example.test", 'id_evento' => $evento, 'num_verificacion' => $verif,
        ];
        $d = $this->datos();
        $d['participante'][] = $p(60, 1, '8000001', 'SIN CODIGO UNO', null);
        $d['participante'][] = $p(61, 1, '8000002', 'DUPLICADA DOS', null);
        $d['participante'][] = $p(62, 1, '8000002', 'DUPLICADA DOS', null);
        $d['participante'][] = $p(63, 1, '8000003', 'CON CODIGO TRES', 6001);
        $this->migrarSintetico($d);
    }

    private function codigo(int $old): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.certificados.show', $this->idCert($old)))->assertOk()->viewData('page')['props']['certificado']['codigo'];
    }

    public function test_el_detalle_distingue_legado_asignado_y_sin_asignar(): void
    {
        $this->assertSame(['origen' => 'legado', 'codigo' => '6001', 'texto' => 'Legado: 6001'], $this->codigo(63));
        $this->assertSame('sin_asignar', $this->codigo(60)['origen']);
        $this->assertStringContainsString('Aún no asignado', $this->codigo(60)['texto']);

        (new CodigoHistorico)->resolverOAsignar(CertificadoLegado::findOrFail($this->idCert(60)));
        $this->assertSame(['origen' => 'credential_flow', 'codigo' => '50000', 'texto' => 'Asignado por Credential Flow: 50000'], $this->codigo(60));
    }

    public function test_los_dos_certificados_de_un_par_muestran_el_mismo_codigo_asignado(): void
    {
        (new CodigoHistorico)->resolverOAsignar(CertificadoLegado::findOrFail($this->idCert(61)));

        $this->assertSame('50000', $this->codigo(61)['codigo']);
        $this->assertSame('50000', $this->codigo(62)['codigo']);
    }

    public function test_el_detalle_del_certificado_no_asigna_nada(): void
    {
        $this->codigo(60);
        $this->codigo(61);

        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
    }

    public function test_un_conflicto_de_codigos_se_muestra_sin_romper_la_pantalla(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(62))->update(['codigo_legado' => '6100']);
        DB::table('cf_certificados_legado')->where('id', $this->idCert(61))->update(['codigo_legado' => '6101']);

        $this->assertSame('conflicto', $this->codigo(61)['origen']);
    }

    public function test_la_busqueda_por_codigo_encuentra_el_legado_y_el_asignado_con_todas_las_filas_del_par(): void
    {
        (new CodigoHistorico)->resolverOAsignar(CertificadoLegado::findOrFail($this->idCert(61)));
        $buscar = fn (string $q) => collect($this->actingAs($this->admin())->get(route('credential-flow.historico.buscar', ['campo' => 'codigo', 'q' => $q]))->assertOk()->viewData('page')['props']['resultados']['data'] ?? [])->pluck('id')->map(fn ($i) => (int) $i)->sort()->values()->all();

        $this->assertSame(collect([61, 62])->map(fn ($o) => $this->idCert($o))->sort()->values()->all(), $buscar('50000'));
        $this->assertSame([$this->idCert(63)], $buscar('6001'));
        $this->assertSame([], $buscar('50555'));
    }
}
