<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Mail\CodigoAccesoMail;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\VinculosIdentidadModerna;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;

/**
 * Corrección del CORREO de un participante desde el administrador (sin reimportar): ambas fuentes coherentes, validación y conflictos de identidad,
 * retiro del acceso del correo anterior, auditoría sin datos completos y emisiones inmutables. Datos 100 % sintéticos.
 */
class CorreoParticipanteTest extends PortalTestCase
{
    private const COMUNES = ['evento' => 'Taller de prueba de correos', 'fecha' => '5 de octubre de 2026', 'intensidad_horaria' => '4 horas'];

    private function importar(string $csv, string $nombre = 'Base correos'): Lote
    {
        return app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', $csv), $this->plantillaLista(), $nombre, null, self::COMUNES);
    }

    private function fila(Lote $lote, string $clave): object
    {
        return DB::table('cf_participantes')->where('lote_id', $lote->id)->where('documento_clave', $clave)->first();
    }

    private function editar(Lote $lote, object $p, array $campos)
    {
        return $this->actingAs($this->admin())->from('/previo')->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), array_merge(['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento], $campos));
    }

    public function test_corregir_el_correo_actualiza_la_fila_y_cf_correos_sin_reimportar(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.001,errado@example.test\n");
        $p = $this->fila($lote, '9200001');

        $this->editar($lote, $p, ['correo' => 'Correcto@Example.test'])->assertRedirect('/previo')->assertSessionHas('success');

        $f = $this->fila($lote, '9200001');
        $this->assertSame(['Correcto@Example.test', 'correcto@example.test', 'valido'], [$f->correo, $f->correo_normalizado, $f->correo_estado]);
        $c = DB::table('cf_correos')->where('participante_id', $p->id)->get();
        $this->assertCount(1, $c, 'El correo anterior ya no figura');
        $this->assertSame(['correcto@example.test', 1, 'credential_flow'], [$c[0]->correo_normalizado, (int) $c[0]->es_principal, $c[0]->origen]);
    }

    public function test_el_modal_recibe_el_correo_actual_de_importados_y_manuales(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.002,uno@example.test\n");
        $this->actingAs($this->admin())->post(route('credential-flow.participantes.store', $lote->id), ['nombre_completo' => 'Persona Manual', 'documento' => 'C.C. 9.200.003', 'correo' => 'manual@example.test'])->assertSessionHasNoErrors();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote->id))->assertOk();
        $filas = collect($r->viewData('page')['props']['participantes']['data'])->keyBy('nombre_completo');
        $this->assertSame('uno@example.test', $filas['PERSONA UNO']['correo']);
        $this->assertSame('manual@example.test', $filas['PERSONA MANUAL']['correo']);
        $this->assertSame(['valido', 1], [$this->fila($lote, 'CC9200003')->correo_estado, DB::table('cf_correos')->where('correo_normalizado', 'manual@example.test')->count()]);
    }

    public function test_el_nuevo_correo_entra_al_portal_y_el_anterior_ya_no_recibe_codigo(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.004,errado@example.test\n");
        $p = $this->fila($lote, '9200004');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();
        $this->editar($lote, $p, ['correo' => 'correcto@example.test'])->assertSessionHas('success');
        Mail::fake();

        // El correo anterior ya no identifica a la persona: no se emite código (misma respuesta pública, sin revelar nada).
        $this->solicitar('9.200.004', 'errado@example.test')->assertRedirect(route('portal.codigo'));
        $this->assertNull($this->ultimoCodigo());
        $this->assertSame(0, DB::table('cf_accesos_otp')->count());

        // El correo nuevo sí: OTP, panel y descarga del MISMO certificado ya emitido.
        $this->entrar('9.200.004', 'correcto@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk()->assertSee('Descargar PDF');
    }

    public function test_un_codigo_ya_enviado_al_correo_anterior_deja_de_servir(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.005,errado@example.test\n");
        $p = $this->fila($lote, '9200005');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();

        $this->solicitar('9.200.005', 'errado@example.test')->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertNotNull($codigo);

        $this->editar($lote, $p, ['correo' => 'correcto@example.test'])->assertSessionHas('success');

        $this->assertNull(DB::table('cf_accesos_otp')->whereNull('invalidado_at')->whereNull('usado_at')->first(), 'El desafío pendiente quedó invalidado');
        $this->validarCodigo($codigo)->assertRedirect(route('portal.codigo'))->assertSessionHas('error', 'El código no es válido o ya expiró.');
        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
    }

    public function test_formato_invalido_se_rechaza_y_nada_cambia(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.006,uno@example.test\n");
        $p = $this->fila($lote, '9200006');

        $this->editar($lote, $p, ['correo' => 'no-es-un-correo'])->assertSessionHasErrors('correo');

        $this->assertSame('uno@example.test', $this->fila($lote, '9200006')->correo_normalizado);
        $this->assertSame(['uno@example.test'], DB::table('cf_correos')->where('participante_id', $p->id)->pluck('correo_normalizado')->all());
    }

    public function test_un_correo_que_ya_identifica_a_otra_persona_con_el_mismo_documento_se_rechaza(): void
    {
        $a = $this->importar("nombre_completo,documento,correo\nANA RUIZ,9.200.007,ana.vieja@example.test\n", 'Base A');
        $b = $this->importar("nombre_completo,documento,correo\nANA RUIZ X,9.200.007,otra.persona@example.test\n", 'Base B');
        $pa = $this->fila($a, '9200007');

        $this->editar($a, $pa, ['correo' => 'otra.persona@example.test'])->assertSessionHasErrors('correo');
        $this->assertSame('ana.vieja@example.test', $this->fila($a, '9200007')->correo_normalizado);

        // Con el MISMO nombre es la misma persona: se permite.
        $c = $this->importar("nombre_completo,documento,correo\nANA RUIZ,9.200.007,ana.mismo@example.test\n", 'Base C');
        $this->editar($a, $pa, ['correo' => 'ana.mismo@example.test'])->assertSessionHasNoErrors();
        $this->assertSame('ana.mismo@example.test', $this->fila($a, '9200007')->correo_normalizado);
        $this->assertNotNull($b->id.$c->id);
    }

    public function test_un_correo_repetido_en_otra_persona_de_la_base_solo_avisa(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.008,uno@example.test\nPERSONA DOS,9.200.009,dos@example.test\n");
        $dos = $this->fila($lote, '9200009');

        $this->editar($lote, $dos, ['correo' => 'uno@example.test'])->assertSessionHasNoErrors()->assertSessionHas('success', fn ($m) => str_contains($m, 'Aviso'));
        $this->assertSame('uno@example.test', $this->fila($lote, '9200009')->correo_normalizado);
    }

    public function test_varios_correos_y_vaciar_el_correo(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.010,uno@example.test\n");
        $p = $this->fila($lote, '9200010');

        $this->editar($lote, $p, ['correo' => 'uno@example.test; dos@example.test'])->assertSessionHasNoErrors();
        $f = $this->fila($lote, '9200010');
        $this->assertSame(['multiple', null, null], [$f->correo_estado, $f->correo, $f->correo_normalizado]);
        $this->assertSame(['uno@example.test', 'dos@example.test'], DB::table('cf_correos')->where('participante_id', $p->id)->orderBy('orden')->pluck('correo_normalizado')->all());

        $this->editar($lote, $p, ['correo' => ''])->assertSessionHasNoErrors();
        $f = $this->fila($lote, '9200010');
        $this->assertSame(['sin_correo', null], [$f->correo_estado, $f->correo]);
        $this->assertSame(0, DB::table('cf_correos')->where('participante_id', $p->id)->count());
    }

    public function test_sin_el_campo_correo_no_se_toca_y_repetir_el_mismo_correo_no_cambia_nada(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.011,uno@example.test\n");
        $p = $this->fila($lote, '9200011');
        $ids = DB::table('cf_correos')->where('participante_id', $p->id)->pluck('id')->all();

        $this->editar($lote, $p, ['nombre_completo' => 'Persona Uno Corregida'])->assertSessionHasNoErrors();
        $p = $this->fila($lote, '9200011');
        $this->editar($lote, $p, ['correo' => 'UNO@example.test'])->assertSessionHasNoErrors();

        $this->assertSame($ids, DB::table('cf_correos')->where('participante_id', $p->id)->pluck('id')->all(), 'Mismo correo: las filas no se recrean');
        $this->assertSame('PERSONA UNO CORREGIDA', $this->fila($lote, '9200011')->nombre_completo);
    }

    public function test_la_auditoria_no_guarda_correos_completos_y_las_emisiones_no_cambian(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.012,errado@example.test\n");
        $p = $this->fila($lote, '9200012');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();
        $antes = DB::table('cf_emisiones')->where('participante_id', $p->id)->first();
        $archivo = hash('sha256', Storage::disk('local')->get($antes->pdf_archivo));

        $this->editar($lote, $p, ['correo' => 'correcto@example.test'])->assertSessionHas('success', fn ($m) => str_contains($m, 'se retiró el acceso'));

        $despues = DB::table('cf_emisiones')->where('participante_id', $p->id)->first();
        $this->assertEquals($antes, $despues, 'La emisión (snapshot, hash, estado, código) no cambia');
        $this->assertSame($archivo, hash('sha256', Storage::disk('local')->get($despues->pdf_archivo)), 'El PDF no cambia');

        $mov = Movimiento::query()->where('descripcion', 'Se corrigió el correo de un participante de Credential Flow')->latest('id')->first();
        $this->assertNotNull($mov);
        $texto = json_encode($mov->toArray());
        $this->assertStringNotContainsString('errado@example.test', $texto);
        $this->assertStringNotContainsString('correcto@example.test', $texto);
        $this->assertStringContainsString('er***@example.test', $texto);
    }

    public function test_cambiar_el_documento_invalida_los_codigos_ya_emitidos_con_el_documento_anterior(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.013,uno@example.test\n");
        $p = $this->fila($lote, '9200013');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();
        $this->solicitar('9.200.013', 'uno@example.test')->assertRedirect(route('portal.codigo'));
        $this->assertSame(1, DB::table('cf_accesos_otp')->whereNull('invalidado_at')->count());

        $this->editar($lote, $p, ['documento' => 'C.C. 9.200.099'])->assertSessionHasNoErrors();

        $this->assertSame(0, DB::table('cf_accesos_otp')->whereNull('invalidado_at')->whereNull('usado_at')->count());
    }

    public function test_solo_administradores_editan_el_correo(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.014,uno@example.test\n");
        $p = $this->fila($lote, '9200014');

        $this->actingAs($this->comercial())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento, 'correo' => 'x@example.test'])->assertForbidden();
        $this->assertSame('uno@example.test', $this->fila($lote, '9200014')->correo_normalizado);
    }

    public function test_una_sesion_abierta_antes_del_cambio_de_correo_ya_no_ve_ni_descarga_el_certificado(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.020,errado@example.test\n");
        $p = $this->fila($lote, '9200020');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();
        $em = DB::table('cf_emisiones')->where('participante_id', $p->id)->first();

        $this->entrar('9.200.020', 'errado@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertOk()->assertSee('Descargar PDF');
        $this->get(route('portal.descargar-moderno', $em->id))->assertOk();

        $this->travel(10)->seconds();
        $this->editar($lote, $p, ['correo' => 'correcto@example.test'])->assertSessionHas('success');

        // La sesión que ya estaba abierta con el correo anterior deja de ver y de descargar ese certificado.
        $this->get(route('portal.panel'))->assertDontSee('Descargar PDF');
        $this->get(route('portal.descargar-moderno', $em->id))->assertNotFound();

        // Una sesión nueva con el correo vigente sí.
        $this->post(route('portal.salir'));
        $this->travel(10)->minutes();
        $this->entrar('9.200.020', 'correcto@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.descargar-moderno', $em->id))->assertOk();
    }

    public function test_con_varios_correos_el_portal_no_elige_ninguno_y_solo_vale_el_que_se_escribe(): void
    {
        $lote = $this->importar("nombre_completo,documento,correo\nPERSONA UNO,9.200.021,uno@example.test\n");
        $p = $this->fila($lote, '9200021');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();
        $this->editar($lote, $p, ['correo' => 'uno@example.test; dos@example.test'])->assertSessionHasNoErrors();

        Mail::fake();
        // Un correo que NO está registrado no recibe código, aunque el documento exista.
        $this->solicitar('9.200.021', 'tres@example.test')->assertRedirect(route('portal.codigo'));
        $this->assertNull($this->ultimoCodigo());

        // El segundo correo entra por sí mismo: el código se envía a la dirección escrita y a ninguna otra.
        $this->solicitar('9.200.021', 'dos@example.test')->assertRedirect(route('portal.codigo'));
        $enviado = Mail::sent(CodigoAccesoMail::class)->last();
        $this->assertTrue($enviado->hasTo('dos@example.test'));
        $this->assertFalse($enviado->hasTo('uno@example.test'));
        $this->validarCodigo($this->ultimoCodigo())->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertSee('Descargar PDF');

        // Quitar uno de los dos retira solo su acceso; el otro sigue funcionando.
        $this->post(route('portal.salir'));
        $this->travel(10)->minutes();
        $this->editar($lote, $this->fila($lote, '9200021'), ['correo' => 'dos@example.test'])->assertSessionHasNoErrors();
        Mail::fake();
        $this->solicitar('9.200.021', 'uno@example.test');
        $this->assertNull($this->ultimoCodigo(), 'El correo retirado ya no recibe código');
        $this->assertSame(0, DB::table('cf_accesos_otp')->whereNull('invalidado_at')->whereNull('usado_at')->where('created_at', '>', now()->subMinute())->count());
    }

    public function test_con_un_vinculo_de_identidad_vigente_no_se_cambia_el_correo_que_lo_autorizo(): void
    {
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_19_100000_cf_vinculos_identidad_moderna_table.php', '--force' => true])->assertExitCode(0);
        $lote = $this->importar("nombre_completo,documento,correo\nANNA UNO PRUEBA,1.000.001,".self::CORREO."\n");
        $p = $this->fila($lote, '1000001');
        $this->emitirPor($lote, Participante::find($p->id))->assertCreated();
        app(VinculosIdentidadModerna::class)->crear('1.000.001', self::CORREO, self::NOMBRE, 'ANNA UNO PRUEBA', 'Grafía histórica errónea: la persona confirmó su nombre.', $this->admin()->id);
        $historicos = md5(json_encode([DB::table('cf_certificados_legado')->orderBy('id')->get(), DB::table('cf_correos')->whereNotNull('certificado_legado_id')->orderBy('id')->get()]));

        // Cambiar el correo vinculado heredaría sin verificar el acceso a los certificados históricos de esa persona: se rechaza y nada cambia.
        $this->editar($lote, $p, ['correo' => 'desconocido@example.test'])->assertSessionHasErrors('correo');
        $this->assertSame(self::CORREO, $this->fila($lote, '1000001')->correo_normalizado);

        // Conservar el correo autorizado y agregar otro sí se permite.
        $this->editar($lote, $p, ['correo' => self::CORREO.'; segundo@example.test'])->assertSessionHasNoErrors();
        $this->assertSame(2, DB::table('cf_correos')->where('participante_id', $p->id)->count());
        $this->assertSame($historicos, md5(json_encode([DB::table('cf_certificados_legado')->orderBy('id')->get(), DB::table('cf_correos')->whereNotNull('certificado_legado_id')->orderBy('id')->get()])), 'Los históricos no cambian');
    }

    public function test_un_homonimo_con_otro_documento_no_se_ve_afectado_por_el_cambio_de_correo(): void
    {
        $a = $this->importar("nombre_completo,documento,correo\nJUAN PEREZ,9.200.030,juan.a@example.test\n", 'Base A');
        $b = $this->importar("nombre_completo,documento,correo\nJUAN PEREZ,9.200.031,juan.b@example.test\n", 'Base B');
        $pa = $this->fila($a, '9200030');
        $pb = $this->fila($b, '9200031');
        $this->emitirPor($a, Participante::find($pa->id))->assertCreated();
        $this->emitirPor($b, Participante::find($pb->id))->assertCreated();
        $antesB = DB::table('cf_correos')->where('participante_id', $pb->id)->get();

        $this->editar($a, $pa, ['correo' => 'juan.a.nuevo@example.test'])->assertSessionHasNoErrors();

        $this->assertEquals($antesB, DB::table('cf_correos')->where('participante_id', $pb->id)->get());
        $this->assertNull($this->fila($b, '9200031')->correo_actualizado_at);
        $this->entrar('9.200.031', 'juan.b@example.test')->assertRedirect(route('portal.panel'));
        $this->get(route('portal.panel'))->assertSee('Descargar PDF');
    }
}
