<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Eventos\DescargaHistorica;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\RendererLegado;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaLegado;
use App\Support\CredentialFlow\Legado\RutasLegado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use ZipArchive;

/**
 * ZIP de certificados HISTÓRICOS por evento: solo PDF ya congelados y elegibles del evento, nunca genera ni toca nada histórico, y jamás entrega un ZIP
 * incompleto como si estuviera completo. Datos 100 % sintéticos (evento 1 «Curso Alfa 2024»: certificados 1, 2 y 7 elegibles; 8 duplicado de 7).
 */
class EventosHistoricosZipTest extends HistoricoTestCase
{
    private int $otroEventoId = 0;

    protected function setUp(): void
    {
        parent::setUp();
        $d = $this->datos();
        foreach ([1 => 5237, 7 => 5300, 8 => 5300] as $id => $codigo) {
            $d = $this->conCambio($d, 'participante', $id, ['num_verificacion' => $codigo]);
        }
        $this->migrarSintetico($this->conCambio($d, 'participante', 2, ['tipo_documento' => null, 'num_verificacion' => 5901]));

        $dir = $this->dirImagenes;
        $this->app->bind(ResolutorPlantillaLegado::class, fn () => new ResolutorPlantillaDirectorio($dir));
    }

    private function evento(): EventoCertificacion
    {
        return EventoCertificacion::findOrFail(CertificadoLegado::findOrFail($this->idCert(1))->evento_id);
    }

    private function cert(int $old): CertificadoLegado
    {
        return CertificadoLegado::findOrFail($this->idCert($old));
    }

    private function congelar(int ...$olds): void
    {
        $c = new CongeladorLegado(new RendererLegado, new ResolutorPlantillaDirectorio($this->dirImagenes));
        foreach ($olds as $old) {
            $c->servir($this->cert($old));
        }
    }

    private function zip()
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.zip', $this->evento()));
    }

    /** @return array<string,string> nombre de entrada → bytes */
    private function entradas($respuesta): array
    {
        $z = new ZipArchive;
        $this->assertTrue($z->open($respuesta->baseResponse->getFile()->getPathname()));
        $n = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $n[$z->getNameIndex($i)] = $z->getFromIndex($i);
        }
        $z->close();

        return $n;
    }

    /** @return array<string,string> huellas de todo lo histórico que no debe cambiar */
    private function huellas(): array
    {
        $archivos = collect(Storage::disk('local')->allFiles('credential-flow/legado'))->mapWithKeys(fn ($f) => [$f => hash('sha256', Storage::disk('local')->get($f))])->all();
        ksort($archivos);

        return [
            'certificados' => md5(json_encode(DB::table('cf_certificados_legado')->orderBy('id')->get())),
            'descargas' => md5(json_encode(DB::table('cf_descargas')->orderBy('id')->get())),
            'correos' => md5(json_encode(DB::table('cf_correos')->orderBy('id')->get())),
            'eventos' => md5(json_encode(DB::table('cf_eventos')->orderBy('id')->get())),
            'archivos' => md5(json_encode($archivos)),
        ];
    }

    // ── Contenido ─────────────────────────────────────────────────────────────

    public function test_el_zip_reune_los_pdf_congelados_del_evento_y_es_valido(): void
    {
        $this->congelar(1, 2, 7);

        $r = $this->zip();

        $r->assertOk()->assertHeader('Content-Type', 'application/zip')->assertHeader('X-Certificados-Omitidos', '0');
        $this->assertStringContainsString('certificados-historicos-evento-'.$this->evento()->id.'.zip', $r->headers->get('Content-Disposition'));
        $entradas = $this->entradas($r);
        $this->assertCount(3, $entradas, 'Certificados 1, 2 y 7 (la copia 8 resuelve al canónico y no suma)');
        foreach (array_keys($entradas) as $nombre) {
            $this->assertMatchesRegularExpression('/^\d{3}-[A-Z0-9-]{1,60}\.pdf$/', $nombre);
        }
    }

    public function test_cada_entrada_es_exactamente_el_pdf_congelado_y_no_hay_leeme_si_esta_completo(): void
    {
        $this->congelar(1, 2, 7);
        $esperados = collect([1, 2, 7])->map(fn ($o) => $this->cert($o)->pdf_hash)->sort()->values()->all();

        $entradas = $this->entradas($this->zip());

        $this->assertSame($esperados, collect($entradas)->map(fn ($b) => hash('sha256', $b))->sort()->values()->all());
        foreach ($entradas as $nombre => $bytes) {
            $this->assertStringEndsWith('.pdf', $nombre);
            $this->assertStringStartsWith('%PDF-', $bytes);
            $this->assertStringNotContainsString('/', $nombre);
        }
        $this->assertArrayNotHasKey('LEEME.txt', $entradas);
    }

    public function test_no_incluye_certificados_de_otros_eventos(): void
    {
        $this->congelar(1, 2, 7);
        $otro = EventoCertificacion::create(['nombre' => 'Curso Otro 2024', 'nombre_normalizado' => 'curso otro 2024', 'anio' => 2024, 'estado' => 'activo', 'origen' => 'legado']);
        $ajeno = $this->cert(2);
        DB::table('cf_certificados_legado')->where('id', $ajeno->id)->update(['evento_id' => $otro->id]);

        $entradas = $this->entradas($this->zip());

        $this->assertCount(2, $entradas);
        $this->assertNotContains($ajeno->pdf_hash, collect($entradas)->map(fn ($b) => hash('sha256', $b))->all());
        $propio = $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.zip', $otro));
        $this->assertCount(1, $this->entradas($propio));
    }

    public function test_revocados_y_reemplazados_quedan_fuera(): void
    {
        $this->congelar(1, 2, 7);
        DB::table('cf_certificados_legado')->where('id', $this->cert(2)->id)->update(['estado' => 'revocado']);
        DB::table('cf_certificados_legado')->where('id', $this->cert(7)->id)->update(['estado' => 'reemplazado']);

        $entradas = $this->entradas($this->zip());

        $this->assertCount(1, $entradas);
        $this->assertSame($this->cert(1)->pdf_hash, hash('sha256', array_values($entradas)[0]));
        $resumen = app(DescargaHistorica::class)->resumen($this->evento());
        $this->assertSame(['PENDIENTE_CONCILIACION' => 2, 'REEMPLAZADO' => 1, 'REVOCADO' => 1], $resumen['excluidos']);
    }

    public function test_los_elegibles_sin_pdf_no_se_generan_y_el_zip_lo_declara(): void
    {
        $this->congelar(1);
        $antes = $this->huellas();
        $this->assertNull($this->cert(7)->pdf_archivo);

        $r = $this->zip();

        $r->assertOk()->assertHeader('X-Certificados-Omitidos', '2');
        $entradas = $this->entradas($r);
        $this->assertCount(2, $entradas, 'El PDF 1 y el LEEME');
        $this->assertArrayHasKey('LEEME.txt', $entradas);
        $this->assertStringContainsString('ZIP PARCIAL', $entradas['LEEME.txt']);
        $this->assertStringContainsString('2 certificado(s)', $entradas['LEEME.txt']);
        $this->assertStringNotContainsString('GINA', $entradas['LEEME.txt'], 'Sin datos personales');
        $this->assertNull($this->cert(7)->pdf_archivo, 'No se generó ningún PDF');
        $this->assertSame($antes, $this->huellas());
    }

    public function test_sin_pdf_disponibles_informa_y_no_genera(): void
    {
        $antes = $this->huellas();

        $this->zip()->assertRedirect()->assertSessionHas('error');

        $this->assertSame($antes, $this->huellas());
    }

    // ── Integridad ────────────────────────────────────────────────────────────

    public function test_un_archivo_faltante_cancela_el_zip_sin_datos_personales(): void
    {
        $this->congelar(1, 2, 7);
        $falta = $this->cert(7);
        Storage::disk('local')->delete(RutasLegado::certificado($falta->id));

        $r = $this->zip();

        $r->assertRedirect()->assertSessionHas('error');
        $mensaje = session('error');
        $this->assertStringContainsString('ausente o alterado', $mensaje);
        $this->assertStringContainsString((string) $falta->id, $mensaje);
        $this->assertStringNotContainsString('GINA', $mensaje);
        $this->assertStringNotContainsString('1000001', $mensaje);
    }

    public function test_un_archivo_alterado_cancela_el_zip(): void
    {
        $this->congelar(1, 2);
        $c = $this->cert(1);
        Storage::disk('local')->put(RutasLegado::certificado($c->id), '%PDF-1.4 alterado %%EOF');

        $this->zip()->assertRedirect()->assertSessionHas('error');
        $this->assertStringContainsString('ausente o alterado', session('error'));
    }

    // ── Solo lectura y autorización ───────────────────────────────────────────

    public function test_descargar_no_modifica_datos_ni_pdf_historicos(): void
    {
        $this->congelar(1, 7);
        $antes = $this->huellas();

        $this->zip()->assertOk();
        $this->zip()->assertOk();

        $this->assertSame($antes, $this->huellas());
        $this->assertNull($this->cert(2)->pdf_archivo, 'Un elegible sin PDF no se congela por pedir el ZIP');
    }

    public function test_solo_administradores_acceden_y_solo_a_eventos_historicos(): void
    {
        $evento = $this->evento();
        $this->actingAs($this->comercial())->get(route('credential-flow.historico.eventos.zip', $evento))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('credential-flow.historico.eventos.zip', $evento))->assertRedirect();

        $nativo = EventoCertificacion::create(['nombre' => 'Nativo', 'nombre_normalizado' => 'nativo', 'anio' => 2026, 'estado' => 'activo', 'origen' => 'credential_flow']);
        $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.zip', $nativo))->assertNotFound();
    }

    public function test_un_visitante_sin_sesion_es_redirigido(): void
    {
        $this->get(route('credential-flow.historico.eventos.zip', $this->evento()))->assertRedirect();
    }

    // ── Pantalla ──────────────────────────────────────────────────────────────

    public function test_la_pantalla_del_evento_informa_la_descarga_y_marca_los_pdf_disponibles(): void
    {
        $this->congelar(1);

        $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', $this->evento()))->assertOk()
            ->assertInertia(fn ($p) => $p->component('CredentialFlow/Historico/Evento')
                ->where('descarga.elegibles', 3)->where('descarga.disponibles', 1)->where('descarga.sin_pdf', 2)->where('descarga.duplicados', 1)
                ->where('certificados.data', fn ($filas) => collect($filas)->firstWhere('id', $this->cert(1)->id)['pdf_disponible'] === true
                    && collect($filas)->firstWhere('id', $this->cert(7)->id)['pdf_disponible'] === false));
    }
}
