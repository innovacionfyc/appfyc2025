<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Los documentales confirmables (una fila por documento, sin ningún registro habilitante en su alcance) ANTES no podían entrar al portal; con el
 * reemplazo emitido por el servicio real, entran, ven UNA tarjeta y descargan la emisión moderna. El alcance y el histórico no cambian.
 */
class PortalReemplazoDocumentalesTest extends ReemplazoTestCase
{
    /** old → [documento histórico, correo] */
    private const CASOS = [120 => ['8300001', 'r1@example.test'], 121 => ['83000002', 'r2@example.test'], 122 => ['83000003', 'r3@example.test'], 123 => ['83A00004', 'r4@example.test']];

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    private function entrar(string $documento, string $correo): TestResponse
    {
        $this->post(route('portal.solicitar'), ['documento' => $documento, 'correo' => $correo])->assertRedirect(route('portal.codigo'));
        $enviados = Mail::sent(CodigoAccesoMail::class);
        $this->assertFalse($enviados->isEmpty(), 'se esperaba un OTP');

        return $this->post(route('portal.validar'), ['codigo' => $enviados->last()->codigo]);
    }

    public function test_los_documentales_antes_sin_acceso_ahora_entran_ven_una_tarjeta_y_descargan_la_emision_moderna(): void
    {
        $acceso = new AccesoPortal;
        $plantilla = $this->plantillaModerna();
        foreach (self::CASOS as $old => [$doc, $correo]) {
            $this->assertNull($acceso->alcance($doc, $correo), "$old: antes del reemplazo no había ninguna fila habilitante");
        }

        foreach (self::CASOS as $old => [$doc, $correo]) {
            $cert = DB::table('cf_certificados_legado')->where('id', $this->idCert($old))->first();
            $grupo = NombreConservador::grupoDe(CertificadoLegado::findOrFail($cert->id));
            $r = $this->reemplazar($old, $plantilla->id);

            // El reemplazo no modifica el histórico ni su alcance.
            $despues = DB::table('cf_certificados_legado')->where('id', $cert->id)->first();
            $this->assertSame([$cert->documento, $cert->nombre_completo, $cert->documento_clave, $cert->conciliacion_estado], [$despues->documento, $despues->nombre_completo, $despues->documento_clave, $despues->conciliacion_estado]);
            $this->assertSame($grupo, NombreConservador::grupoDe(CertificadoLegado::findOrFail($cert->id)));
            $this->assertSame(['grupo' => null], $acceso->alcance($doc, $correo), "$old: el mismo alcance (documento completo), ahora con algo que mostrar");
            $this->assertNull(DB::table('cf_participantes')->where('id', $r['participante_id'])->value('correo'));

            $this->entrar($doc, $correo)->assertRedirect(route('portal.panel'));
            $panel = $this->get(route('portal.panel'))->assertOk();
            preg_match_all('/data-certificado="([a-z_]+)"/', $panel->getContent(), $m);
            $this->assertSame(['actualizado'], $m[1], "$old: una sola tarjeta");
            $panel->assertSee('Código del certificado: '.$r['codigo_emision'])->assertSee('Descargar certificado');
            $d = $this->get(route('portal.descargar', $this->idCert($old)))->assertOk();
            $this->assertSame(DB::table('cf_emisiones')->where('id', $r['emision_id'])->value('pdf_hash'), hash_file('sha256', $d->baseResponse->getFile()->getPathname()));
            $this->assertSame(1, DB::table('cf_descargas')->where('emision_id', $r['emision_id'])->where('via', 'portal')->where('origen', 'credential_flow')->whereNull('certificado_legado_id')->count());
            $this->post(route('portal.salir'));
        }
    }

    public function test_el_documento_corregido_distinto_del_historico_no_abre_otra_via_de_acceso(): void
    {
        $plantilla = $this->plantillaModerna();
        $r = $this->reemplazar(120, $plantilla->id);   // el histórico tiene el espacio de no separación; el moderno, el documento limpio
        $this->assertSame('8300001', DB::table('cf_participantes')->where('id', $r['participante_id'])->value('documento'));

        // La entrada sigue siendo la del histórico (clave '8300001'); un documento inventado con el mismo correo no entra.
        $this->post(route('portal.solicitar'), ['documento' => '8300009', 'correo' => 'r1@example.test']);
        $this->assertTrue(Mail::sent(CodigoAccesoMail::class)->isEmpty());
        $this->assertNull((new AccesoPortal)->alcance('8300009', 'r1@example.test'));
    }

    public function test_el_codigo_historico_conduce_a_la_emision_moderna_vigente(): void
    {
        DB::table('cf_certificados_legado')->where('id', $this->idCert(121))->update(['codigo_legado' => '9121']);
        $r = $this->reemplazar(121);

        $respuesta = $this->get('/verificar/9121')->assertOk();

        $respuesta->assertSee('Este certificado histórico fue reemplazado por una versión posterior.');
        $this->assertStringContainsString($r['codigo_emision'], $respuesta->getContent());
        $this->get('/verificar/'.$r['codigo_emision'])->assertOk()->assertSee('Credencial válida');
    }
}
