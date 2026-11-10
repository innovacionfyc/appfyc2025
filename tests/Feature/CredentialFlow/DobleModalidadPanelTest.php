<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Models\Usuario;
use App\Support\CredentialFlow\Eventos\AutorizacionDobleModalidadException;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Autorización de la doble certificación por modalidad desde el panel (AutorizacionesDobleModalidad): solo super-admin, con motivo y confirmación, historial
 * y Movimientos; revocación bloqueada si dejaría datos inconsistentes; transición desde la variable heredada (evento #637) con el comando auditado.
 * Datos 100 % sintéticos.
 */
class DobleModalidadPanelTest extends EmisionesTestCase
{
    private const MOTIVO = 'Seminario con asistencia presencial y virtual de las mismas personas';

    private EventoCertificacion $evento;

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
            'database/migrations/2026_10_24_100000_cf_importaciones_table.php',
        ], '--force' => true])->assertExitCode(0);
        config(['credential_flow.doble_modalidad.eventos' => null]);
        $this->evento = $this->evento('Seminario sintético de doble modalidad');
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function evento(string $nombre, string $origen = EventoCertificacion::ORIGEN_CREDENTIAL_FLOW, string $estado = EventoCertificacion::ESTADO_ACTIVO): EventoCertificacion
    {
        return EventoCertificacion::create(['nombre' => $nombre, 'nombre_normalizado' => NombreConservador::normalizar($nombre), 'anio' => 2026,
            'fecha_texto' => '10 DE NOVIEMBRE DE 2026', 'estado' => $estado, 'origen' => $origen]);
    }

    private function super(): Usuario
    {
        return $this->superAdmin()->load('perfilOrganizador.rol');
    }

    private function servicio(): AutorizacionesDobleModalidad
    {
        return app(AutorizacionesDobleModalidad::class);
    }

    /** Base del evento con participantes creados directamente: [nombre, documento, modalidad, qa?]. */
    private function base(?EventoCertificacion $evento, array $personas, string $nombre = 'Base sintética'): Lote
    {
        $lote = Lote::create(['plantilla_id' => $this->plantillaLista()->id, 'nombre' => $nombre, 'evento_id' => $evento?->id,
            'datos_comunes' => ['evento' => 'Seminario sintético', 'fecha' => '10 de noviembre de 2026', 'intensidad_horaria' => '8 horas']]);
        foreach ($personas as [$n, $doc, $modalidad, $qa]) {
            $this->participante($lote, $n, $doc)->update(['modalidad' => $modalidad, 'es_control_qa' => $qa ?? false]);
        }

        return $lote;
    }

    private function conDobles(?EventoCertificacion $evento = null): Lote
    {
        return $this->base($evento ?? $this->evento, [['ANA FICTICIA DOBLE', 'C.C. 7100001', 'Presencial', false], ['ANA FICTICIA DOBLE', 'C.C. 7100001', 'Virtual', false],
            ['BETO FICTICIO', 'C.C. 7100002', 'Presencial', false]]);
    }

    private function sinDobles(?EventoCertificacion $evento = null): Lote
    {
        return $this->base($evento ?? $this->evento, [['CARO FICTICIA', 'C.C. 7100003', 'Presencial', false], ['DANI FICTICIO', 'C.C. 7100004', 'Virtual', false]], 'Base sin dobles');
    }

    private function codigo(callable $f): ?string
    {
        try {
            $f();
        } catch (AutorizacionDobleModalidadException $e) {
            return $e->codigo;
        }

        return null;
    }

    /** @return list<string> códigos de los bloqueos */
    private function bloqueos(callable $f): array
    {
        try {
            $f();
        } catch (AutorizacionDobleModalidadException $e) {
            return array_column($e->bloqueos, 'code') ?: [$e->codigo];
        }

        return [];
    }

    private function huella(Lote $lote): string
    {
        return hash('sha256', DB::table('cf_participantes')->where('lote_id', $lote->id)->orderBy('id')->get()->toJson().DB::table('cf_lotes')->where('id', $lote->id)->get()->toJson());
    }

    private function accion(string $accion, ?Usuario $u, array $datos = ['motivo' => self::MOTIVO, 'confirmacion' => true], ?EventoCertificacion $evento = null)
    {
        $evento ??= $this->evento;
        $req = $u === null ? $this : $this->actingAs($u);

        return $req->from(route('credential-flow.eventos.show', $evento))->post(route('credential-flow.eventos.doble-modalidad.'.$accion, $evento), $datos);
    }

    // ── Roles y permisos ──────────────────────────────────────────────────────

    public function test_super_admin_autoriza_desde_el_panel_con_motivo_y_queda_en_historial_y_movimientos(): void
    {
        $this->assertFalse(DobleModalidad::autorizado($this->evento->id));
        $this->accion('autorizar', $this->superAdmin())->assertRedirect(route('credential-flow.eventos.show', $this->evento))->assertSessionHas('success');

        $a = AutorizacionDobleModalidad::sole();
        $this->assertSame([$this->evento->id, 'vigente', 'panel', self::MOTIVO, $this->superAdmin()->id, $this->evento->id],
            [$a->evento_id, $a->estado, $a->origen, $a->motivo, $a->autorizada_por, $a->vigente_evento_id]);
        $this->assertNotNull($a->autorizada_at);
        $this->assertNull($a->revocada_at);
        $this->assertTrue(DobleModalidad::autorizado($this->evento->id));
        $this->assertSame(DobleModalidad::ORIGEN_BASE_DE_DATOS, DobleModalidad::origen($this->evento->id));
        $this->assertSame([$this->evento->id], DobleModalidad::eventosAutorizados());

        $mov = Movimiento::where('descripcion', 'like', 'Se autorizó la doble certificación%')->sole();
        $this->assertSame($this->superAdmin()->id, $mov->user_id);
        $this->assertSame(['autorizar_doble_modalidad', $this->evento->id, $a->id, 'panel', self::MOTIVO, false],
            [$mov->metadata['accion'], $mov->metadata['evento_id'], $mov->metadata['autorizacion_id'], $mov->metadata['origen'], $mov->metadata['motivo'], $mov->metadata['heredado_en_configuracion']]);
        $this->assertSame(['bases', 'emisiones_vigentes', 'documentos_dobles', 'programaciones_activas', 'borradores_en_revision'], array_keys($mov->metadata['impacto']));
    }

    public function test_admin_comercial_e_invitado_no_pueden_autorizar_ni_revocar(): void
    {
        foreach (['autorizar', 'revocar'] as $accion) {
            $this->accion($accion, null)->assertRedirect(route('login'));   // invitado (antes de iniciar sesión con nadie)
        }
        foreach (['autorizar', 'revocar'] as $accion) {
            $this->accion($accion, $this->admin())->assertForbidden();
            $this->accion($accion, $this->comercial())->assertForbidden();
        }
        // El servicio vuelve a comprobar el rol aunque alguien lo llame sin pasar por la ruta.
        $admin = $this->admin()->load('perfilOrganizador.rol');
        $this->assertSame(AutorizacionDobleModalidadException::SIN_PERMISO, $this->codigo(fn () => $this->servicio()->autorizar($this->evento, $admin, self::MOTIVO)));
        $this->assertSame(AutorizacionDobleModalidadException::SIN_PERMISO, $this->codigo(fn () => $this->servicio()->autorizar($this->evento, null, self::MOTIVO)));
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        $this->assertSame(AutorizacionDobleModalidadException::SIN_PERMISO, $this->codigo(fn () => $this->servicio()->revocar($this->evento, $admin, self::MOTIVO)));
        $this->assertSame(1, AutorizacionDobleModalidad::count());
        $this->assertTrue(DobleModalidad::autorizado($this->evento->id));
    }

    public function test_motivo_y_confirmacion_son_obligatorios(): void
    {
        $this->accion('autorizar', $this->superAdmin(), ['motivo' => self::MOTIVO])->assertSessionHasErrors('confirmacion');
        $this->accion('autorizar', $this->superAdmin(), ['motivo' => 'corto', 'confirmacion' => true])->assertSessionHasErrors('motivo');
        $this->accion('autorizar', $this->superAdmin(), ['motivo' => str_repeat('x', 501), 'confirmacion' => true])->assertSessionHasErrors('motivo');
        $this->assertSame(AutorizacionDobleModalidadException::MOTIVO_INVALIDO, $this->codigo(fn () => $this->servicio()->autorizar($this->evento, $this->super(), "   corto   \n   ")));
        $this->assertSame(0, AutorizacionDobleModalidad::count());
        $this->assertSame(0, Movimiento::where('descripcion', 'like', '%doble certificación%')->count());
    }

    // ── Autorización, revocación e intentos repetidos ─────────────────────────

    public function test_autorizar_dos_veces_no_duplica(): void
    {
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        $this->assertSame(AutorizacionDobleModalidadException::YA_AUTORIZADO, $this->codigo(fn () => $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO)));
        $this->accion('autorizar', $this->superAdmin())->assertSessionHas('error');
        $this->assertSame(1, AutorizacionDobleModalidad::count());
    }

    public function test_la_base_de_datos_garantiza_una_sola_autorizacion_vigente_por_evento(): void
    {
        $fila = ['evento_id' => $this->evento->id, 'estado' => 'vigente', 'origen' => 'panel', 'motivo' => self::MOTIVO, 'autorizada_por' => 1, 'autorizada_at' => now(), 'vigente_evento_id' => $this->evento->id];
        AutorizacionDobleModalidad::create($fila);
        $this->expectException(QueryException::class);
        AutorizacionDobleModalidad::create($fila);
    }

    public function test_eventos_no_validos_no_se_autorizan(): void
    {
        $legado = $this->evento('Evento histórico sintético', EventoCertificacion::ORIGEN_LEGADO);
        $archivado = $this->evento('Evento archivado sintético', estado: EventoCertificacion::ESTADO_ARCHIVADO);
        $eliminado = $this->evento('Evento eliminado sintético');
        $eliminado->delete();
        foreach ([$legado, $archivado, $eliminado->fresh()] as $e) {
            $this->assertSame(AutorizacionDobleModalidadException::EVENTO_NO_VALIDO, $this->codigo(fn () => $this->servicio()->autorizar($e, $this->super(), self::MOTIVO)));
        }
        $this->assertSame(0, AutorizacionDobleModalidad::count());
    }

    public function test_revocar_sin_participaciones_dobles_vuelve_la_regla_de_siempre_y_conserva_el_historial(): void
    {
        $lote = $this->sinDobles();
        $antes = $this->huella($lote);
        $this->accion('autorizar', $this->superAdmin())->assertSessionHas('success');
        $this->accion('revocar', $this->superAdmin(), ['motivo' => 'El evento ya no tendrá asistencia en dos modalidades', 'confirmacion' => true])->assertSessionHas('success');

        $a = AutorizacionDobleModalidad::sole();
        $this->assertSame(['revocada', null, $this->superAdmin()->id, 'El evento ya no tendrá asistencia en dos modalidades'], [$a->estado, $a->vigente_evento_id, $a->revocada_por, $a->motivo_revocacion]);
        $this->assertNotNull($a->revocada_at);
        $this->assertFalse(DobleModalidad::autorizado($this->evento->id));
        $this->assertSame($antes, $this->huella($lote), 'no toca participantes ni la base');
        $mov = Movimiento::where('descripcion', 'like', 'Se revocó la doble certificación%')->sole();
        $this->assertSame(['revocar_doble_modalidad', $a->id], [$mov->metadata['accion'], $mov->metadata['autorizacion_id']]);

        // Revocar otra vez: no hay nada vigente. Autorizar de nuevo: nueva fila, el historial conserva las dos.
        $this->assertSame(AutorizacionDobleModalidadException::NO_AUTORIZADO, $this->codigo(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));
        $this->servicio()->autorizar($this->evento, $this->super(), 'Se vuelve a autorizar por un segundo grupo');
        $this->assertSame(['vigente', 'revocada'], AutorizacionDobleModalidad::orderByDesc('id')->pluck('estado')->all());
        $historial = $this->servicio()->resumen($this->evento, $this->super())['historial'];
        $this->assertCount(2, $historial);
        $this->assertSame('vigente', $historial[0]['estado']);

        // Sin datos personales en la auditoría (ni nombres ni documentos de los participantes).
        foreach (Movimiento::where('descripcion', 'like', '%doble certificación%')->get() as $m) {
            $json = json_encode($m->metadata, JSON_UNESCAPED_UNICODE);
            $this->assertStringNotContainsString('7100003', $json);
            $this->assertStringNotContainsString('CARO', $json);
        }
    }

    // ── Bloqueos de la revocación ─────────────────────────────────────────────

    public function test_revocar_se_bloquea_con_participaciones_dobles_reales_pero_no_por_los_qa(): void
    {
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        // Contactos QA repetidos (uno por modalidad) no son participaciones dobles.
        $qa = $this->base($this->evento, [['QA UNO PRUEBA', 'C.C. 8000001', 'Presencial', true], ['QA UNO PRUEBA', 'C.C. 8000001', 'Virtual', true]], 'Base con QA');
        $this->assertSame(0, AutorizacionesDobleModalidad::documentosDobles($this->evento->id));

        $lote = $this->conDobles();
        $antes = $this->huella($lote);
        $this->assertSame(1, AutorizacionesDobleModalidad::documentosDobles($this->evento->id));
        $this->assertSame([AutorizacionDobleModalidadException::PARTICIPACIONES_DOBLES], $this->bloqueos(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));
        $this->accion('revocar', $this->superAdmin())->assertSessionHas('error');
        $this->assertTrue(DobleModalidad::autorizado($this->evento->id));
        $this->assertSame($antes, $this->huella($lote));

        // Una participación eliminada (lógicamente) ya no cuenta como doble.
        $lote->participantes()->where('modalidad', 'Virtual')->first()->delete();
        $this->assertSame([], $this->bloqueos(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));
        $this->assertNotNull($qa->fresh());
    }

    public function test_revocar_se_bloquea_con_una_programacion_activa(): void
    {
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        $lote = $this->sinDobles();
        $id = DB::table('cf_emisiones_programadas')->insertGetId(['lote_id' => $lote->id, 'estado' => 'pendiente', 'programada_para' => now()->addDay(), 'huella' => str_repeat('a', 64),
            'huella_detalle' => '{}', 'programada_por' => $this->superAdmin()->id, 'created_at' => now(), 'updated_at' => now()]);
        $this->assertSame([AutorizacionDobleModalidadException::PROGRAMACION_ACTIVA], $this->bloqueos(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));

        DB::table('cf_emisiones_programadas')->where('id', $id)->update(['estado' => 'completada']);
        $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO);
        $this->assertFalse(DobleModalidad::autorizado($this->evento->id));
    }

    public function test_revocar_se_bloquea_con_un_borrador_en_revision_asociado_al_evento(): void
    {
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        $otro = $this->evento('Otro evento sintético');
        $crear = fn (array $evento, string $estado = Importacion::EN_REVISION) => Importacion::create(['usuario_id' => $this->superAdmin()->id, 'estado' => $estado, 'archivo_hash' => str_repeat('b', 64),
            'correcciones' => ['evento' => $evento]]);
        $crear(['decision' => 'asociar', 'evento_id' => $otro->id]);                         // otro evento: no bloquea
        $crear(['decision' => 'crear', 'evento_id' => null, 'nombre' => 'Nuevo']);            // crear: no bloquea
        $crear(['decision' => 'asociar', 'evento_id' => $this->evento->id], Importacion::RECHAZADO);   // ya cerrado: no bloquea
        $this->assertSame([], $this->servicio()->diagnostico($this->evento)['bloqueos_revocar']);

        $b = $crear(['decision' => 'asociar', 'evento_id' => $this->evento->id]);
        $this->assertSame([AutorizacionDobleModalidadException::BORRADOR_EN_REVISION], $this->bloqueos(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));
        $b->update(['estado' => Importacion::RECHAZADO]);
        $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO);
        $this->assertFalse(DobleModalidad::autorizado($this->evento->id));
    }

    // ── Fuentes de la autorización ────────────────────────────────────────────

    public function test_fuentes_ninguna_base_de_datos_heredada_y_ambas(): void
    {
        $bd = $this->evento('Evento autorizado en base de datos');
        $heredado = $this->evento('Evento autorizado por la variable');
        $ambos = $this->evento('Evento autorizado por las dos fuentes');
        config(['credential_flow.doble_modalidad.eventos' => $heredado->id.','.$ambos->id]);
        $this->servicio()->autorizar($bd, $this->super(), self::MOTIVO);
        $this->servicio()->autorizar($ambos, $this->super(), self::MOTIVO, AutorizacionDobleModalidad::ORIGEN_HEREDADA);

        $this->assertSame([false, null], [DobleModalidad::autorizado($this->evento->id), DobleModalidad::origen($this->evento->id)]);
        $this->assertSame([true, DobleModalidad::ORIGEN_BASE_DE_DATOS], [DobleModalidad::autorizado($bd->id), DobleModalidad::origen($bd->id)]);
        $this->assertSame([true, DobleModalidad::ORIGEN_HEREDADO], [DobleModalidad::autorizado($heredado->id), DobleModalidad::origen($heredado->id)]);
        $this->assertSame([true, DobleModalidad::ORIGEN_AMBOS], [DobleModalidad::autorizado($ambos->id), DobleModalidad::origen($ambos->id)]);
        $this->assertSame([$bd->id, $heredado->id, $ambos->id], DobleModalidad::eventosAutorizados());
        $this->assertSame([$heredado->id, $ambos->id], DobleModalidad::eventosHeredados());

        // Con la variable inválida, la base de datos sigue valiendo y la heredada no autoriza nada (fallo cerrado de siempre).
        config(['credential_flow.doble_modalidad.eventos' => $heredado->id.', '.$ambos->id]);
        $this->assertSame([true, false, true], [DobleModalidad::autorizado($bd->id), DobleModalidad::autorizado($heredado->id), DobleModalidad::autorizado($ambos->id)]);
    }

    public function test_sin_la_tabla_solo_cuenta_la_configuracion_heredada_y_el_panel_no_escribe(): void
    {
        Schema::drop('cf_eventos_doble_modalidad');
        config(['credential_flow.doble_modalidad.eventos' => (string) $this->evento->id]);
        $this->assertTrue(DobleModalidad::autorizado($this->evento->id));
        $this->assertSame(DobleModalidad::ORIGEN_HEREDADO, DobleModalidad::origen($this->evento->id));
        $this->assertNull(DobleModalidad::vigente($this->evento->id));
        $this->assertSame(AutorizacionDobleModalidadException::NO_DISPONIBLE, $this->codigo(fn () => $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO)));
        $this->assertFalse($this->servicio()->resumen($this->evento, $this->super())['disponible']);
        $this->actingAs($this->superAdmin())->get(route('credential-flow.eventos.show', $this->evento))->assertOk();
    }

    // ── Transición del evento heredado (como el #637) ─────────────────────────

    public function test_evento_heredado_con_participaciones_dobles_se_registra_con_el_comando_y_no_se_puede_revocar(): void
    {
        config(['credential_flow.doble_modalidad.eventos' => (string) $this->evento->id]);
        $lote = $this->conDobles();
        $antes = $this->huella($lote);

        // Desde el panel no se registra (lo hace el procedimiento de transición) y no hay nada que revocar.
        $this->assertContains(AutorizacionDobleModalidadException::CONFIGURACION_HEREDADA, $this->bloqueos(fn () => $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO)));
        $this->assertSame(AutorizacionDobleModalidadException::NO_AUTORIZADO, $this->codigo(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));

        // Comando: por defecto solo simula.
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id])->expectsOutputToContain('SIMULACIÓN')->assertExitCode(0);
        $this->assertSame(0, AutorizacionDobleModalidad::count());
        // Sin super-admin o sin motivo no escribe.
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--ejecutar' => true])->assertExitCode(1);
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id, '--usuario' => $this->superAdmin()->id, '--ejecutar' => true])->assertExitCode(1);
        $this->assertSame(0, AutorizacionDobleModalidad::count());

        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id, '--usuario' => $this->superAdmin()->id, '--motivo' => 'Registro de la autorización heredada del servidor', '--ejecutar' => true])
            ->expectsOutputToContain('REGISTRADA')->assertExitCode(0);
        $a = AutorizacionDobleModalidad::sole();
        $this->assertSame(['vigente', AutorizacionDobleModalidad::ORIGEN_HEREDADA, $this->superAdmin()->id], [$a->estado, $a->origen, $a->autorizada_por]);
        $this->assertSame(DobleModalidad::ORIGEN_AMBOS, DobleModalidad::origen($this->evento->id));
        $mov = Movimiento::where('descripcion', 'like', 'Se autorizó la doble certificación%')->sole();
        $this->assertSame([$this->superAdmin()->id, 'configuracion_heredada', true], [$mov->user_id, $mov->metadata['origen'], $mov->metadata['heredado_en_configuracion']]);

        // Repetirlo no duplica.
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id, '--usuario' => $this->superAdmin()->id, '--motivo' => 'Registro repetido por error', '--ejecutar' => true])->assertExitCode(1);
        $this->assertSame(1, AutorizacionDobleModalidad::count());

        // Revocar: bloqueado por las participaciones dobles y por la variable heredada. Al retirar la variable sigue autorizado por la base de datos.
        $b = $this->bloqueos(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO));
        $this->assertContains(AutorizacionDobleModalidadException::PARTICIPACIONES_DOBLES, $b);
        $this->assertContains(AutorizacionDobleModalidadException::CONFIGURACION_HEREDADA, $b);
        config(['credential_flow.doble_modalidad.eventos' => null]);
        $this->assertSame([true, DobleModalidad::ORIGEN_BASE_DE_DATOS], [DobleModalidad::autorizado($this->evento->id), DobleModalidad::origen($this->evento->id)]);
        $this->assertSame([AutorizacionDobleModalidadException::PARTICIPACIONES_DOBLES], $this->bloqueos(fn () => $this->servicio()->revocar($this->evento, $this->super(), self::MOTIVO)));
        $this->assertSame($antes, $this->huella($lote), 'la base y sus participaciones no cambian en ningún paso');
    }

    public function test_el_comando_rechaza_un_evento_que_no_esta_en_la_variable_heredada(): void
    {
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id])->expectsOutputToContain('NO_HEREDADO')->assertExitCode(1);
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $this->evento->id, '--usuario' => $this->superAdmin()->id, '--motivo' => self::MOTIVO, '--ejecutar' => true])->assertExitCode(1);
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => 999999])->assertExitCode(1);
        $this->assertSame(0, AutorizacionDobleModalidad::count());
    }

    // ── Asociación y desasociación ────────────────────────────────────────────

    public function test_una_base_con_participaciones_dobles_solo_se_mueve_entre_eventos_autorizados(): void
    {
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        $noAutorizado = $this->evento('Evento sintético sin autorizar');
        $autorizado = $this->evento('Otro evento sintético autorizado');
        $this->servicio()->autorizar($autorizado, $this->super(), self::MOTIVO);
        $lote = $this->conDobles();
        $mover = fn (?int $evento) => $this->actingAs($this->admin())->from(route('credential-flow.eventos.show', $this->evento))->put(route('credential-flow.lotes.evento', $lote), ['evento_id' => $evento]);

        $mover($noAutorizado->id)->assertSessionHas('error');
        $this->assertSame($this->evento->id, $lote->fresh()->evento_id);
        $mover(null)->assertSessionHas('error');
        $this->assertSame($this->evento->id, $lote->fresh()->evento_id);
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.evento.desde-base', $lote), ['evento_id' => $noAutorizado->id])->assertSessionHas('error');
        $this->assertSame($this->evento->id, $lote->fresh()->evento_id);
        // «Crear evento desde la base» con la base ya asociada no cambia nada (comportamiento de siempre) y no crea eventos.
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.evento.desde-base', $lote), ['crear_nuevo' => true]);
        $this->assertSame($this->evento->id, $lote->fresh()->evento_id);
        $this->assertSame(3, EventoCertificacion::count(), 'no se creó un evento nuevo');

        $mover($autorizado->id)->assertSessionHas('success');
        $this->assertSame($autorizado->id, $lote->fresh()->evento_id);

        // Una base sin participaciones dobles se mueve y se desasocia como siempre.
        $libre = $this->sinDobles();
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.evento', $libre), ['evento_id' => $noAutorizado->id])->assertSessionHas('success');
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.evento', $libre), ['evento_id' => null])->assertSessionHas('success');
        $this->assertNull($libre->fresh()->evento_id);
    }

    // ── Concurrencia: la autorización se relee con bloqueo al guardar ─────────

    public function test_un_participante_doble_no_se_guarda_si_la_autorizacion_se_revoca_entre_validar_y_guardar(): void
    {
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);
        $lote = $this->base($this->evento, [['ANA FICTICIA DOBLE', 'C.C. 7100001', 'Presencial', false]]);
        $alta = fn () => $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))
            ->post(route('credential-flow.participantes.store', $lote), ['nombre_completo' => 'ANA FICTICIA DOBLE', 'documento' => 'C.C. 7100001', 'modalidad' => 'Virtual']);

        // La validación ve la autorización; justo antes de guardar, otra sesión la revoca (se simula al crear la fila).
        $carrera = true;
        Participante::creating(function () use (&$carrera) {
            if (! $carrera) {
                return;
            }
            $carrera = false;
            AutorizacionDobleModalidad::query()->where('vigente_evento_id', $this->evento->id)->update(['estado' => 'revocada', 'vigente_evento_id' => null, 'revocada_por' => 1, 'revocada_at' => now(), 'motivo_revocacion' => 'Carrera simulada']);
        });
        $alta()->assertSessionHasErrors('documento');
        $this->assertSame(1, $lote->participantes()->count(), 'todo o nada: no quedó la segunda participación');

        // La revocación simulada ocurrió DENTRO de la transacción del alta y se deshizo con ella: la autorización sigue vigente y la misma alta funciona.
        $this->assertTrue(DobleModalidad::autorizado($this->evento->id));
        $alta()->assertSessionHasNoErrors();
        $this->assertSame(2, $lote->participantes()->count());
        $this->assertSame(DobleModalidad::ORIGEN_BASE_DE_DATOS, Movimiento::where('descripcion', 'like', 'Se agregó manualmente%')->latest('id')->first()->metadata['doble_modalidad']['origen']);
    }

    // ── Pantallas ─────────────────────────────────────────────────────────────

    public function test_la_ficha_muestra_el_estado_a_todos_y_el_detalle_solo_al_super_admin(): void
    {
        $this->conDobles();
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);

        $this->actingAs($this->admin())->get(route('credential-flow.eventos.show', $this->evento))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('doble_modalidad.autorizado', true)->where('doble_modalidad.origen', 'base_de_datos')->where('doble_modalidad.puede_gestionar', false)
                ->missing('doble_modalidad.historial')->missing('doble_modalidad.impacto')->missing('doble_modalidad.bloqueos_revocar'));

        $this->actingAs($this->superAdmin())->get(route('credential-flow.eventos.show', $this->evento))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->where('doble_modalidad.puede_gestionar', true)->where('doble_modalidad.impacto.documentos_dobles', 1)
                ->where('doble_modalidad.bloqueos_revocar.0.code', AutorizacionDobleModalidadException::PARTICIPACIONES_DOBLES)
                ->where('doble_modalidad.bloqueos_autorizar.0.code', AutorizacionDobleModalidadException::YA_AUTORIZADO)
                ->has('doble_modalidad.historial', 1)->where('doble_modalidad.historial.0.motivo', self::MOTIVO));
    }

    /** Transición del #637 con datos ficticios: la ficha muestra el estado REAL en los cinco casos. */
    public function test_la_ficha_muestra_el_estado_real_en_los_cinco_casos_de_transicion(): void
    {
        $soloEnv = $this->evento('Evento sintético solo por la variable');
        $soloBd = $this->evento('Evento sintético solo en base de datos');
        $ambas = $this->evento('Evento sintético por las dos fuentes');
        $revocadoConEnv = $this->evento('Evento sintético revocado en base de datos pero en la variable');
        $ninguna = $this->evento('Evento sintético sin autorización');

        $this->servicio()->autorizar($soloBd, $this->super(), self::MOTIVO);
        $this->servicio()->autorizar($revocadoConEnv, $this->super(), self::MOTIVO);
        $this->servicio()->revocar($revocadoConEnv, $this->super(), 'Revocada en el panel antes de entrar en la variable');
        config(['credential_flow.doble_modalidad.eventos' => implode(',', [$soloEnv->id, $ambas->id, $revocadoConEnv->id])]);
        $this->artisan('credential-flow:doble-modalidad-heredar', ['evento' => $ambas->id, '--usuario' => $this->superAdmin()->id, '--motivo' => 'Registro sintético de la autorización heredada', '--ejecutar' => true])->assertExitCode(0);

        $esperado = [
            $soloEnv->id => [true, DobleModalidad::ORIGEN_HEREDADO, []],
            $soloBd->id => [true, DobleModalidad::ORIGEN_BASE_DE_DATOS, ['vigente']],
            $ambas->id => [true, DobleModalidad::ORIGEN_AMBOS, ['vigente']],
            $revocadoConEnv->id => [true, DobleModalidad::ORIGEN_HEREDADO, ['revocada']],
            $ninguna->id => [false, null, []],
        ];
        foreach ($esperado as $id => [$autorizado, $origen, $historial]) {
            foreach ([$this->admin(), $this->superAdmin()] as $u) {
                $this->actingAs($u)->get(route('credential-flow.eventos.show', $id))->assertOk()->assertInertia(function (Assert $p) use ($autorizado, $origen, $historial, $u, $id) {
                    $d = $p->toArray()['props']['doble_modalidad'];
                    $this->assertSame([$autorizado, $origen], [$d['autorizado'], $d['origen']], "evento {$id}");
                    if ($u->correo_principal === $this->superAdmin()->correo_principal) {
                        $this->assertSame($historial, array_column($d['historial'], 'estado'), "evento {$id}");
                    } else {
                        $this->assertArrayNotHasKey('historial', $d);
                    }
                });
            }
        }
        // El evento revocado en la base de datos pero listado en la variable: ni se revoca otra vez ni se autoriza desde el panel.
        $this->assertSame(AutorizacionDobleModalidadException::NO_AUTORIZADO, $this->codigo(fn () => $this->servicio()->revocar($revocadoConEnv, $this->super(), self::MOTIVO)));
        $this->assertContains(AutorizacionDobleModalidadException::CONFIGURACION_HEREDADA, $this->bloqueos(fn () => $this->servicio()->autorizar($revocadoConEnv, $this->super(), self::MOTIVO)));
    }

    public function test_el_listado_de_eventos_marca_los_autorizados(): void
    {
        $otro = $this->evento('Evento sintético sin autorizar');
        $heredado = $this->evento('Evento sintético heredado');
        config(['credential_flow.doble_modalidad.eventos' => (string) $heredado->id]);
        $this->servicio()->autorizar($this->evento, $this->super(), self::MOTIVO);

        $this->actingAs($this->admin())->get(route('credential-flow.eventos.index'))->assertOk()->assertInertia(function (Assert $p) use ($otro, $heredado) {
            $marcas = collect($p->toArray()['props']['eventos'])->pluck('doble_modalidad', 'id')->all();
            $this->assertSame([true, false, true], [$marcas[$this->evento->id], $marcas[$otro->id], $marcas[$heredado->id]]);
        });
    }
}
