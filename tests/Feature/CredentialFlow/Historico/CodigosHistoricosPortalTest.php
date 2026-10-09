<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Legado\CodigoHistoricoException;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Portal y descarga con códigos históricos perezosos (Fase 10B-1.5): un certificado sin código ya NO aparece «en revisión» por eso, el panel
 * no asigna nada, la primera descarga asigna → genera/congela → registra la descarga y la segunda reutiliza todo.
 */
class CodigosHistoricosPortalTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        // El certificado de Ana en el evento 1 pierde su código legado (como un certificado nunca descargado en el sistema viejo).
        DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->update(['codigo_legado' => null]);
    }

    private function tarjetas(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }

    private function panel(): TestResponse
    {
        $this->entrar('1000001', self::CORREO)->assertRedirect(route('portal.panel'));

        return $this->get(route('portal.panel'))->assertOk();
    }

    private function registros(): int
    {
        return DB::table('cf_codigos_historicos')->count();
    }

    public function test_un_certificado_sin_codigo_aparece_disponible_y_el_panel_no_asigna_nada(): void
    {
        $sentencias = $this->sentencias(fn () => $this->panel());

        $this->assertContains('disponible', $this->tarjetas($this->get(route('portal.panel'))));
        $this->assertSame(0, $this->registros());
        $this->assertSame(50000, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert into|update)\s+[`"]?cf_(codigos_historicos|codigo_historico_contador)/i', $sql);
        }
    }

    public function test_el_panel_no_muestra_revision_solo_porque_falte_el_codigo(): void
    {
        $r = $this->panel();

        $r->assertDontSee('Código del certificado: 5237');
        // La tarjeta del certificado de Ana sigue siendo descargable (el único motivo de revisión era la falta del código).
        $tarjeta = collect((new AccesoPortal)->tarjetas('1000001'))->firstWhere('id', $this->idCert(1));
        $this->assertSame('disponible', $tarjeta['tipo']);
        $this->assertTrue($tarjeta['descargable']);
        $this->assertNull($tarjeta['codigo']);
    }

    public function test_la_primera_descarga_asigna_el_codigo_genera_y_registra_la_descarga_y_la_segunda_reutiliza(): void
    {
        $this->panel();
        $importadas = DB::table('cf_descargas')->count();

        $uno = $this->get(route('portal.descargar', $this->idCert(1)));
        $uno->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $archivo = file_get_contents($uno->baseResponse->getFile()->getPathname());
        $dos = $this->get(route('portal.descargar', $this->idCert(1)));

        $dos->assertOk();
        $this->assertSame($archivo, file_get_contents($dos->baseResponse->getFile()->getPathname()));
        $this->assertSame(1, $this->registros());
        $this->assertSame('50000', DB::table('cf_codigos_historicos')->value('codigo'));
        $this->assertSame($importadas + 2, DB::table('cf_descargas')->count());
        $this->assertNull(DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->value('codigo_legado'));
        // Ahora el panel muestra el código asignado.
        $this->get(route('portal.panel'))->assertSee('Código del certificado: 50000');
    }

    public function test_el_codigo_asignado_en_la_descarga_se_verifica_publicamente(): void
    {
        $this->panel();
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        $this->app['auth']->forgetGuards();

        $this->get('/verificar/50000')->assertOk()->assertSee('Certificado histórico válido')->assertSee('Curso Alfa 2024');
    }

    public function test_un_fallo_del_codigo_no_rompe_la_descarga_ni_registra_una_descarga(): void
    {
        $this->panel();
        DB::table('cf_codigo_historico_contador')->update(['siguiente' => 100000]);   // rango agotado
        $descargas = DB::table('cf_descargas')->count();

        $this->get(route('portal.descargar', $this->idCert(1)))->assertRedirect(route('portal.panel'))->assertSessionHas('error');

        $this->assertSame($descargas, DB::table('cf_descargas')->count());
        $this->assertSame(0, $this->registros());
        $this->assertNull(DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->value('pdf_archivo'));
    }

    public function test_los_demas_bloqueos_siguen_mostrando_revision_o_plantilla(): void
    {
        $tipos = collect((new AccesoPortal)->tarjetas('1000001'))->pluck('tipo', 'id');

        $this->assertSame('revision', $tipos[$this->idCert(17)]);              // revision_documento
        $this->assertSame('plantilla', $tipos[$this->idCert(16)]);             // pendiente_plantilla
        $this->assertSame('revocado', $tipos[$this->idCert(18)]);
        $this->assertSame('revision', $tipos[$this->idCert(19)]);              // «reemplazado» sin emisión asociada: estado inconsistente → revisión (10B-2B-2C)
        $this->assertSame(0, $this->registros());
    }

    public function test_el_codigo_no_se_revela_ni_se_guarda_junto_a_datos_personales_en_la_respuesta_publica(): void
    {
        $this->panel();
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        $this->app['auth']->forgetGuards();

        $r = $this->get('/verificar/50000');
        foreach ([self::NOMBRE, self::DOCUMENTO, self::CORREO] as $privado) {
            $r->assertDontSee($privado);
        }
        $this->assertStringNotContainsString(CodigoHistoricoException::class, $r->getContent());
    }
}
