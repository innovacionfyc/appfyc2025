<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Portal del participante: un certificado de PRUEBA INTERNA (QA) vigente se sigue pudiendo descargar (sirve para probar OTP y descarga), pero su tarjeta lo
 * dice con claridad; el oficial no cambia y la revocación prevalece. Misma clasificación estructurada que la verificación pública. Datos 100 % sintéticos.
 */
class PortalPruebaInternaTest extends PortalTestCase
{
    private const COMUNES = ['evento' => 'Taller de prueba QA', 'fecha' => '6 de octubre de 2026', 'intensidad_horaria' => '4 horas'];

    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php', '--force' => true])->assertExitCode(0);
        DB::table('cf_contactos_qa')->insert(['nombre_completo' => 'QA UNO PRUEBA', 'documento' => 'C.C. 8.000.001', 'documento_clave' => Texto::claveDocumento('C.C. 8.000.001'),
            'correo' => 'qa.uno@example.test', 'correo_normalizado' => 'qa.uno@example.test', 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Base con un participante real y el contacto QA, y la variante de pruebas de su plantilla. */
    private function base(): Lote
    {
        $lote = app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', "nombre_completo,documento,correo\nREAL UNO,9.300.001,real1@example.test\n"), $this->plantillaLista(), 'Base QA', null, self::COMUNES);
        app(ServicioQa::class)->incorporar($lote);
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $lote->plantilla_id, '--ejecutar' => true])->assertExitCode(0);

        return $lote->fresh();
    }

    private function emitir(Lote $lote, string $clave): Emision
    {
        $p = Participante::where('lote_id', $lote->id)->where('documento_clave', $clave)->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::where('participante_id', $p->id)->where('estado', Emision::EMITIDA)->firstOrFail();
    }

    private function panel(string $documento, string $correo): string
    {
        $this->app['auth']->forgetGuards();
        $this->entrar($documento, $correo)->assertRedirect(route('portal.panel'));

        return $this->get(route('portal.panel'))->assertOk()->getContent();
    }

    private function tarjetas(string $html): array
    {
        preg_match_all('/<article class="cert" data-certificado="([a-z_]+)"([^>]*)>/', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => $x[1].(str_contains($x[2], 'data-prueba-interna') ? '+prueba' : ''), $m);
    }

    public function test_el_certificado_qa_vigente_se_marca_como_prueba_interna_y_se_sigue_descargando(): void
    {
        $lote = $this->base();
        $e = $this->emitir($lote, 'CC8000001');

        $html = $this->panel('C.C. 8.000.001', 'qa.uno@example.test');

        $this->assertSame(['disponible+prueba'], $this->tarjetas($html));
        $this->assertStringContainsString(self::ADVERTENCIA, $html);
        $this->assertStringContainsString('No acredita asistencia, participación ni formación académica.', $html);
        $this->assertStringContainsString('Descargar PDF', $html);
        $pdf = $this->get(route('portal.descargar-moderno', $e->id))->assertOk();
        $this->assertSame($e->pdf_hash, hash_file('sha256', $pdf->baseResponse->getFile()->getPathname()));
    }

    public function test_el_certificado_oficial_no_cambia(): void
    {
        $lote = $this->base();
        $this->emitir($lote, '9300001');

        $html = $this->panel('9.300.001', 'real1@example.test');

        $this->assertSame(['disponible'], $this->tarjetas($html));
        $this->assertStringNotContainsString('PRUEBA INTERNA', $html);
        $this->assertStringContainsString('Disponible para descargar.', $html);
    }

    public function test_un_qa_historico_con_plantilla_normal_tambien_se_marca(): void
    {
        // Como las bases 9-13: plantilla normal (no designada QA) con el aviso impreso como texto fijo; el participante no estaba marcado al emitir.
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $els[] = $this->elemento(['text' => self::ADVERTENCIA, 'y' => 400, 'fontSize' => 14]);
        $lote = app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', "nombre_completo,documento,correo\nQA HISTORICO,9.400.001,hist@example.test\n"), $this->plantillaLista($els), 'Base antigua', null, self::COMUNES);
        $e = $this->emitir($lote, '9400001');
        $this->assertFalse((bool) Plantilla::find($e->plantilla_id)->es_plantilla_qa);
        $this->assertFalse((bool) Participante::find($e->participante_id)->es_control_qa);

        $this->assertSame(['disponible+prueba'], $this->tarjetas($this->panel('9.400.001', 'hist@example.test')));
    }

    public function test_un_qa_revocado_se_ve_solo_como_revocado(): void
    {
        // Como los 8 contactos actuales: una prueba revocada (histórica) y otra vigente, que es la que permite entrar al portal.
        $vieja = $this->emitir($lote = $this->base(), 'CC8000001');
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $vieja), ['motivo' => 'Fin de la prueba'])->assertOk();
        $this->emitir($this->base(), 'CC8000001');

        $html = $this->panel('C.C. 8.000.001', 'qa.uno@example.test');

        $tarjetas = $this->tarjetas($html);
        sort($tarjetas);
        $this->assertSame(['disponible+prueba', 'revocado'], $tarjetas);
        preg_match('/<article class="cert" data-certificado="revocado".*?<\/article>/s', $html, $m);
        $this->assertStringNotContainsString('PRUEBA INTERNA', $m[0] ?? 'sin tarjeta revocada', 'La revocación prevalece: la tarjeta revocada no muestra la marca de prueba');
    }
}
