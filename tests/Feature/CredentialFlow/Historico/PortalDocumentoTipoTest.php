<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\EmisionesModernas;
use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use App\Support\CredentialFlow\Portal\SesionPortal;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;

/**
 * Importador ↔ portal: con la columna de tipo, el importador guarda «C.C. 1.234.567» (clave `CC1234567`) y la persona escribe solo su número. El portal
 * busca por la FAMILIA del número sin unir jamás tipos explícitos distintos ni relajar las reglas de identidad (nombre conservador + correo + OTP).
 * Datos 100 % ficticios (ver PortalTestCase: Ana = 1000001 con históricos de tipo «CC»).
 */
class PortalDocumentoTipoTest extends PortalTestCase
{
    private const COMUNES = ['evento' => 'Jornada ficticia de prueba', 'fecha' => 'el 8 de octubre de 2026', 'intensidad_horaria' => '4 horas'];

    private function importar(string $filas, bool $conTipo = true): Lote
    {
        $csv = ($conTipo ? "nombre_completo,tipo_documento,documento,correo\n" : "nombre_completo,documento,correo\n").$filas;

        return app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', $csv), $this->plantillaLista(), 'Base ficticia '.uniqid(), null, self::COMUNES);
    }

    /** @return array<string,object> documento_clave => emisión */
    private function emitirTodos(Lote $lote): array
    {
        $r = [];
        foreach ($lote->participantes()->orderBy('id')->get() as $p) {
            $this->emitirPor($lote, $p)->assertCreated();
            $r[(string) $p->documento_clave] = DB::table('cf_emisiones')->where('participante_id', $p->id)->orderByDesc('id')->first();
        }

        return $r;
    }

    private function tarjetas(): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"( data-moderno)?/', $this->get(route('portal.panel'))->assertOk()->getContent(), $m, PREG_SET_ORDER);

        return array_map(fn ($x) => ($x[2] ?? '') !== '' ? $x[1].'*' : $x[1], $m);
    }

    private function modernas(array $t): int
    {
        return count(array_filter($t, fn ($x) => str_ends_with($x, '*')));
    }

    private function salir(): void
    {
        $this->post(route('portal.salir'));
    }

    /** Histórico ficticio adicional (copia estructural de uno existente) con la clave, nombre, tipo y correo indicados. */
    private function historico(string $clave, string $nombre, ?string $tipo, ?string $correo): int
    {
        $base = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(1))->first();
        unset($base['id']);
        $id = (int) DB::table('cf_certificados_legado')->insertGetId(array_merge($base, [
            'documento_clave' => $clave, 'documento' => $clave, 'nombre_completo' => $nombre, 'tipo_documento' => $tipo, 'grupo_duplicado' => null, 'reemplazado_por_emision_id' => null,
            'correo' => $correo, 'correo_normalizado' => $correo, 'correo_estado' => $correo === null ? 'sin_correo' : 'valido', 'conciliacion_estado' => 'ok', 'estado' => $base['estado'],
        ]));
        if ($correo !== null) {
            DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => true, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
        }

        return $id;
    }

    // ── 1. Normalizador ──────────────────────────────────────────────────────

    public function test_normalizador_raiz_familia_y_tipos(): void
    {
        foreach (['1234567', 'CC1234567', 'C.C. 1.234.567', 'cc 1234567', 'TI1234567', 'C.E. 1234567', 'NIT1234567', 'PPT1234567', 'PEP1234567'] as $v) {
            $this->assertSame('1234567', FamiliaDocumento::raiz($v), $v);
        }
        $this->assertSame('AB12345', FamiliaDocumento::raiz('PASAPORTE AB12345'));
        $this->assertSame('ABC123', FamiliaDocumento::raiz('ABC123'), 'sin prefijo reconocible queda igual');
        $this->assertSame('CC123', FamiliaDocumento::raiz('CC123'), 'un prefijo solo se separa si queda un número completo');
        $this->assertSame('CCAB1234', FamiliaDocumento::raiz('CCAB1234'), 'C.C. solo admite números');
        $this->assertSame('01234567', FamiliaDocumento::raiz('C.C. 01234567'), 'los ceros a la izquierda se conservan');
        $this->assertNotSame(FamiliaDocumento::raiz('01234567'), FamiliaDocumento::raiz('1234567'));
        $this->assertSame(['1234567', 'PASAPORTE1234567', 'NIT1234567', 'PEP1234567', 'PPT1234567', 'CC1234567', 'CE1234567', 'TI1234567'], FamiliaDocumento::claves('C.C. 1.234.567'));
        $this->assertSame(['AB12345', 'PASAPORTEAB12345'], FamiliaDocumento::claves('AB12345'));
        $this->assertSame(['CC', 'TI', null], [FamiliaDocumento::tipoDeClave('CC1234567'), FamiliaDocumento::tipoDeClave('TI1234567'), FamiliaDocumento::tipoDeClave('1234567')]);
        $this->assertSame(['CC', 'TI', 'CE', 'PASAPORTE', null, 'RC'], array_map([FamiliaDocumento::class, 'tipoLegado'], ['C.C.', 'ti', 'CE', 'PA', '', 'RC']));
        // Lo digitado en el portal se busca por la raíz.
        $this->assertSame('1000001', AccesoPortal::claveDocumento('C.C. 1.000.001'));
        $this->assertNull(AccesoPortal::claveDocumento('CC'));
    }

    // ── 2. Los cinco escenarios de la reproducción, ya corregidos ─────────────

    public function test_r1_con_historicos_el_numero_muestra_historicos_y_el_nuevo_juntos(): void
    {
        $e = $this->emitirTodos($this->importar(self::NOMBRE.',C.C.,1.000.001,'.self::CORREO."\n"));
        $this->assertSame(['CC1000001'], array_keys($e), 'el dato guardado no cambia: sigue con su prefijo');

        foreach (['1000001', '1.000.001', 'C.C. 1.000.001', 'CC1000001'] as $digitado) {
            $this->entrar($digitado, self::CORREO)->assertRedirect(route('portal.panel'));
            $t = $this->tarjetas();
            $this->assertSame(1, $this->modernas($t), $digitado);
            $this->assertGreaterThan(1, count($t), "{$digitado}: siguen los históricos");
            $this->get(route('portal.descargar-moderno', $e['CC1000001']->id))->assertOk();
            $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
            $this->salir();
            $this->travel(61)->minutes();   // fuera del límite de 3 códigos por hora del mismo documento + correo
        }
    }

    public function test_r2_control_sin_columna_de_tipo_sigue_igual(): void
    {
        $e = $this->emitirTodos($this->importar(self::NOMBRE.',1.000.001,'.self::CORREO."\n", false));
        $this->assertSame(['1000001'], array_map('strval', array_keys($e)));
        $this->entrar('1000001', self::CORREO);
        $t = $this->tarjetas();
        $this->assertSame(1, $this->modernas($t));
        $this->assertGreaterThan(1, count($t));
    }

    public function test_r3_sin_historicos_el_numero_recibe_codigo_y_ve_su_certificado(): void
    {
        $e = $this->emitirTodos($this->importar("PERSONA FICTICIA NUEVA,C.C.,9.100.050,ficticia@example.test\n"));
        $this->assertSame(['grupo' => null], (new AccesoPortal)->alcance('9100050', 'ficticia@example.test'));
        $this->entrar('9100050', 'ficticia@example.test')->assertRedirect(route('portal.panel'));
        $this->assertSame(['disponible*'], $this->tarjetas());
        $this->get(route('portal.descargar-moderno', $e['CC9100050']->id))->assertOk();
    }

    public function test_r4_un_correo_en_dos_grupos_de_nombre_sigue_bloqueado(): void
    {
        // Mismo número guardado SIN prefijo, OTRO nombre, el MISMO correo que Ana: identidad ambigua como siempre, sin código (también con el tipo delante).
        $this->emitirTodos($this->importar('ANA MARIA UNO PRUEBA DISTINTA,1.000.001,'.self::CORREO."\n", false));
        $this->assertNull((new AccesoPortal)->alcance('1000001', self::CORREO));
        foreach (['1000001', 'C.C. 1000001'] as $digitado) {
            $this->solicitar($digitado, self::CORREO);
        }
        Mail::assertNothingSent();
    }

    public function test_r4b_un_nuevo_con_prefijo_y_otro_nombre_no_quita_a_ana_lo_que_ya_veia(): void
    {
        // El mismo correo en un certificado nuevo CON PREFIJO a nombre de otra grafía: la familia lo vuelve ambiguo, pero Ana conserva EXACTAMENTE lo de siempre
        // (su grupo, no el documento completo) y el certificado de la otra grafía queda fuera hasta que se resuelva con un vínculo.
        $e = $this->emitirTodos($this->importar('ANA MARIA UNO PRUEBA DISTINTA,C.C.,1.000.001,'.self::CORREO."\n"));
        $this->assertSame(['grupo' => NombreConservador::grupoId(self::NOMBRE)], (new AccesoPortal)->alcance('1000001', self::CORREO));
        $this->entrar('C.C. 1.000.001', self::CORREO)->assertRedirect(route('portal.panel'));
        $t = $this->tarjetas();
        $this->assertSame(0, $this->modernas($t));
        $this->assertGreaterThan(1, count($t));
        $this->get(route('portal.descargar', $this->idCert(1)))->assertOk();
        $this->get(route('portal.descargar-moderno', $e['CC1000001']->id))->assertNotFound();
        // Un correo que no estaba en lo guardado sin prefijo no gana nada por el respaldo.
        $this->assertNull((new AccesoPortal)->alcance('1000001', 'cualquiera@example.test'));
    }

    public function test_r5_aislamiento_otro_correo_y_otro_documento_no_obtienen_nada(): void
    {
        $e = $this->emitirTodos($this->importar("PERSONA FICTICIA NUEVA,C.C.,9.100.050,ficticia@example.test\n"));
        $this->assertNull((new AccesoPortal)->alcance('9100050', 'otra@example.test'));
        $this->assertNull((new AccesoPortal)->alcance('9100050', self::CORREO));
        $this->solicitar('9100050', 'otra@example.test');
        Mail::assertNothingSent();
        // Ana (otro documento) no ve ni descarga el certificado de esta persona, ni un histórico de otro número (aunque su sesión sea de documento completo).
        $otro = $this->historico('9100050', 'PERSONA FICTICIA NUEVA', 'CC', 'ficticia@example.test');
        $this->entrar('1000001', self::CORREO);
        $this->assertSame(0, $this->modernas($this->tarjetas()));
        $this->get(route('portal.descargar-moderno', $e['CC9100050']->id))->assertNotFound();
        $this->get(route('portal.descargar', $otro))->assertNotFound();
        $this->assertFalse(AccesoPortal::perteneceA(CertificadoLegado::findOrFail($otro), '1000001', null));
    }

    // ── 3. Tipos de documento ────────────────────────────────────────────────

    public function test_cc_y_ti_con_el_mismo_numero_y_el_mismo_nombre_no_se_unen(): void
    {
        $cc = $this->emitirTodos($this->importar("PERSONA DOBLE TIPO,C.C.,9.200.001,doble@example.test\n"));
        $ti = $this->emitirTodos($this->importar("PERSONA DOBLE TIPO,T.I.,9.200.001,doble@example.test\n"));

        $this->assertSame(1, FamiliaDocumento::excluidos('9200001')['unidades']);
        $this->assertCount(0, EmisionesModernas::filas('9200001'));
        $this->assertNull((new AccesoPortal)->alcance('9200001', 'doble@example.test'));
        foreach (['9200001', 'C.C. 9200001', 'T.I. 9200001'] as $digitado) {
            $this->solicitar($digitado, 'doble@example.test');
        }
        Mail::assertNothingSent();
        // Ni siquiera una sesión ya abierta del número las alcanza.
        $this->assertNull((new AccesoPortal)->emisionModerna('9200001', null, (int) $cc['CC9200001']->id));
        $this->assertNull((new AccesoPortal)->emisionModerna('9200001', null, (int) $ti['TI9200001']->id));
    }

    public function test_el_informe_de_ambiguedades_las_lista_sin_datos_personales_y_sin_escribir(): void
    {
        $this->artisan('credential-flow:portal-ambiguedades')->expectsOutputToContain('Sin ambigüedades')->assertExitCode(0);
        $this->emitirTodos($this->importar('PERSONA DOBLE TIPO,C.C.,9.200.001,doble@example.test
'));
        $this->emitirTodos($this->importar('PERSONA DOBLE TIPO,T.I.,9.200.001,doble@example.test
'));
        $antes = [DB::table('cf_emisiones')->max('updated_at'), DB::table('cf_participantes')->max('updated_at'), DB::table('movimientos')->count()];

        $this->artisan('credential-flow:portal-ambiguedades')
            ->expectsOutputToContain('Unidades ambiguas (mismo número y nombre con tipos distintos): 1. Registros que el portal NO muestra: 2.')
            ->doesntExpectOutputToContain('PERSONA DOBLE TIPO')->doesntExpectOutputToContain('9200001')->doesntExpectOutputToContain('doble@example.test')
            ->assertExitCode(0);
        $this->assertSame($antes, [DB::table('cf_emisiones')->max('updated_at'), DB::table('cf_participantes')->max('updated_at'), DB::table('movimientos')->count()]);
    }

    public function test_ce_y_cc_con_el_mismo_numero_y_el_mismo_nombre_no_se_unen(): void
    {
        $this->emitirTodos($this->importar("PERSONA EXTRANJERA,C.E.,9.200.002,ext@example.test\n"));
        $this->emitirTodos($this->importar("PERSONA EXTRANJERA,C.C.,9.200.002,ext@example.test\n"));
        $this->assertSame(1, FamiliaDocumento::excluidos('9200002')['unidades']);
        $this->assertNull((new AccesoPortal)->alcance('9200002', 'ext@example.test'));
    }

    public function test_tipos_distintos_con_nombres_distintos_quedan_aislados_por_correo(): void
    {
        $a = $this->emitirTodos($this->importar("PERSONA ADULTA UNO,C.C.,9.200.003,adulta@example.test\n"));
        $b = $this->emitirTodos($this->importar("PERSONA MENOR DOS,T.I.,9.200.003,menor@example.test\n"));
        $c = $this->emitirTodos($this->importar("PERSONA EXTRANJERA TRES,C.E.,9.200.003,extranjera@example.test\n"));
        $this->assertSame(0, FamiliaDocumento::excluidos('9200003')['unidades']);

        foreach ([['adulta@example.test', $a['CC9200003']], ['menor@example.test', $b['TI9200003']], ['extranjera@example.test', $c['CE9200003']]] as [$correo, $propia]) {
            $this->entrar('9200003', $correo)->assertRedirect(route('portal.panel'));
            $this->assertSame(['disponible*'], $this->tarjetas(), $correo);
            $this->get(route('portal.descargar-moderno', $propia->id))->assertOk();
            foreach ([$a['CC9200003'], $b['TI9200003'], $c['CE9200003']] as $otra) {
                if ($otra->id !== $propia->id) {
                    $this->get(route('portal.descargar-moderno', $otra->id))->assertNotFound();
                }
            }
            $this->salir();
        }
        $this->assertNull((new AccesoPortal)->alcance('9200003', 'nadie@example.test'));
    }

    public function test_el_tipo_digitado_no_autoriza_nada_por_si_solo(): void
    {
        // Lo que autentica es número + correo + grupo; el tipo que se escribe no amplía ni recorta el alcance.
        $e = $this->emitirTodos($this->importar("PERSONA FICTICIA NUEVA,C.C.,9.100.050,ficticia@example.test\n"));
        $this->entrar('T.I. 9100050', 'ficticia@example.test')->assertRedirect(route('portal.panel'));
        $this->assertSame(['disponible*'], $this->tarjetas());
        $this->salir();
        $this->travel(2)->minutes();
        $this->solicitar('C.C. 9100050', 'otra@example.test');
        $this->assertSame(1, Mail::sent(CodigoAccesoMail::class)->count(), 'un correo ajeno no recibe código con ningún tipo');
        $this->get(route('portal.descargar-moderno', $e['CC9100050']->id))->assertRedirect(route('portal.inicio'));
    }

    public function test_historico_con_tipo_ti_y_nuevo_con_cc_del_mismo_nombre_no_se_unen(): void
    {
        DB::table('cf_certificados_legado')->where('documento_clave', '1000001')->update(['tipo_documento' => 'TI']);
        $e = $this->emitirTodos($this->importar(self::NOMBRE.',C.C.,1.000.001,'.self::CORREO."\n"));

        $this->assertSame(1, FamiliaDocumento::excluidos('1000001')['unidades']);
        // Lo de antes sigue igual (sus históricos); el nuevo queda fuera hasta resolver la ambigüedad.
        $this->entrar('1000001', self::CORREO)->assertRedirect(route('portal.panel'));
        $t = $this->tarjetas();
        $this->assertSame(0, $this->modernas($t));
        $this->assertGreaterThan(1, count($t));
        $this->get(route('portal.descargar-moderno', $e['CC1000001']->id))->assertNotFound();
    }

    public function test_historico_sin_tipo_se_une_con_un_unico_tipo_explicito(): void
    {
        DB::table('cf_certificados_legado')->where('documento_clave', '1000001')->update(['tipo_documento' => null]);
        $this->emitirTodos($this->importar(self::NOMBRE.',T.I.,1.000.001,'.self::CORREO."\n"));
        $this->assertSame(0, FamiliaDocumento::excluidos('1000001')['unidades']);
        $this->entrar('1000001', self::CORREO);
        $this->assertSame(1, $this->modernas($this->tarjetas()));
    }

    public function test_ceros_a_la_izquierda_no_unen_numeros_distintos(): void
    {
        $e = $this->emitirTodos($this->importar('PERSONA CON CERO,C.C.,01000001,'.self::CORREO."\n"));
        $this->assertSame(['CC01000001'], array_keys($e));
        // Ana (1000001) no ve el certificado del número con cero, aunque comparta correo y el nombre sea otro.
        $this->entrar('1000001', self::CORREO);
        $this->assertSame(0, $this->modernas($this->tarjetas()));
        $this->get(route('portal.descargar-moderno', $e['CC01000001']->id))->assertNotFound();
        $this->salir();
        $this->entrar('01000001', self::CORREO)->assertRedirect(route('portal.panel'));
        $this->assertSame(['disponible*'], $this->tarjetas());
    }

    // ── 4. Homónimos, nombres parecidos y correos compartidos ─────────────────

    public function test_homonimo_con_prefijo_y_otro_correo_no_amplia_el_alcance_de_ana(): void
    {
        $e = $this->emitirTodos($this->importar("PERSONA DISTINTA DE ANA,C.C.,1.000.001,distinta@example.test\n"));
        $this->assertSame(['grupo' => NombreConservador::grupoId(self::NOMBRE)], (new AccesoPortal)->alcance('1000001', self::CORREO));
        $this->entrar('1000001', self::CORREO);
        $this->assertSame(0, $this->modernas($this->tarjetas()));
        $this->get(route('portal.descargar-moderno', $e['CC1000001']->id))->assertNotFound();
        $this->salir();

        $this->entrar('1000001', 'distinta@example.test');
        $this->assertSame(['disponible*'], $this->tarjetas(), 'la otra persona ve solo lo suyo, nunca los históricos de Ana');
        $this->get(route('portal.descargar', $this->idCert(1)))->assertNotFound();
    }

    public function test_nombre_parecido_con_otro_correo_es_otra_identidad(): void
    {
        $e = $this->emitirTodos($this->importar('ANA UNO PRUEBAS,C.C.,1.000.001,parecida@example.test'."\n"));
        $this->entrar('1000001', self::CORREO);
        $this->assertSame(0, $this->modernas($this->tarjetas()));
        $this->salir();
        $this->entrar('1000001', 'parecida@example.test');
        $this->assertSame(['disponible*'], $this->tarjetas());
        $this->get(route('portal.descargar', $this->idCert(1)))->assertNotFound();
        $this->get(route('portal.descargar-moderno', $e['CC1000001']->id))->assertOk();
    }

    public function test_correo_compartido_por_dos_personas_con_prefijo_no_recibe_codigo(): void
    {
        $this->emitirTodos($this->importar("PERSONA UNO COMPARTE,C.C.,9.200.004,compartido@example.test\nPERSONA DOS COMPARTE,C.C.,9.200.005,compartido@example.test\n"));
        // Números distintos: cada número es su propia familia y el correo autentica en cada una por separado.
        $this->assertNotNull((new AccesoPortal)->alcance('9200004', 'compartido@example.test'));
        $this->entrar('9200004', 'compartido@example.test');
        $this->assertSame(['disponible*'], $this->tarjetas());
        $this->salir();
        // Mismo número y dos nombres con el MISMO correo: sin código.
        $this->emitirTodos($this->importar("PERSONA TRES COMPARTE,T.I.,9.200.004,compartido@example.test\n"));
        $this->assertNull((new AccesoPortal)->alcance('9200004', 'compartido@example.test'));
    }

    // ── 5. Versiones y revocaciones ──────────────────────────────────────────

    public function test_v1_revocada_y_v2_vigente_solo_entrega_la_v2(): void
    {
        $e = $this->emitirTodos($this->importar("PERSONA REEMITIDA,C.C.,9.200.006,reemitida@example.test\n"));
        $v1 = $e['CC9200006'];
        $v2 = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $v1->id), ['motivo' => 'Corrección del nombre'])->assertCreated()->json('emision.id');

        $this->entrar('9200006', 'reemitida@example.test');
        $this->assertSame(['disponible*'], $this->tarjetas());
        $this->get(route('portal.descargar-moderno', $v2))->assertOk();
        $this->get(route('portal.descargar-moderno', $v1->id))->assertNotFound();

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $v2), ['motivo' => 'Revocación de prueba ficticia'])->assertOk();
        $this->assertSame(['revocado*'], $this->tarjetas());
        $this->get(route('portal.descargar-moderno', $v2))->assertRedirect(route('portal.panel'));
        $this->assertSame(0, DB::table('cf_descargas')->where('emision_id', $v1->id)->count());
    }

    public function test_un_certificado_revocado_con_prefijo_no_habilita_codigo_por_si_solo(): void
    {
        $e = $this->emitirTodos($this->importar("PERSONA REVOCADA,C.C.,9.200.007,revocada@example.test\n"));
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e['CC9200007']->id), ['motivo' => 'Revocación de prueba ficticia'])->assertOk();
        $this->assertNull((new AccesoPortal)->alcance('9200007', 'revocada@example.test'));
    }

    // ── 6. Descargas, sesiones antiguas, OTP y vínculos ──────────────────────

    public function test_un_historico_con_prefijo_de_otra_persona_no_se_hereda_en_una_sesion_de_documento_completo(): void
    {
        $this->entrar('1000001', self::CORREO)->assertRedirect(route('portal.panel'));   // un solo grupo: documento completo
        $antes = $this->tarjetas();
        $ajeno = $this->historico('CC1000001', 'PERSONA AJENA HISTORICA', 'CC', 'ajena@example.test');

        $this->assertSame([], $this->tarjetas(), 'falla cerrado: debe identificarse de nuevo');
        $this->get(route('portal.descargar', $ajeno))->assertNotFound();
        $this->get(route('portal.descargar', $this->idCert(1)))->assertNotFound();
        $this->salir();
        $this->travel(2)->minutes();

        // Al entrar de nuevo, su correo identifica su grupo: lo suyo vuelve, lo ajeno no.
        $this->entrar('1000001', self::CORREO)->assertRedirect(route('portal.panel'));
        $this->assertSame($antes, $this->tarjetas());
        $this->get(route('portal.descargar', $ajeno))->assertNotFound();
    }

    public function test_un_historico_con_prefijo_en_unidad_ambigua_no_se_entrega(): void
    {
        DB::table('cf_certificados_legado')->where('documento_clave', '1000001')->update(['tipo_documento' => 'CC']);
        $ti = $this->historico('TI1000001', self::NOMBRE, null, self::CORREO);
        $this->assertTrue(isset(FamiliaDocumento::excluidos('1000001')['historicos'][$ti]));
        $this->entrar('1000001', self::CORREO);
        $this->get(route('portal.descargar', $ti))->assertNotFound();
        $this->assertFalse(AccesoPortal::perteneceA(CertificadoLegado::findOrFail($ti), '1000001', null));
    }

    public function test_una_sesion_anterior_guardada_con_el_tipo_delante_se_cierra(): void
    {
        $this->emitirTodos($this->importar(self::NOMBRE.',C.C.,1.000.001,'.self::CORREO."\n"));
        $ahora = now()->getTimestamp();
        $this->withSession([SesionPortal::CLAVE => ['documento' => 'CC1000001', 'grupo' => null, 'authenticated_at' => $ahora, 'last_activity' => $ahora, 'absolute_expires_at' => $ahora + 3600]])
            ->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_el_codigo_y_sus_limites_son_los_del_numero_escriba_como_escriba(): void
    {
        $this->emitirTodos($this->importar("PERSONA FICTICIA NUEVA,C.C.,9.100.050,ficticia@example.test\n"));
        $this->solicitar('9100050', 'ficticia@example.test');
        $codigo = $this->ultimoCodigo();
        // Otra grafía dentro de los 60 s: es el MISMO documento, no un desafío nuevo con límites propios.
        $this->solicitar('C.C. 9.100.050', 'ficticia@example.test');
        $this->solicitar('CC9100050', 'ficticia@example.test');
        $this->assertSame(1, Mail::sent(CodigoAccesoMail::class)->count());
        $this->assertSame(1, DB::table('cf_accesos_otp')->count());
        // El código del número abre la sesión aunque lo último digitado lleve el tipo.
        $this->validarCodigo($codigo)->assertRedirect(route('portal.panel'));
        $this->assertSame(['disponible*'], $this->tarjetas());
        $this->assertSame(ServicioOtp::hashDocumento('9100050'), ServicioOtp::hashDocumento('CC9100050'));
    }

    public function test_corregir_el_correo_invalida_el_codigo_pedido_con_el_numero(): void
    {
        $this->emitirTodos($this->importar("PERSONA FICTICIA NUEVA,C.C.,9.100.050,ficticia@example.test\n"));
        $this->solicitar('9100050', 'ficticia@example.test');
        $this->assertSame(1, ServicioOtp::invalidarPendientes('CC9100050', 'ficticia@example.test'), 'la clave del participante lleva el prefijo');
    }

    public function test_el_vinculo_de_identidad_es_del_numero(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_19_100000_cf_vinculos_identidad_moderna_table.php', '--force' => true])->assertExitCode(0);
        $this->assertSame(VinculosIdentidadModerna::hashDocumento('1000001'), VinculosIdentidadModerna::hashDocumento('CC1000001'));
        // Un vínculo creado con el número aplica al participante importado con prefijo (grafía distinta del nombre, misma persona).
        $this->emitirTodos($this->importar('ANA UNO PRUEBA MODERNA,C.C.,1.000.001,'.self::CORREO."\n"));
        $this->assertSame(['grupo' => NombreConservador::grupoId(self::NOMBRE)], (new AccesoPortal)->alcance('1000001', self::CORREO), 'sin vínculo: solo lo de siempre');
        $this->assertCount(0, (new AccesoPortal)->tarjetasModernas('1000001', NombreConservador::grupoId(self::NOMBRE)));
        app(VinculosIdentidadModerna::class)->crear('1000001', self::CORREO, self::NOMBRE, 'ANA UNO PRUEBA MODERNA', 'Misma persona: el nombre moderno incluye un apellido adicional verificado.', $this->admin()->id);
        $this->travel(1)->seconds();
        $this->entrar('C.C. 1.000.001', self::CORREO)->assertRedirect(route('portal.panel'));
        $t = $this->tarjetas();
        $this->assertSame(1, $this->modernas($t));
        $this->assertGreaterThan(1, count($t));
    }

    // ── 7. Panel de la Fase 4: búsqueda, filtros, orden y paginación ─────────

    public function test_panel_paginado_busca_filtra_y_ordena_con_el_nuevo_incluido(): void
    {
        $this->emitirTodos($this->importar(self::NOMBRE.',C.C.,1.000.001,'.self::CORREO."\n"));
        $acceso = new AccesoPortal;
        $todo = $acceso->tarjetasPaginadas('1000001', null);
        $this->assertSame(1, count(array_filter($todo['items'], fn ($t) => ! empty($t['moderno']))));
        $this->assertSame('Jornada ficticia de prueba', $todo['items'][0]['evento'], 'lo publicado primero (recientes)');
        $this->assertSame($todo['total_sin_filtros'], $todo['total']);

        $q = $acceso->tarjetasPaginadas('1000001', null, ['q' => 'jornada FICTICIA']);
        $this->assertSame(1, $q['total']);
        $mes = substr(now()->toDateString(), 0, 7);
        $this->assertSame(1, $acceso->tarjetasPaginadas('1000001', null, ['publicado' => $mes])['total']);
        $antiguos = $acceso->tarjetasPaginadas('1000001', null, ['orden' => 'antiguos']);
        $this->assertSame($todo['total'], $antiguos['total']);
        $this->assertSame([], $acceso->tarjetasPaginadas('1000001', null, ['anio' => 'x'])['items'], 'un filtro inválido nunca amplía');
        // Mismo listado por la ruta, escrito con el tipo delante.
        $this->entrar('C.C. 1.000.001', self::CORREO);
        $this->get(route('portal.panel', ['q' => 'jornada']))->assertOk()->assertSee('Jornada ficticia de prueba');
    }
}
