<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\CorreccionBase;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * «Corregir toda la base» desde el panel (segunda entrega), con datos 100 % sintéticos:
 *  1. Se corrige el dato común de UNA base («Editar base»): guardar no reemite y no toca el evento compartido ni las otras bases del evento.
 *  2. Antes de confirmar se ven el PDF vigente (anterior) y el propuesto de una persona elegida, oficial o QA, sin escribir nada.
 *  3. Al confirmar (huella + motivo + casilla) se reemiten oficiales y QA como dos operaciones separadas, con resultado por grupo. No se envía ningún correo.
 *  4. Si falla un grupo, el otro conserva lo hecho (PARCIAL), el grupo fallido queda intacto, y al reintentar solo se procesa lo pendiente.
 *  5. Permisos: la corrección masiva es SOLO del super-admin (rutas, servicio y panel); el admin conserva «Editar base» y la reemisión individual.
 */
class CorregirTodaLaBaseTest extends EmisionesTestCase
{
    private const EVENTO_ERRADO = 'CONGRESO DE FINANZAS';

    private const EVENTO_CORRECTO = 'CONGRESO NACIONAL DE FINANZAS PUBLICAS';

    private const MOTIVO = 'Nombre del evento mal escrito en la base';

    private Plantilla $institucional;

    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => [
            'database/migrations/2026_10_06_100000_cf_eventos_table.php',
            'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
            'database/migrations/2026_10_06_100200_add_correo_columns_to_cf_participantes_table.php',
            'database/migrations/2026_10_06_100300_cf_plantillas_legado_table.php',
            'database/migrations/2026_10_06_100400_cf_certificados_legado_table.php',
            'database/migrations/2026_10_06_100500_cf_descargas_table.php',
            'database/migrations/2026_10_06_100600_cf_correos_table.php',
            'database/migrations/2026_10_06_100700_add_plantilla_legado_to_cf_eventos_table.php',
            'database/migrations/2026_10_08_100000_cf_migraciones_corridas_table.php',
            'database/migrations/2026_10_08_100100_add_corrida_id_to_tablas_historicas.php',
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
        config(['credential_flow.qa.contactos_esperados' => 8]);
        Mail::fake();

        $this->institucional = $this->plantillaLista();
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $this->institucional->id, '--ejecutar' => true])->assertExitCode(0);
        for ($i = 1; $i <= 8; $i++) {
            $doc = (string) (70000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SINTÉTICO {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        AlmacenEmisiones::$antesDeEscribir = null;
        parent::tearDown();
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function eventoCompartido(): int
    {
        return DB::table('cf_eventos')->insertGetId(['nombre' => self::EVENTO_ERRADO, 'nombre_normalizado' => 'congreso de finanzas', 'anio' => 2026,
            'estado' => 'activo', 'created_at' => now(), 'updated_at' => now()]);
    }

    /** Base del evento compartido con N reales + los 8 QA, ya emitida. */
    private function baseEmitida(int $eventoId, int $reales = 3): Lote
    {
        $lote = $this->loteCon(0, $this->institucional);
        $lote->update(['evento_id' => $eventoId]);
        for ($i = 1; $i <= $reales; $i++) {
            $this->participante($lote, "Persona Real {$lote->id}-{$i}", 'C.C. '.(40343230 + $lote->id * 100 + $i));
        }
        app(ServicioQa::class)->incorporar($lote);
        app(EmisorLote::class)->emitirTodo($lote->fresh(), $this->admin()->id);

        return $lote->fresh();
    }

    private function corregirDatoComun(Lote $lote, string $evento = self::EVENTO_CORRECTO)
    {
        return $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))->put(route('credential-flow.lotes.update', $lote), [
            'nombre' => $lote->nombre, 'evento' => $evento, 'fecha' => $lote->datos_comunes['fecha'], 'intensidad_horaria' => $lote->datos_comunes['intensidad_horaria'],
        ]);
    }

    private function plan(Lote $lote): array
    {
        return $this->actingAs($this->superAdmin())->getJson(route('credential-flow.lotes.correccion', $lote))->assertOk()->json('plan');
    }

    private function confirmar(Lote $lote, ?string $huella = null, array $extra = [])
    {
        return $this->actingAs($this->superAdmin())->postJson(route('credential-flow.lotes.correccion.reemitir', $lote), array_merge([
            'huella' => $huella ?? $this->plan($lote)['huella'], 'motivo' => self::MOTIVO, 'confirmo' => true,
        ], $extra));
    }

    private function vigentes(Lote $lote, bool $qa): Collection
    {
        return Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)
            ->whereIn('participante_id', $lote->participantes()->where('es_control_qa', $qa)->select('id'))->orderBy('id')->get();
    }

    /** Todas las emisiones (fila completa), todos los archivos y todas las bases y eventos: para probar que algo no cambió en absoluto. */
    private function foto(): array
    {
        return [
            DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(),
            collect($this->archivosDeEmisiones())->mapWithKeys(fn ($f) => [$f => hash('sha256', Storage::disk('local')->get($f))])->sort()->all(),
            DB::table('cf_eventos')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(),
            DB::table('cf_descargas')->count(),
        ];
    }

    private function textosPdf(string $bytes): array
    {
        return array_column((new InspectorPdf($bytes))->textos(), 'texto');
    }

    private function sinCorreos(): void
    {
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
        foreach (['cf_envios'] as $tabla) {
            if (Schema::hasTable($tabla)) {
                $this->assertSame(0, DB::table($tabla)->count(), "no se crea nada en {$tabla}");
            }
        }
    }

    // ── 1. Corregir el dato común: solo esta base ─────────────────────────────

    public function test_corregir_el_dato_comun_cambia_solo_esta_base_no_reemite_y_no_toca_el_evento_compartido(): void
    {
        $evento = $this->eventoCompartido();
        $elegida = $this->baseEmitida($evento);
        $otra = $this->baseEmitida($evento, 2);
        $eventoAntes = (array) DB::table('cf_eventos')->find($evento);
        $emisionesAntes = DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();

        $this->corregirDatoComun($elegida)->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'certificados vigentes no cambiaron') && str_contains($m, '«Corregir toda la base»'));

        $this->assertSame(self::EVENTO_CORRECTO, $elegida->fresh()->datos_comunes['evento']);
        $this->assertSame(self::EVENTO_ERRADO, $otra->fresh()->datos_comunes['evento'], 'la otra base del mismo evento no cambia');
        $this->assertEquals($eventoAntes, (array) DB::table('cf_eventos')->find($evento), 'el evento compartido no se modifica');
        $this->assertEquals($emisionesAntes, DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(), 'guardar no reemite ni revoca');

        $plan = $this->plan($elegida);
        $this->assertSame(3, $plan['grupos']['oficiales']['a_reemitir']);
        $this->assertSame(8, $plan['grupos']['qa']['a_reemitir']);
        $this->assertSame(1, $plan['grupos']['oficiales']['motivos']['datos'] > 0 ? 1 : 0);
        $this->assertSame(11, $plan['total']);
        $this->assertNull($plan['bloqueo']);
        $this->assertCount(3, $plan['grupos']['oficiales']['personas']);
        $this->assertCount(8, $plan['grupos']['qa']['personas']);

        $planOtra = $this->plan($otra);
        $this->assertSame(0, $planOtra['total'], 'la otra base sigue al día');
    }

    // ── 2. Antes y después ────────────────────────────────────────────────────

    public function test_antes_de_confirmar_muestra_el_pdf_anterior_y_el_propuesto_de_un_oficial_y_de_un_qa_sin_escribir_nada(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $foto = $this->foto();
        $oficial = $this->vigentes($lote, false)->first();
        $qa = $this->vigentes($lote, true)->first();

        $anterior = $this->actingAs($this->superAdmin())->get(route('credential-flow.lotes.correccion.anterior', [$lote, $oficial]))->assertOk();
        $this->assertSame('application/pdf', $anterior->headers->get('Content-Type'));
        $this->assertStringStartsWith('inline', (string) $anterior->headers->get('Content-Disposition'));
        $this->assertSame($oficial->pdf_hash, hash('sha256', $anterior->getContent()), 'el anterior es exactamente el PDF guardado');
        $this->assertContains(self::EVENTO_ERRADO, $this->textosPdf($anterior->getContent()));

        $propuesto = $this->actingAs($this->superAdmin())->get(route('credential-flow.lotes.correccion.propuesto', [$lote, $oficial]))->assertOk();
        $textos = $this->textosPdf($propuesto->getContent());
        $this->assertContains(self::EVENTO_CORRECTO, $textos);
        $this->assertNotContains('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', $textos, 'un oficial se previsualiza con la plantilla institucional');

        $propuestoQa = $this->textosPdf($this->actingAs($this->superAdmin())->get(route('credential-flow.lotes.correccion.propuesto', [$lote, $qa]))->assertOk()->getContent());
        $this->assertContains(self::EVENTO_CORRECTO, $propuestoQa);
        $this->assertContains('PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', $propuestoQa, 'un QA se previsualiza con su variante de pruebas internas');

        $this->assertEquals($foto, $this->foto(), 'ver el antes y el después no escribe nada (ni descargas)');

        // Solo certificados de ESTA base.
        $ajena = $this->baseEmitida($this->eventoCompartido(), 1);
        $deOtra = $this->vigentes($ajena, false)->first();
        $this->actingAs($this->superAdmin())->get(route('credential-flow.lotes.correccion.anterior', [$lote, $deOtra]))->assertNotFound();
        $this->actingAs($this->superAdmin())->get(route('credential-flow.lotes.correccion.propuesto', [$lote, $deOtra]))->assertNotFound();
    }

    // ── 3. Confirmar: oficiales y QA por separado, sin correos ────────────────

    public function test_confirmar_reemite_oficiales_y_qa_con_resultado_separado_sin_enviar_correos_y_solo_en_esta_base(): void
    {
        $evento = $this->eventoCompartido();
        $lote = $this->baseEmitida($evento);
        $otra = $this->baseEmitida($evento, 2);
        $this->corregirDatoComun($lote);
        $anterioresOficiales = $this->vigentes($lote, false);
        $anterioresQa = $this->vigentes($lote, true);
        $hashes = Emision::query()->pluck('pdf_hash', 'id');
        $otraAntes = DB::table('cf_emisiones')->where('lote_id', $otra->id)->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
        $eventoAntes = (array) DB::table('cf_eventos')->find($evento);

        $r = $this->confirmar($lote)->assertOk()->json();

        $this->assertSame('COMPLETO', $r['estado']);
        $this->assertSame(['estado' => 'reemitido', 'total' => 3, 'esperados' => 3, 'restantes' => 0], array_intersect_key($r['grupos']['oficiales'], array_flip(['estado', 'total', 'esperados', 'restantes'])));
        $this->assertSame(['estado' => 'reemitido', 'total' => 8, 'esperados' => 8, 'restantes' => 0], array_intersect_key($r['grupos']['qa'], array_flip(['estado', 'total', 'esperados', 'restantes'])));
        $this->assertSame(0, $r['correos_enviados']);
        $this->sinCorreos();

        foreach ($anterioresOficiales->concat($anterioresQa) as $a) {
            $a->refresh();
            $this->assertSame(Emision::REVOCADA, $a->estado);
            $this->assertSame('Reemplazada por la versión 2. Motivo: '.self::MOTIVO, $a->motivo_revocacion);
            $this->assertSame($hashes[$a->id], hash('sha256', Storage::disk('local')->get($a->pdf_archivo)), 'el PDF anterior queda intacto');
        }
        foreach ($this->vigentes($lote, false)->concat($this->vigentes($lote, true)) as $nueva) {
            $this->assertSame(2, $nueva->version);
            $this->assertContains(self::EVENTO_CORRECTO, $this->textosPdf(Storage::disk('local')->get($nueva->pdf_archivo)));
        }
        $this->assertEquals($otraAntes, DB::table('cf_emisiones')->where('lote_id', $otra->id)->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(), 'la otra base del evento no cambia');
        $this->assertEquals($eventoAntes, (array) DB::table('cf_eventos')->find($evento), 'el evento compartido no cambia');
        $this->assertSame(2, DB::table('movimientos')->where('descripcion', 'like', 'Se reemitieron credenciales de una base%')->count(), 'una auditoría por grupo');

        // Repetir no duplica nada.
        $plan = $this->plan($lote);
        $this->assertSame(0, $plan['total']);
        $repetir = $this->confirmar($lote, $plan['huella'])->assertOk()->json();
        $this->assertSame('SIN_CAMBIOS', $repetir['estado']);
        $this->assertSame(11, $this->vigentes($lote, false)->concat($this->vigentes($lote, true))->where('version', 2)->count());
    }

    public function test_sin_huella_vigente_motivo_o_casilla_no_se_reemite_nada(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $huella = $this->plan($lote)['huella'];
        $foto = $this->foto();

        $this->confirmar($lote, $huella, ['confirmo' => false])->assertUnprocessable();
        $this->confirmar($lote, $huella, ['motivo' => 'corto'])->assertUnprocessable();

        // La base cambió después de revisar: la huella ya no vale.
        $this->corregirDatoComun($lote->fresh(), 'OTRO NOMBRE DEL EVENTO');
        $r = $this->confirmar($lote, $huella)->assertStatus(409)->json();
        $this->assertSame('SIN_CAMBIOS', $r['estado']);
        $this->assertSame('PLAN_DESACTUALIZADO', $r['error']['code']);

        $this->assertEquals($foto[0], $this->foto()[0], 'ninguna emisión cambió');
        $this->assertEquals($foto[1], $this->foto()[1], 'ningún archivo cambió');
        $this->sinCorreos();
    }

    // ── 4. Si falla un grupo ──────────────────────────────────────────────────

    public function test_si_falla_el_grupo_qa_los_oficiales_quedan_reemitidos_y_al_reintentar_solo_se_procesa_lo_pendiente(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $qaAntes = DB::table('cf_emisiones')->whereIn('id', $this->vigentes($lote, true)->pluck('id'))->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();
        $archivosQa = $this->vigentes($lote, true)->mapWithKeys(fn ($e) => [$e->pdf_archivo => hash('sha256', Storage::disk('local')->get($e->pdf_archivo))])->all();

        // Fallo de disco simulado al escribir el primer PDF del grupo QA (los 3 oficiales se escriben antes).
        $escrituras = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$escrituras) {
            if (++$escrituras > 3) {
                throw new \RuntimeException('disco lleno simulado');
            }
        };

        $r = $this->confirmar($lote)->assertOk()->json();

        $this->assertSame('PARCIAL', $r['estado']);
        $this->assertSame('reemitido', $r['grupos']['oficiales']['estado']);
        $this->assertSame(3, $r['grupos']['oficiales']['total']);
        $this->assertSame('fallido', $r['grupos']['qa']['estado']);
        $this->assertSame(8, $r['grupos']['qa']['esperados']);
        $this->assertSame(0, $r['grupos']['qa']['total']);
        $this->assertSame('ERROR_ESCRITURA', $r['grupos']['qa']['error']['code']);
        $this->assertStringNotContainsString('disco lleno simulado', json_encode($r), 'el detalle técnico no se muestra');

        // El grupo QA quedó exactamente como estaba: mismas filas, mismos archivos, sin residuos de staging.
        $this->assertEquals($qaAntes, DB::table('cf_emisiones')->whereIn('id', array_column($qaAntes, 'id'))->orderBy('id')->get()->map(fn ($e) => (array) $e)->all());
        foreach ($archivosQa as $ruta => $hash) {
            $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($ruta)));
        }
        $this->assertSame([], array_values(array_filter($this->archivosDeEmisiones(), fn ($f) => str_contains($f, '.staging') || str_contains($f, '.tmp'))));
        $this->assertSame(3, $this->vigentes($lote, false)->where('version', 2)->count());

        // Reintentar: el plan nuevo solo trae lo pendiente (los 8 QA) y los oficiales no se vuelven a reemitir.
        AlmacenEmisiones::$antesDeEscribir = null;
        $plan = $this->plan($lote);
        $this->assertSame(0, $plan['grupos']['oficiales']['a_reemitir']);
        $this->assertSame(8, $plan['grupos']['qa']['a_reemitir']);

        $r2 = $this->confirmar($lote, $plan['huella'])->assertOk()->json();
        $this->assertSame('COMPLETO', $r2['estado']);
        $this->assertSame('al_dia', $r2['grupos']['oficiales']['estado']);
        $this->assertSame(8, $r2['grupos']['qa']['total']);
        $this->assertSame(3, $this->vigentes($lote, false)->where('version', 2)->count(), 'los oficiales siguen en la versión 2: no se duplicaron');
        $this->assertSame(0, Emision::query()->where('lote_id', $lote->id)->where('version', 3)->count());
        $this->assertSame(8, $this->vigentes($lote, true)->where('version', 2)->count());
        $this->sinCorreos();
    }

    public function test_si_falla_el_grupo_de_oficiales_no_cambia_nada_en_ningun_grupo(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $huella = $this->plan($lote)['huella'];
        $foto = $this->foto();
        AlmacenEmisiones::$antesDeEscribir = fn () => throw new \RuntimeException('disco lleno simulado');

        $r = $this->confirmar($lote, $huella)->assertStatus(409)->json();

        $this->assertSame('SIN_CAMBIOS', $r['estado']);
        $this->assertSame('fallido', $r['grupos']['oficiales']['estado']);
        $this->assertSame('no_intentado', $r['grupos']['qa']['estado']);
        $this->assertEquals($foto, $this->foto());
        $this->sinCorreos();
    }

    public function test_una_programacion_activa_o_una_base_sin_cambios_no_se_puede_confirmar(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());

        $plan = $this->plan($lote);
        $this->assertSame(0, $plan['total']);
        $this->assertSame('SIN_CAMBIOS', $this->confirmar($lote, $plan['huella'])->assertOk()->json('estado'));

        $this->corregirDatoComun($lote);
        DB::table('cf_emisiones_programadas')->insert(['lote_id' => $lote->id, 'lote_activa' => $lote->id, 'estado' => 'pendiente', 'version' => 1,
            'programada_para' => now()->addDay(), 'zona_horaria' => 'America/Bogota', 'huella' => str_repeat('a', 64), 'huella_detalle' => '[]', 'oficiales_esperados' => 3, 'qa_esperados' => 8,
            'plantilla_id' => $this->institucional->id, 'programada_por' => $this->admin()->id, 'created_at' => now(), 'updated_at' => now()]);
        $plan = $this->plan($lote);
        $this->assertNotNull($plan['bloqueo']);
        $foto = $this->foto();
        $this->confirmar($lote, $plan['huella'])->assertStatus(409);
        $this->assertEquals($foto, $this->foto());
    }

    // ── 5. Permisos: solo el super-admin corrige toda la base ─────────────────

    public function test_un_admin_no_puede_revisar_ver_ni_confirmar_la_correccion_masiva_y_nada_cambia(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $huella = $this->plan($lote)['huella'];
        $oficial = $this->vigentes($lote, false)->first();
        $foto = $this->foto();
        $movimientos = DB::table('movimientos')->count();
        $datos = ['huella' => $huella, 'motivo' => self::MOTIVO, 'confirmo' => true];

        $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.correccion', $lote))->assertForbidden();
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.correccion.anterior', [$lote, $oficial]))->assertForbidden();
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.correccion.propuesto', [$lote, $oficial]))->assertForbidden();
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.correccion.reemitir', $lote), $datos)->assertForbidden();
        // Un rol sin acceso a Credential Flow tampoco.
        $this->actingAs($this->comercial())->postJson(route('credential-flow.lotes.correccion.reemitir', $lote), $datos)->assertForbidden();

        $this->assertEquals($foto, $this->foto(), 'ningún certificado, archivo ni evento cambió');
        $this->assertSame($movimientos, DB::table('movimientos')->count(), 'no queda auditoría de una reemisión que no ocurrió');
        $this->sinCorreos();
    }

    public function test_el_servicio_tambien_rechaza_a_un_admin_aunque_llegue_sin_pasar_por_la_ruta(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $huella = $this->plan($lote)['huella'];
        $foto = $this->foto();

        $this->assertFalse(CorreccionBase::puedeUsar($this->admin()));
        $this->assertTrue(CorreccionBase::puedeUsar($this->superAdmin()));
        $this->assertFalse(CorreccionBase::puedeUsar(null));

        try {
            app(CorreccionBase::class)->ejecutar($lote, $huella, self::MOTIVO, $this->admin());
            $this->fail('un admin no debe poder ejecutar la corrección masiva');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::NO_AUTORIZADO, $e->codigo);
            $this->assertSame(403, $e->estadoHttp);
        }
        $this->assertEquals($foto, $this->foto());
        $this->sinCorreos();
    }

    public function test_la_ficha_ofrece_corregir_toda_la_base_solo_al_super_admin(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());

        $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permisos.corregir_base', false));
        $this->actingAs($this->superAdmin())->get(route('credential-flow.lotes.show', $lote))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('permisos.corregir_base', true));

        // Al guardar la base, el aviso no manda al admin a una acción que no puede usar.
        $this->corregirDatoComun($lote)->assertSessionHas('success', fn ($m) => str_contains($m, 'certificados vigentes no cambiaron')
            && str_contains($m, 'super-administrador') && str_contains($m, '«Reemitir certificado»'));
    }

    public function test_el_admin_conserva_la_reemision_individual_y_la_correccion_masiva_despues_solo_toma_lo_pendiente(): void
    {
        $lote = $this->baseEmitida($this->eventoCompartido());
        $this->corregirDatoComun($lote);
        $elegida = $this->vigentes($lote, false)->first();

        // El admin corrige UNA persona con «Reemitir certificado», como siempre.
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $elegida), ['motivo' => 'Corrección individual sintética'])->assertCreated();
        $this->assertSame(Emision::REVOCADA, $elegida->fresh()->estado);
        $nueva = $this->vigentes($lote, false)->firstWhere('participante_id', $elegida->participante_id);
        $this->assertSame(2, $nueva->version);
        $this->assertContains(self::EVENTO_CORRECTO, $this->textosPdf(Storage::disk('local')->get($nueva->pdf_archivo)));
        $this->assertSame(2, $this->vigentes($lote, false)->where('version', 1)->count(), 'los otros oficiales siguen en la versión 1');

        // El super-admin corrige el resto: el plan ya no incluye a quien se reemitió individualmente.
        $plan = $this->plan($lote);
        $this->assertSame(2, $plan['grupos']['oficiales']['a_reemitir']);
        $this->assertSame(8, $plan['grupos']['qa']['a_reemitir']);
        $r = $this->confirmar($lote, $plan['huella'])->assertOk()->json();
        $this->assertSame('COMPLETO', $r['estado']);
        $this->assertSame(2, $r['grupos']['oficiales']['total']);
        $this->assertSame(8, $r['grupos']['qa']['total']);
        $this->assertSame($nueva->id, $this->vigentes($lote, false)->firstWhere('participante_id', $elegida->participante_id)->id, 'la reemisión individual no se repite');
        $this->assertSame(0, Emision::query()->where('lote_id', $lote->id)->where('version', 3)->count());
        $this->assertSame(3, $this->vigentes($lote, false)->where('version', 2)->count());
        $this->sinCorreos();
    }
}
