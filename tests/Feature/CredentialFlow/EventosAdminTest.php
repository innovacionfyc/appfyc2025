<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;
use ZipArchive;

/**
 * Asociación de bases con eventos (`cf_eventos`) y descarga administrativa por evento: sin duplicar, sin asociar a ciegas ante la duda, QA por
 * PARTICIPANTE (también en bases mixtas), revocadas fuera y nada emitido cambia.
 */
class EventosAdminTest extends EmisionesTestCase
{
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
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
    }

    private function base(string $evento, string $fecha, int $participantes, bool $emitir = true, string $nombre = ''): Lote
    {
        $lote = $this->loteCon($participantes);
        $lote->update(['datos_comunes' => ['evento' => $evento, 'fecha' => $fecha, 'intensidad_horaria' => '8 horas'], 'nombre' => $nombre ?: $lote->nombre]);
        if ($emitir) {
            $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        }

        return $lote->fresh();
    }

    /** Marca como QA a los N primeros participantes de la base (por id), a través del servicio real. */
    private function marcarQa(Lote $lote, ?int $primeros = null): void
    {
        $ids = $lote->participantes()->orderBy('id')->when($primeros !== null, fn ($q) => $q->limit($primeros))->pluck('id');
        (new ServicioEventos)->marcarParticipantesQa($lote, $ids, true);
    }

    /** Plantilla de pruebas internas: con la marca visible en el nombre y designada para QA. */
    private function plantillaQa(): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 60 + $i * 60]), $campos, array_keys($campos));
        $els[] = $this->elemento(['type' => 'text', 'text' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'y' => 380, 'fontSize' => 18]);
        $p = $this->plantillaLista($els);
        $p->update(['nombre' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'es_plantilla_qa' => true]);

        return $p->fresh();
    }

    private function desdeBase(Lote $lote, array $datos = [])
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.lotes.evento.desde-base', $lote), $datos);
    }

    private function historico(string $nombre, int $anio): EventoCertificacion
    {
        return EventoCertificacion::create(['nombre' => $nombre, 'nombre_normalizado' => NombreConservador::normalizar($nombre), 'anio' => $anio, 'estado' => 'activo', 'origen' => 'legado']);
    }

    private function entradasZip(string $ruta): array
    {
        $zip = new ZipArchive;
        $this->assertTrue($zip->open($ruta));
        $n = [];
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $n[] = $zip->getNameIndex($i);
        }
        $zip->close();

        return $n;
    }

    // ── Asociación ────────────────────────────────────────────────────────────

    public function test_dos_bases_del_mismo_evento_comparten_un_unico_evento(): void
    {
        $presencial = $this->base('Seminario de Contratación Pública', '10 de octubre de 2026', 2);
        $virtual = $this->base('SEMINARIO DE CONTRATACION PUBLICA.', '11 de octubre de 2026', 3);

        $this->desdeBase($presencial)->assertRedirect();
        $this->desdeBase($virtual)->assertRedirect();
        $this->desdeBase($virtual)->assertRedirect(); // repetir no duplica

        $this->assertSame(1, EventoCertificacion::where('origen', 'credential_flow')->count());
        $evento = EventoCertificacion::first();
        $this->assertSame($evento->id, $presencial->fresh()->evento_id);
        $this->assertSame($evento->id, $virtual->fresh()->evento_id);
        $this->assertSame(2026, $evento->anio);
    }

    public function test_mismo_nombre_en_otro_anio_es_otro_evento(): void
    {
        $this->desdeBase($this->base('Congreso Anual', '5 de mayo de 2026', 1))->assertRedirect();
        $this->desdeBase($this->base('Congreso Anual', '5 de mayo de 2027', 1, false))->assertRedirect();

        $this->assertSame(2, EventoCertificacion::count());
    }

    public function test_asociar_no_modifica_emisiones_ni_participantes(): void
    {
        $lote = $this->base('Taller de Prueba', '1 de marzo de 2026', 2);
        $this->marcarQa($lote, 1);
        $antes = [Emision::orderBy('id')->get()->toArray(), DB::table('cf_participantes')->orderBy('id')->get()->toArray()];

        $this->desdeBase($lote)->assertRedirect();

        $this->assertSame($antes[0], Emision::orderBy('id')->get()->toArray());
        $this->assertEquals($antes[1], DB::table('cf_participantes')->orderBy('id')->get()->toArray());
    }

    public function test_base_sin_nombre_de_evento_no_se_asocia(): void
    {
        $lote = $this->base('X', 'sin año', 1, false);
        $lote->update(['datos_comunes' => ['evento' => '', 'fecha' => '', 'intensidad_horaria' => '1']]);

        $this->desdeBase($lote)->assertRedirect()->assertSessionHas('error');
        $this->assertSame(0, EventoCertificacion::count());
        $this->assertNull($lote->fresh()->evento_id);
    }

    public function test_no_se_asocia_a_un_evento_historico(): void
    {
        $historico = $this->historico('Histórico', 2019);
        $lote = $this->base('Otro', '1 de marzo de 2026', 1, false);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.evento', $lote), ['evento_id' => $historico->id])->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lote->fresh()->evento_id);
        $this->desdeBase($lote, ['evento_id' => $historico->id])->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lote->fresh()->evento_id);
        $this->actingAs($this->admin())->get(route('credential-flow.eventos.show', $historico))->assertNotFound();
    }

    public function test_desasociar_y_cambiar_de_evento(): void
    {
        $lote = $this->base('Evento A', '1 de marzo de 2026', 1, false);
        $this->desdeBase($lote);
        $b = $this->base('Evento B', '2 de marzo de 2026', 1, false);
        $this->desdeBase($b);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.evento', $lote), ['evento_id' => $b->fresh()->evento_id])->assertRedirect();
        $this->assertSame($b->fresh()->evento_id, $lote->fresh()->evento_id);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.evento', $lote), ['evento_id' => null])->assertRedirect();
        $this->assertNull($lote->fresh()->evento_id);
    }

    // ── Ambigüedad: nunca se asocia a ciegas ──────────────────────────────────

    public function test_sin_anio_exige_elegir_o_confirmar(): void
    {
        $existente = EventoCertificacion::create(['nombre' => 'Foro Anual', 'nombre_normalizado' => 'foro anual', 'anio' => 2026, 'estado' => 'activo', 'origen' => 'credential_flow']);
        $lote = $this->base('Foro Anual', 'próximo otoño', 1, false);

        $this->desdeBase($lote)->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lote->fresh()->evento_id);
        $this->assertSame(1, EventoCertificacion::count());

        $this->desdeBase($lote, ['evento_id' => $existente->id])->assertRedirect(route('credential-flow.eventos.show', $existente));
        $this->assertSame($existente->id, $lote->fresh()->evento_id);
        $this->assertSame(1, EventoCertificacion::count());
    }

    public function test_varios_candidatos_con_el_mismo_nombre_y_anio_exigen_elegir(): void
    {
        $a = EventoCertificacion::create(['nombre' => 'Congreso', 'nombre_normalizado' => 'congreso', 'anio' => 2026, 'fecha_texto' => '5 de mayo de 2026', 'estado' => 'activo', 'origen' => 'credential_flow']);
        $b = EventoCertificacion::create(['nombre' => 'Congreso', 'nombre_normalizado' => 'congreso', 'anio' => 2026, 'fecha_texto' => '9 de noviembre de 2026', 'estado' => 'activo', 'origen' => 'credential_flow']);
        $lote = $this->base('Congreso', '9 de noviembre de 2026', 1, false);

        $this->desdeBase($lote)->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lote->fresh()->evento_id);

        $this->desdeBase($lote, ['evento_id' => $b->id])->assertRedirect();
        $this->assertSame($b->id, $lote->fresh()->evento_id);
        $this->assertSame(2, EventoCertificacion::count());
    }

    public function test_fechas_en_meses_distintos_exigen_confirmacion(): void
    {
        $mayo = EventoCertificacion::create(['nombre' => 'Congreso', 'nombre_normalizado' => 'congreso', 'anio' => 2026, 'fecha_texto' => '5 de mayo de 2026', 'estado' => 'activo', 'origen' => 'credential_flow']);
        $lote = $this->base('Congreso', '9 de noviembre de 2026', 1, false);

        $this->desdeBase($lote)->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lote->fresh()->evento_id);

        $this->desdeBase($lote, ['crear_nuevo' => 1])->assertRedirect();
        $this->assertSame(2, EventoCertificacion::count());
        $this->assertNotSame($mayo->id, $lote->fresh()->evento_id);
    }

    public function test_un_homonimo_historico_exige_confirmar_y_no_se_toca(): void
    {
        $historico = $this->historico('Congreso de Finanzas', 2026);
        $antes = $historico->fresh()->toArray();
        $lote = $this->base('Congreso de Finanzas', '17 de septiembre de 2026', 1, false);

        $this->desdeBase($lote)->assertRedirect()->assertSessionHas('error');
        $this->assertNull($lote->fresh()->evento_id);

        $this->desdeBase($lote, ['crear_nuevo' => 1])->assertRedirect();
        $this->assertNotNull($lote->fresh()->evento_id);
        $this->assertNotSame($historico->id, $lote->fresh()->evento_id);
        $this->assertSame($antes, $historico->fresh()->toArray());
    }

    public function test_confirmar_evento_nuevo_es_idempotente(): void
    {
        $lote = $this->base('Foro Único', 'sin año', 1, false);

        $this->desdeBase($lote, ['crear_nuevo' => 1])->assertRedirect();
        $this->desdeBase($lote, ['crear_nuevo' => 1])->assertRedirect();
        $this->desdeBase($lote)->assertRedirect()->assertSessionMissing('error');

        $this->assertSame(1, EventoCertificacion::count());
    }

    public function test_evaluar_no_escribe_y_distingue_unir_crear_y_ambigua(): void
    {
        $s = new ServicioEventos;
        $nuevo = $this->base('Taller Nuevo', '1 de marzo de 2026', 1, false);
        $this->assertSame(ServicioEventos::CREAR, $s->evaluar($nuevo)['accion']);
        $this->assertSame(0, EventoCertificacion::count());

        $this->desdeBase($nuevo);
        $otra = $this->base('Taller Nuevo', '2 de marzo de 2026', 1, false);
        $e = $s->evaluar($otra);
        $this->assertSame(ServicioEventos::UNIR, $e['accion']);
        $this->assertSame(EventoCertificacion::first()->id, $e['evento']->id);
        $this->assertNull($otra->fresh()->evento_id);

        $this->assertSame(ServicioEventos::AMBIGUA, $s->evaluar($this->base('Taller Nuevo', 'sin año', 1, false))['accion']);
        $this->assertTrue(ServicioEventos::mesesDistintos('5 de mayo de 2026', 'octubre de 2026'));
        $this->assertFalse(ServicioEventos::mesesDistintos('17, 18 y 19 de septiembre de 2026', 'setiembre 2026'));
        $this->assertFalse(ServicioEventos::mesesDistintos('2026', '5 de mayo de 2026'), 'Sin meses no hay evidencia de que sean distintos');
    }

    // ── QA por participante (bases mixtas) ───────────────────────────────────

    public function test_base_mixta_separa_reales_y_qa_por_participante(): void
    {
        $mixta = $this->base('Seminario', '10 de octubre de 2026', 3); // 3 emitidos
        $this->participante($mixta, 'Contacto Control Uno', 'C.C. 800.001');
        $this->participante($mixta, 'Contacto Control Dos', 'C.C. 800.002'); // dos QA SIN emitir
        $qa = $mixta->participantes()->whereIn('documento', ['C.C. 800.001', 'C.C. 800.002'])->pluck('id');
        (new ServicioEventos)->marcarParticipantesQa($mixta, $qa, true);
        $this->desdeBase($mixta);
        $evento = EventoCertificacion::first();

        $r = (new ServicioEventos)->resumen($evento);

        $this->assertEquals(['vigentes' => 3, 'revocadas' => 0, 'pendientes' => 0, 'participantes' => 3], $r['reales'], 'Los QA sin emitir no son pendientes reales');
        $this->assertEquals(['participantes' => 2, 'vigentes' => 0, 'revocadas' => 0], $r['qa']);

        // Se emite ahora a los QA (solo con la plantilla de pruebas internas): pasan a la columna QA, nunca a la de reales.
        $this->plantillaQa();
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $mixta))->assertCreated();
        $r = (new ServicioEventos)->resumen($evento);
        $this->assertSame(3, $r['reales']['vigentes']);
        $this->assertSame(2, $r['qa']['vigentes']);
        $this->assertSame(0, $r['reales']['pendientes']);
    }

    public function test_zip_y_listado_de_una_base_mixta_excluyen_los_qa(): void
    {
        $mixta = $this->base('Seminario', '10 de octubre de 2026', 4);
        $this->marcarQa($mixta, 1);
        $soloQa = $this->base('Seminario', '10 de octubre de 2026', 2, true, 'Solo control');
        $this->marcarQa($soloQa);
        $this->desdeBase($mixta);
        $this->desdeBase($soloQa);
        $evento = EventoCertificacion::first();
        $idsQa = ServicioEventos::emisiones($evento->id, true)->pluck('cf_emisiones.id')->all();
        $this->assertCount(3, $idsQa);

        $r = $this->actingAs($this->admin())->get(route('credential-flow.eventos.zip', $evento));
        $r->assertOk();
        $this->assertCount(3, $this->entradasZip($r->baseResponse->getFile()->getPathname()), 'Solo los 3 reales de la base mixta');

        $con = $this->actingAs($this->admin())->get(route('credential-flow.eventos.zip', ['evento' => $evento, 'incluir_qa' => 1]));
        $this->assertStringContainsString('-con-qa', $con->headers->get('Content-Disposition'));
        $this->assertCount(6, $this->entradasZip($con->baseResponse->getFile()->getPathname()));

        $this->actingAs($this->admin())->get(route('credential-flow.eventos.show', $evento))->assertOk()
            ->assertInertia(fn ($p) => $p->component('CredentialFlow/Eventos/Show')->has('bases', 2)->has('emisiones.data', 3)
                ->where('bases.0.reales', 3)->where('bases.0.qa', 1)->where('bases.0.vigentes', 3)
                ->where('bases.1.reales', 0)->where('bases.1.qa', 2)
                ->where('emisiones.data', fn ($filas) => collect($filas)->pluck('id')->intersect($idsQa)->isEmpty()));
    }

    public function test_marcar_qa_no_toca_emisiones_pdf_codigos_ni_estados(): void
    {
        $lote = $this->base('Seminario', '10 de octubre de 2026', 3);
        $antes = Emision::orderBy('id')->get()->toArray();

        $this->marcarQa($lote, 2);
        $this->assertSame($antes, Emision::orderBy('id')->get()->toArray());
        $this->assertSame(2, Participante::where('es_control_qa', true)->count());

        (new ServicioEventos)->marcarParticipantesQa($lote, $lote->participantes()->pluck('id'), false);
        $this->assertSame(0, Participante::where('es_control_qa', true)->count());
        $this->assertSame($antes, Emision::orderBy('id')->get()->toArray());
    }

    public function test_resumen_cuenta_revocadas_y_pendientes_reales(): void
    {
        $real = $this->base('Seminario', '10 de octubre de 2026', 3);
        $this->participante($real, 'Persona Pendiente', 'C.C. 999.001');
        $this->marcarQa($this->base('Seminario', '10 de octubre de 2026', 2, true, 'Control QA'));
        $this->desdeBase($real);
        $this->desdeBase(Lote::where('nombre', 'Control QA')->first());
        $primera = Emision::where('lote_id', $real->id)->orderBy('id')->first();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $primera), ['motivo' => 'Revocada de prueba'])->assertOk();

        $r = (new ServicioEventos)->resumen(EventoCertificacion::first());

        $this->assertSame(2, $r['bases']);
        $this->assertEquals(['vigentes' => 2, 'revocadas' => 1, 'pendientes' => 2, 'participantes' => 4], $r['reales']);
        $this->assertEquals(['participantes' => 2, 'vigentes' => 2, 'revocadas' => 0], $r['qa']);
    }

    public function test_zip_del_evento_deja_fuera_las_revocadas(): void
    {
        $presencial = $this->base('Seminario', '10 de octubre de 2026', 2);
        $virtual = $this->base('Seminario', '10 de octubre de 2026', 2);
        $this->desdeBase($presencial);
        $this->desdeBase($virtual);
        $revocada = Emision::where('lote_id', $virtual->id)->orderBy('id')->first();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $revocada), ['motivo' => 'Revocada de prueba'])->assertOk();
        $evento = EventoCertificacion::first();

        $r = $this->actingAs($this->admin())->get(route('credential-flow.eventos.zip', $evento));
        $r->assertOk()->assertHeader('Content-Type', 'application/zip');
        $this->assertStringContainsString('credenciales-evento-'.$evento->id.'.zip', $r->headers->get('Content-Disposition'));
        $this->assertCount(3, $this->entradasZip($r->baseResponse->getFile()->getPathname()));
    }

    public function test_zip_de_evento_sin_vigentes_reales_devuelve_el_mensaje(): void
    {
        $lote = $this->base('Sin emitir', '1 de marzo de 2026', 1, false);
        $this->desdeBase($lote);

        $this->actingAs($this->admin())->get(route('credential-flow.eventos.zip', EventoCertificacion::first()))
            ->assertRedirect()->assertSessionHas('error');
    }

    // ── Pantallas y permisos ─────────────────────────────────────────────────

    public function test_la_pantalla_de_eventos_ofrece_propuesta_y_enlaza_al_historico(): void
    {
        $this->historico('Evento viejo', 2019);
        $this->base('Foro Libre', '1 de marzo de 2026', 1, false);
        $this->base('Foro Dudoso', 'sin año', 1, false);
        $this->desdeBase($this->base('Foro Ya', '3 de marzo de 2026', 2));
        $this->marcarQa(Lote::where('evento_id', '!=', null)->first(), 1);

        $this->actingAs($this->admin())->get(route('credential-flow.eventos.index'))->assertOk()
            ->assertInertia(fn ($p) => $p->component('CredentialFlow/Eventos/Index')->has('eventos', 1)
                ->where('eventos.0.reales.vigentes', 1)->where('eventos.0.qa.participantes', 1)
                ->has('sin_evento', 2)
                ->where('sin_evento.0.propuesta.accion', fn ($a) => in_array($a, ['ambigua', 'crear'], true))
                ->where('historico.eventos', 1)->has('historico.certificados'));
    }

    public function test_un_visitante_sin_sesion_es_redirigido(): void
    {
        $this->get(route('credential-flow.eventos.index'))->assertRedirect();
    }

    public function test_solo_admin_y_super_admin_acceden(): void
    {
        $lote = $this->base('Seminario', '10 de octubre de 2026', 1, false);
        $this->desdeBase($lote);
        $evento = EventoCertificacion::first();

        $this->actingAs($this->comercial())->get(route('credential-flow.eventos.index'))->assertForbidden();
        $this->actingAs($this->comercial())->get(route('credential-flow.eventos.zip', $evento))->assertForbidden();
        $this->actingAs($this->comercial())->post(route('credential-flow.lotes.evento.desde-base', $lote), ['crear_nuevo' => 1])->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('credential-flow.eventos.index'))->assertOk();
    }

    // ── Comando de marcado QA ────────────────────────────────────────────────

    private function conCorreos(Lote $lote, string $documento, string $correo): Participante
    {
        $p = $this->participante($lote, 'Contacto '.$documento, $documento);
        $n = Correo::normalizar($correo);
        $p->update(['correo' => $correo, 'correo_normalizado' => $n, 'correo_estado' => 'valido']);

        return $p->fresh();
    }

    public function test_el_comando_solo_muestra_sin_ejecutar_y_marca_por_correo_con_ejecutar(): void
    {
        $lote = $this->base('Seminario', '10 de octubre de 2026', 2);
        $qa = $this->conCorreos($lote, 'C.C. 700.001', 'Control.Uno@Example.test');
        $otro = $this->conCorreos($lote, 'C.C. 700.002', 'otro@example.test');
        $antes = Emision::orderBy('id')->get()->toArray();

        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--correo' => ['control.uno@example.test']])
            ->expectsOutputToContain('1 cambiarían a QA')->assertExitCode(0);
        $this->assertFalse($qa->fresh()->es_control_qa, 'Vista previa: no escribe');

        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--correo' => ['control.uno@example.test'], '--ejecutar' => true])
            ->expectsOutputToContain('1 participante(s) actualizados')->assertExitCode(0);
        $this->assertTrue($qa->fresh()->es_control_qa);
        $this->assertFalse($otro->fresh()->es_control_qa);
        $this->assertSame(0, Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->where('id', '!=', $qa->id)->count());
        $this->assertSame($antes, Emision::orderBy('id')->get()->toArray());

        // Idempotente y reversible.
        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--correo' => ['control.uno@example.test'], '--ejecutar' => true])->expectsOutputToContain('0 participante(s)')->assertExitCode(0);
        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--correo' => ['control.uno@example.test'], '--quitar' => true, '--ejecutar' => true])->assertExitCode(0);
        $this->assertFalse($qa->fresh()->es_control_qa);
    }

    public function test_el_comando_marca_toda_una_base_exclusivamente_qa(): void
    {
        $lote = $this->base('Seminario', '10 de octubre de 2026', 3, false);

        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--todos' => true, '--ejecutar' => true])->assertExitCode(0);

        $this->assertSame(3, Participante::where('lote_id', $lote->id)->where('es_control_qa', true)->count());
    }

    public function test_el_comando_exige_correo_o_todos_y_con_ambos_exige_que_todos_coincidan(): void
    {
        $lote = $this->base('Seminario', '10 de octubre de 2026', 1, false);

        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id])->assertExitCode(1);
        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--todos' => true, '--correo' => ['a@example.test']])->assertExitCode(1); // el participante no es de ese correo
        $this->artisan('credential-flow:qa-participantes', ['lote' => 99999, '--todos' => true])->assertExitCode(1);
        $this->assertSame(0, Participante::where('es_control_qa', true)->count());
    }

    public function test_todos_con_correos_marca_la_base_solo_si_todos_son_qa_conocidos(): void
    {
        $lote = $this->base('Seminario', '10 de octubre de 2026', 0, false);
        $a = $this->conCorreos($lote, 'C.C. 710.001', 'qa.uno@example.test');
        $b = $this->conCorreos($lote, 'C.C. 710.002', 'qa.dos@example.test');
        $real = $this->conCorreos($lote, 'C.C. 710.003', 'real@example.test');

        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--todos' => true, '--correo' => ['qa.uno@example.test', 'qa.dos@example.test'], '--ejecutar' => true])->assertExitCode(1);
        $this->assertSame(0, Participante::where('es_control_qa', true)->count(), 'Un real en la base impide marcarla completa');

        $real->delete();
        $this->artisan('credential-flow:qa-participantes', ['lote' => $lote->id, '--todos' => true, '--correo' => ['qa.uno@example.test', 'qa.dos@example.test'], '--ejecutar' => true])->assertExitCode(0);
        $this->assertTrue($a->fresh()->es_control_qa && $b->fresh()->es_control_qa);
    }
}
