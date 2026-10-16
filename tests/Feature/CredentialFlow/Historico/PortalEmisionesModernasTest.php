<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Participantes\ImportacionInvalidaException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Emergencia de unificación: los certificados NUEVOS (emisiones oficiales de participantes importados con correo) entran al MISMO portal público
 * (documento + correo + OTP), en el mismo panel que los históricos, con las mismas reglas conservadoras de identidad. Datos 100 % sintéticos.
 */
class PortalEmisionesModernasTest extends PortalTestCase
{
    private const COMUNES = ['evento' => 'Jornada de actualización planeación estratégica', 'fecha' => 'los días 8 y 14 de octubre de 2026', 'intensidad_horaria' => '4 horas'];

    private function importar(string $csv): Lote
    {
        $archivo = UploadedFile::fake()->createWithContent('base.csv', $csv);

        return app(ImportadorParticipantes::class)->importar($archivo, $this->plantillaLista(), 'Base nueva', null, self::COMUNES);
    }

    /** Importa y EMITE oficialmente a cada participante del lote; devuelve [documento_clave => emision]. */
    private function emitirTodos(Lote $lote): array
    {
        $r = [];
        foreach ($lote->participantes()->orderBy('id')->get() as $p) {
            $this->emitirPor($lote, $p)->assertCreated();
            $r[$p->documento_clave] = DB::table('cf_emisiones')->where('participante_id', $p->id)->first();
        }

        return $r;
    }

    private function tarjetas(string $html): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"( data-moderno)?/', $html, $m, PREG_SET_ORDER);

        return array_map(fn ($x) => ($x[2] ?? '') !== '' ? $x[1].'*' : $x[1], $m);
    }

    // ── A. Importación con correo ─────────────────────────────────────────────

    public function test_la_importacion_guarda_el_correo_en_el_participante_y_en_cf_correos(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,Nuevo1@Example.test\nNUEVA PERSONA DOS,9.100.002,dos@example.test; dos.b@example.test\n");

        $uno = DB::table('cf_participantes')->where('lote_id', $lote->id)->where('documento_clave', '9100001')->first();
        $this->assertSame('Nuevo1@Example.test', $uno->correo);          // la dirección original no se toca
        $this->assertSame('nuevo1@example.test', $uno->correo_normalizado);
        $this->assertSame('valido', $uno->correo_estado);
        $fila = DB::table('cf_correos')->where('participante_id', $uno->id)->get();
        $this->assertCount(1, $fila);
        $this->assertSame(['nuevo1@example.test', 'valido', 1, 'credential_flow'], [$fila[0]->correo_normalizado, $fila[0]->estado, (int) $fila[0]->es_principal, $fila[0]->origen]);

        $dos = DB::table('cf_participantes')->where('lote_id', $lote->id)->where('documento_clave', '9100002')->first();
        $this->assertSame(['multiple', null, null], [$dos->correo_estado, $dos->correo, $dos->correo_normalizado]);   // varios: la fuente de verdad es cf_correos
        $this->assertSame(['dos@example.test', 'dos.b@example.test'], DB::table('cf_correos')->where('participante_id', $dos->id)->orderBy('orden')->pluck('correo_normalizado')->all());
    }

    public function test_un_correo_invalido_bloquea_la_importacion_y_un_correo_vacio_solo_avisa(): void
    {
        try {
            $this->importar("nombre_completo,documento,correo\nPERSONA MALA,9.100.003,no-es-un-correo\n");
            $this->fail('Debía rechazar el correo inválido.');
        } catch (ImportacionInvalidaException $e) {
            $this->assertSame(0, DB::table('cf_lotes')->where('nombre', 'Base nueva')->count());   // nada a medias
        }

        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA SIN CORREO,9.100.004,\n");
        $p = DB::table('cf_participantes')->where('lote_id', $lote->id)->first();
        $this->assertSame(['sin_correo', null], [$p->correo_estado, $p->correo]);
        $this->assertSame(0, DB::table('cf_correos')->where('participante_id', $p->id)->count());
    }

    public function test_las_bases_antiguas_sin_columna_correo_se_importan_como_antes(): void
    {
        $lote = $this->importar("nombre_completo,documento\nPERSONA ANTIGUA,9.100.005\n");
        $p = DB::table('cf_participantes')->where('lote_id', $lote->id)->first();
        $this->assertSame([null, null, null], [$p->correo, $p->correo_normalizado, $p->correo_estado]);
        $this->assertSame(0, DB::table('cf_correos')->where('participante_id', $p->id)->count());
    }

    public function test_encabezados_equivalentes_de_correo_se_reconocen(): void
    {
        $lote = $this->importar("Nombre,Cédula,Correo Electrónico\nPERSONA EQUIVALENTE,9.100.006,eq@example.test\n");
        $this->assertSame('eq@example.test', DB::table('cf_participantes')->where('lote_id', $lote->id)->value('correo_normalizado'));
    }

    // ── B/C. Portal y descarga ────────────────────────────────────────────────

    public function test_una_emision_nueva_entra_por_documento_correo_y_otp_se_ve_en_el_panel_y_se_descarga(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,nuevo1@example.test\nNUEVA PERSONA DOS,9.100.002,nuevo2@example.test\n");
        $e = $this->emitirTodos($lote);

        $this->entrar('9.100.001', 'nuevo1@example.test')->assertRedirect(route('portal.panel'));
        $panel = $this->get(route('portal.panel'))->assertOk();
        $this->assertSame(['disponible*'], $this->tarjetas($panel->getContent()));
        $panel->assertSee('Jornada de actualización planeación estratégica')->assertSee('Código del certificado: '.$e['9100001']->codigo)->assertSee('Descargar PDF');

        $pdf = $this->get(route('portal.descargar-moderno', $e['9100001']->id))->assertOk();
        $this->assertSame($e['9100001']->pdf_hash, hash_file('sha256', $pdf->baseResponse->getFile()->getPathname()));
        $this->assertSame('application/pdf', $pdf->headers->get('Content-Type'));
        $this->assertSame(1, DB::table('cf_descargas')->where('emision_id', $e['9100001']->id)->where('via', 'portal')->where('origen', 'credential_flow')->whereNull('certificado_legado_id')->count());
        // La emisión no se tocó: sigue siendo la misma, con el mismo código.
        $this->assertSame($e['9100001']->codigo, DB::table('cf_emisiones')->where('id', $e['9100001']->id)->value('codigo'));
        $this->assertSame(2, DB::table('cf_emisiones')->count());
    }

    public function test_documento_con_correo_incorrecto_no_obtiene_otp_ni_acceso(): void
    {
        $this->emitirTodos($this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,nuevo1@example.test\nNUEVA PERSONA DOS,9.100.002,nuevo2@example.test\n"));

        $this->solicitar('9.100.001', 'nuevo2@example.test')->assertRedirect(route('portal.codigo'));   // misma respuesta pública
        $this->assertNull($this->ultimoCodigo(), 'El correo de otra persona no autoriza este documento.');
        $this->solicitar('9.100.001', 'cualquiera@example.test');
        $this->assertNull($this->ultimoCodigo());
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->assertNull((new AccesoPortal)->alcance('9100001', 'nuevo2@example.test'));
    }

    public function test_un_participante_no_puede_descargar_ni_ver_el_certificado_de_otro(): void
    {
        $e = $this->emitirTodos($this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,nuevo1@example.test\nNUEVA PERSONA DOS,9.100.002,nuevo2@example.test\n"));

        $this->entrar('9.100.001', 'nuevo1@example.test');
        $this->get(route('portal.descargar-moderno', $e['9100002']->id))->assertNotFound();   // ajeno: igual que «no existe»
        $this->get(route('portal.descargar-moderno', 999999))->assertNotFound();
        $this->get(route('portal.panel'))->assertDontSee($e['9100002']->codigo);
        $this->assertSame(0, DB::table('cf_descargas')->where('emision_id', $e['9100002']->id)->count());
    }

    public function test_un_id_de_emision_no_se_confunde_con_el_id_de_un_certificado_historico(): void
    {
        $e = $this->emitirTodos($this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,nuevo1@example.test\n"));
        $this->entrar('9.100.001', 'nuevo1@example.test');

        // El histórico de Ana existe con ids propios: por la ruta HISTÓRICA un id de emisión no entrega nada, y viceversa.
        $this->get(route('portal.descargar', $e['9100001']->id))->assertNotFound();
        // Y un id de certificado histórico de OTRA persona no abre nada por la ruta moderna (aunque numéricamente coincida con una emisión ajena).
        $this->get(route('portal.descargar-moderno', $e['9100001']->id + 1000))->assertNotFound();
    }

    public function test_historico_y_moderno_de_la_misma_persona_aparecen_juntos_cuando_el_alcance_es_inequivoco(): void
    {
        // Misma persona (documento, nombre y correo) que Ana, que ya tiene certificados históricos.
        $lote = $this->importar("nombre_completo,documento,correo\n".self::NOMBRE.',1.000.001,'.self::CORREO."\n");
        $e = $this->emitirTodos($lote);

        $antes = $this->tarjetas($this->entrarYPanel());
        $this->assertContains('disponible*', $antes);
        $this->assertSame(1, count(array_filter($antes, fn ($t) => str_ends_with($t, '*'))));
        $this->assertGreaterThan(1, count($antes), 'También siguen los históricos de Ana.');
        $this->get(route('portal.descargar-moderno', $e['1000001']->id))->assertOk();
        // El histórico descargable de Ana sigue entregándose como antes.
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
    }

    private function entrarYPanel(): string
    {
        $this->entrar('1.000.001', self::CORREO)->assertRedirect(route('portal.panel'));

        return $this->get(route('portal.panel'))->assertOk()->getContent();
    }

    public function test_un_homonimo_con_el_mismo_documento_no_amplia_el_alcance_de_ninguno(): void
    {
        // Otra persona (otro nombre) con el MISMO documento que Ana y su propio correo.
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA DISTINTA DE ANA,1.000.001,distinta@example.test\n");
        $e = $this->emitirTodos($lote);
        $grupoAna = NombreConservador::grupoId(self::NOMBRE);
        $grupoOtra = NombreConservador::grupoId('PERSONA DISTINTA DE ANA');

        // Ana (su correo identifica EXACTAMENTE su grupo): ve lo suyo y NO el certificado de la otra persona.
        $this->assertSame(['grupo' => $grupoAna], (new AccesoPortal)->alcance('1000001', self::CORREO));
        $this->entrar('1.000.001', self::CORREO);
        $this->assertNotContains('disponible*', $this->tarjetas($this->get(route('portal.panel'))->getContent()));
        $this->get(route('portal.descargar-moderno', $e['1000001']->id))->assertNotFound();
        $this->post(route('portal.salir'));

        // La otra persona: ve SOLO el suyo, nunca los históricos de Ana.
        $this->assertSame(['grupo' => $grupoOtra], (new AccesoPortal)->alcance('1000001', 'distinta@example.test'));
        $this->entrar('1.000.001', 'distinta@example.test');
        $this->assertSame(['disponible*'], $this->tarjetas($this->get(route('portal.panel'))->getContent()));
        $this->get(route('portal.descargar-moderno', $e['1000001']->id))->assertOk();
        $this->get(route('portal.descargar', $this->idCert(1)))->assertNotFound();
    }

    public function test_una_sesion_de_documento_completo_no_hereda_un_homonimo_emitido_despues(): void
    {
        $this->entrar('1.000.001', self::CORREO)->assertRedirect(route('portal.panel'));   // el documento tenía UN solo grupo: sesión de documento completo
        $antes = $this->tarjetas($this->get(route('portal.panel'))->getContent());

        // Mientras la sesión sigue abierta se emite el certificado de OTRA persona con el mismo documento.
        $e = $this->emitirTodos($this->importar("nombre_completo,documento,correo\nPERSONA DISTINTA DE ANA,1.000.001,distinta@example.test\n"));

        $this->assertSame($antes, $this->tarjetas($this->get(route('portal.panel'))->getContent()));   // nada nuevo aparece
        $this->get(route('portal.descargar-moderno', $e['1000001']->id))->assertNotFound();
        $this->assertSame(0, DB::table('cf_descargas')->where('emision_id', $e['1000001']->id)->count());
    }

    public function test_un_certificado_revocado_no_se_entrega_ni_habilita_acceso_por_si_solo(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,nuevo1@example.test\nNUEVA PERSONA DOS,9.100.002,nuevo2@example.test\n");
        $e = $this->emitirTodos($lote);

        $this->entrar('9.100.001', 'nuevo1@example.test');
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e['9100001']->id), ['motivo' => 'Error en el nombre del participante'])->assertOk();

        $panel = $this->get(route('portal.panel'))->assertOk();
        $this->assertSame(['revocado*'], $this->tarjetas($panel->getContent()));
        $panel->assertDontSee('data-descarga href', false);
        $this->get(route('portal.descargar-moderno', $e['9100001']->id))->assertRedirect(route('portal.panel'));
        $this->assertSame(0, DB::table('cf_descargas')->where('emision_id', $e['9100001']->id)->count());
        $this->post(route('portal.salir'));

        // Con todo revocado ya no hay ninguna fila habilitante: no se emite OTP.
        Mail::fake();
        $this->solicitar('9.100.001', 'nuevo1@example.test');
        $this->assertNull($this->ultimoCodigo());
        // La otra persona no se ve afectada.
        $this->entrar('9.100.002', 'nuevo2@example.test')->assertRedirect(route('portal.panel'));
    }

    public function test_la_verificacion_publica_por_codigo_sigue_funcionando(): void
    {
        $e = $this->emitirTodos($this->importar("nombre_completo,documento,correo\nNUEVA PERSONA UNO,9.100.001,nuevo1@example.test\n"));

        $this->get('/verificar/'.$e['9100001']->codigo)->assertOk()->assertSee('Credencial válida');
    }

    public function test_una_base_antigua_sin_correo_no_da_acceso_al_portal(): void
    {
        $this->emitirTodos($this->importar("nombre_completo,documento\nPERSONA ANTIGUA,9.100.005\n"));

        $this->solicitar('9.100.005', 'cualquiera@example.test');
        $this->assertNull($this->ultimoCodigo());
        $this->assertNull((new AccesoPortal)->alcance('9100005', 'cualquiera@example.test'));
    }
}
