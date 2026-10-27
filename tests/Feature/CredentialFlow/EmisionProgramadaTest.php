<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Programacion\EjecucionAutorizada;
use App\Support\CredentialFlow\Programacion\EjecutorProgramado;
use App\Support\CredentialFlow\Programacion\HuellaProgramacion;
use App\Support\CredentialFlow\Programacion\ProgramacionException;
use App\Support\CredentialFlow\Programacion\ServicioProgramacion;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\DB;

/**
 * Emisión programada — P1 (modelo y servicio; sin ejecutor, sin interfaz y sin cron). Reloj simulado: «ahora» es el martes 27 de octubre de 2026, 10:00 de
 * Colombia. Datos 100 % ficticios. Nada de esto emite certificados salvo las pruebas de compatibilidad, que emiten en la base de pruebas en memoria.
 */
class EmisionProgramadaTest extends ProgramacionTestCase
{
    // ── Programación normal ───────────────────────────────────────────────────

    public function test_programar_guarda_la_hora_de_colombia_quien_lo_hizo_lo_esperado_y_la_huella_sin_datos_personales(): void
    {
        $lote = $this->base(3);
        $p = $this->programar($lote, '2026-10-28T08:30');

        $this->assertSame(EmisionProgramada::PENDIENTE, $p->estado);
        $this->assertSame($lote->id, $p->lote_activa, 'ocupa la base');
        $this->assertSame('2026-10-28 08:30:00', $p->getRawOriginal('programada_para'), 'se guarda la hora de Colombia tal cual');
        $this->assertSame('America/Bogota', $p->zona_horaria);
        $this->assertSame([3, 2], [$p->oficiales_esperados, $p->qa_esperados]);
        $this->assertSame($this->admin()->id, $p->programada_por);
        $this->assertSame($this->admin()->id, $p->confirmada_por);
        $this->assertSame(HuellaProgramacion::PARTES, array_keys($p->huella_detalle));
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $p->huella);
        $this->assertSame([], $this->servicio()->cambios($p));

        // Ningún dato personal en la fila: ni nombres, ni documentos, ni correos.
        $fila = json_encode(DB::table('cf_emisiones_programadas')->find($p->id), JSON_UNESCAPED_UNICODE);
        foreach (['PERSONA NÚMERO', '20.000.001', 'QA PROGRAMADA', '72000001', '@example.test'] as $dato) {
            $this->assertStringNotContainsString($dato, $fila, $dato);
        }
        $this->assertSame(0, Emision::count(), 'programar no emite nada');
        $mov = Movimiento::where('descripcion', 'Se programó la emisión de una base de Credential Flow')->sole();
        $this->assertSame([$this->admin()->id, $lote->id, $p->id, '2026-10-28 08:30'], [$mov->metadata['usuario_id'], $mov->metadata['lote_id'], $mov->metadata['programacion_id'], $mov->metadata['programada_para']]);
    }

    public function test_el_super_admin_tambien_puede_programar_y_el_rol_sin_acceso_no(): void
    {
        $this->assertSame(EmisionProgramada::PENDIENTE, $this->programar($this->base(1), '2026-10-28 08:00', $this->superAdmin())->estado);
        $otra = $this->base(1);
        $this->assertSame(ProgramacionException::SIN_PERMISO, $this->codigo(fn () => $this->programar($otra, '2026-10-28 08:00', $this->comercial())));
        $this->assertNull($this->servicio()->activaDe($otra->id));
    }

    public function test_con_el_interruptor_apagado_no_se_programa_ni_reprograma_pero_si_se_cancela(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote);
        config(['credential_flow.programacion.activa' => false]);

        $this->assertSame(ProgramacionException::DESACTIVADA, $this->codigo(fn () => $this->programar($this->base(1))));
        $this->assertSame(ProgramacionException::DESACTIVADA, $this->codigo(fn () => $this->servicio()->reprogramar($p, '2026-10-29 08:00', $this->admin())));
        $this->assertSame(ProgramacionException::DESACTIVADA, $this->codigo(fn () => $this->servicio()->reconfirmar($p, $this->admin())));
        $this->assertSame(EmisionProgramada::CANCELADA, $this->servicio()->cancelar($p, $this->admin(), 'Se apagó la programación')->estado, 'nunca queda una base bloqueada sin salida');
        $this->assertSame(1, EmisionProgramada::count());
    }

    public function test_el_interruptor_esta_apagado_por_defecto(): void
    {
        // El archivo de configuración tal cual, sin la variable en el entorno: apagado.
        $this->assertFalse(getenv('CREDENTIAL_FLOW_PROGRAMACION_ACTIVA'));
        $this->assertFalse((require base_path('config/credential_flow.php'))['programacion']['activa']);
    }

    // ── Fechas y zona horaria ─────────────────────────────────────────────────

    public function test_fechas_pasadas_muy_proximas_muy_lejanas_invalidas_o_ambiguas_se_rechazan(): void
    {
        $lote = $this->base(1);
        $casos = [
            '2026-10-27 09:59' => ProgramacionException::FECHA_PASADA,
            '2026-10-27 10:00' => ProgramacionException::FECHA_PASADA,
            '2026-10-27 10:04' => ProgramacionException::FECHA_MUY_PRONTO,
            '2027-01-26 10:01' => ProgramacionException::FECHA_MUY_LEJANA,
            '2026-02-30 10:00' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28 24:00' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28 08:60' => ProgramacionException::FECHA_INVALIDA,
            '28/10/2026 08:00' => ProgramacionException::FECHA_INVALIDA,
            '10/28/2026 08:00' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28 8:00' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28 08:00 PM' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28T08:00Z' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28T08:00-05:00' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28 08:00:30' => ProgramacionException::FECHA_INVALIDA,
            '2026-10-28' => ProgramacionException::FECHA_INVALIDA,
            'mañana a las 8' => ProgramacionException::FECHA_INVALIDA,
            '' => ProgramacionException::FECHA_INVALIDA,
        ];
        foreach ($casos as $fecha => $esperado) {
            $this->assertSame($esperado, $this->codigo(fn () => $this->programar($lote, (string) $fecha)), "«{$fecha}»");
        }
        $this->assertSame(ProgramacionException::FECHA_INVALIDA, $this->codigo(fn () => $this->servicio()->programar($lote, ['2026-10-28 08:00'], $this->admin())), 'un arreglo');
        $this->assertSame(0, EmisionProgramada::count());

        // Los límites exactos sí valen: 5 minutos y 90 días; y con «:00» o con la T del formulario.
        $this->assertSame('2026-10-27 10:05:00', $this->programar($lote, '2026-10-27 10:05')->getRawOriginal('programada_para'));
        $otra = $this->base(1);
        $this->assertSame('2027-01-25 10:00:00', $this->programar($otra, '2027-01-25T10:00:00')->getRawOriginal('programada_para'));
    }

    public function test_la_hora_se_interpreta_en_colombia_aunque_php_este_en_otra_zona_y_no_se_programa_si_la_aplicacion_no_esta_en_colombia(): void
    {
        $lote = $this->base(1);
        $original = date_default_timezone_get();
        date_default_timezone_set('UTC');
        try {
            // A las 10:00 de Colombia son las 15:00 UTC: «hoy a las 12:00» es futuro en Colombia aunque en UTC ya hubiera pasado.
            $p = $this->programar($lote, '2026-10-27 12:00');
            $this->assertSame('2026-10-27 12:00:00', $p->getRawOriginal('programada_para'));
        } finally {
            date_default_timezone_set($original);
        }

        config(['app.timezone' => 'UTC']);
        $this->assertSame(ProgramacionException::ZONA_NO_CONFIGURADA, $this->codigo(fn () => $this->programar($this->base(1))));
    }

    // ── Una sola programación activa por base ─────────────────────────────────

    public function test_una_sola_programacion_activa_por_base_y_al_cancelar_o_terminar_se_puede_volver_a_programar(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote);
        $this->assertSame(ProgramacionException::YA_EXISTE_ACTIVA, $this->codigo(fn () => $this->programar($lote, '2026-10-29 08:00', $this->superAdmin())));

        $this->servicio()->cancelar($p, $this->admin(), 'Cambio de fecha del evento');
        $q = $this->programar($lote, '2026-10-29 08:00');
        $this->assertSame($lote->id, $q->lote_activa);

        // Una terminada (completada o fallida) ya no ocupa la base.
        $q->update(['estado' => EmisionProgramada::COMPLETADA, 'lote_activa' => null]);
        $this->assertSame(EmisionProgramada::PENDIENTE, $this->programar($lote, '2026-10-30 08:00')->estado);
        $this->assertSame(3, EmisionProgramada::where('lote_id', $lote->id)->count());
    }

    public function test_la_base_de_datos_impide_dos_programaciones_activas_de_la_misma_base(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote);
        $fila = collect($p->getAttributes())->except(['id'])->all();
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('cf_emisiones_programadas')->insert($fila);
    }

    public function test_si_otro_administrador_programa_entre_la_validacion_y_el_bloqueo_no_se_crea_una_segunda(): void
    {
        $lote = $this->base(1);
        // Simula al segundo administrador: su programación se guarda mientras la primera ya validó la base y aún no la bloqueó.
        ServicioProgramacion::$antesDeBloquear = function () use ($lote) {
            ServicioProgramacion::$antesDeBloquear = null;
            $this->programar($lote, '2026-10-29 09:00', $this->superAdmin());
        };

        $this->assertSame(ProgramacionException::YA_EXISTE_ACTIVA, $this->codigo(fn () => $this->programar($lote, '2026-10-28 08:00', $this->admin())));
        $this->assertSame(1, EmisionProgramada::where('lote_id', $lote->id)->count());
        $this->assertSame('2026-10-29 09:00:00', EmisionProgramada::sole()->getRawOriginal('programada_para'), 'queda la del que llegó primero');
    }

    public function test_si_la_base_cambia_entre_la_validacion_y_el_bloqueo_no_se_guarda_nada(): void
    {
        $lote = $this->base(2);
        ServicioProgramacion::$antesDeBloquear = fn () => $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'PERSONA CORREGIDA']);

        $this->assertSame(ProgramacionException::BASE_CAMBIO, $this->codigo(fn () => $this->programar($lote)));
        $this->assertSame(0, EmisionProgramada::count());
    }

    // ── Cancelar y reprogramar ────────────────────────────────────────────────

    public function test_cancelar_exige_motivo_registra_quien_y_libera_la_base(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote);
        foreach (['', '   ', 'no', str_repeat('x', 256), null, ['motivo']] as $motivo) {
            $this->assertSame(ProgramacionException::MOTIVO_INVALIDO, $this->codigo(fn () => $this->servicio()->cancelar($p, $this->admin(), $motivo)));
        }
        $this->assertSame(ProgramacionException::SIN_PERMISO, $this->codigo(fn () => $this->servicio()->cancelar($p, $this->comercial(), 'Sin permiso')));

        $c = $this->servicio()->cancelar($p, $this->superAdmin(), '  El evento se aplazó  ');
        $this->assertSame([EmisionProgramada::CANCELADA, null, $this->superAdmin()->id, 'El evento se aplazó', 2], [$c->estado, $c->lote_activa, $c->cancelada_por, $c->motivo_cancelacion, $c->version]);
        $this->assertNotNull($c->cancelada_at);
        $this->assertNull($this->servicio()->activaDe($lote->id));
        $this->assertSame(ProgramacionException::NO_MODIFICABLE, $this->codigo(fn () => $this->servicio()->cancelar($c, $this->admin(), 'Otra vez')));
    }

    public function test_una_programacion_ejecutandose_o_terminada_no_se_cancela_ni_reprograma(): void
    {
        $p = $this->programar($this->base(1));
        foreach ([EmisionProgramada::EJECUTANDO, EmisionProgramada::COMPLETADA, EmisionProgramada::FALLIDA] as $estado) {
            $p->update(['estado' => $estado]);
            $this->assertSame(ProgramacionException::NO_MODIFICABLE, $this->codigo(fn () => $this->servicio()->cancelar($p, $this->admin(), 'Intento')), $estado);
            $this->assertSame(ProgramacionException::NO_MODIFICABLE, $this->codigo(fn () => $this->servicio()->reprogramar($p, '2026-10-29 08:00', $this->admin())), $estado);
            $this->assertSame(ProgramacionException::NO_MODIFICABLE, $this->codigo(fn () => $this->servicio()->reconfirmar($p, $this->admin())), $estado);
        }
    }

    public function test_reprogramar_cambia_la_hora_vuelve_a_aprobar_y_respeta_la_version_que_vio_el_administrador(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote);
        $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'PERSONA CORREGIDA ANTES DE REPROGRAMAR']);
        $this->assertSame(['participantes'], $this->servicio()->cambios($p));

        $this->assertSame(ProgramacionException::CAMBIO_CONCURRENTE, $this->codigo(fn () => $this->servicio()->reprogramar($p, '2026-10-29 07:15', $this->admin(), 99)));
        $this->assertSame(ProgramacionException::FECHA_PASADA, $this->codigo(fn () => $this->servicio()->reprogramar($p, '2026-10-26 07:15', $this->admin(), 1)));

        $r = $this->servicio()->reprogramar($p, '2026-10-29 07:15', $this->superAdmin(), 1);
        $this->assertSame(['2026-10-29 07:15:00', 1, 2, $this->superAdmin()->id, $this->admin()->id], [$r->getRawOriginal('programada_para'), $r->reprogramaciones, $r->version, $r->confirmada_por, $r->programada_por]);
        $this->assertSame([], $this->servicio()->cambios($r), 'reprogramar aprueba los datos de ahora');
        $this->assertSame(1, EmisionProgramada::count(), 'es la misma programación');
    }

    // ── Cambios después de programar: exigir reconfirmación ───────────────────

    public function test_cambios_en_participantes_documentos_datos_de_la_base_y_plantilla_se_detectan(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote);
        $real = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->first();

        $real->update(['documento' => 'C.C. 99.999.999', 'documento_clave' => Texto::claveDocumento('C.C. 99.999.999')]);
        $this->assertSame(['participantes'], $this->servicio()->cambios($p), 'un documento');
        $p = $this->servicio()->reconfirmar($p, $this->admin());

        $this->participante($lote, 'Persona Agregada Después', 'C.C. 30.000.001');
        $this->assertSame(['participantes'], $this->servicio()->cambios($p), 'un participante nuevo');
        $p = $this->servicio()->reconfirmar($p, $this->admin());

        $real->update(['elegibilidad' => 'no_elegible']);
        $this->assertSame(['participantes'], $this->servicio()->cambios($p), 'la elegibilidad');
        $real->update(['elegibilidad' => 'elegible']);
        $this->assertSame([], $this->servicio()->cambios($p), 'deshacer el cambio vuelve a la huella aprobada');

        $lote->update(['datos_comunes' => [...$lote->datos_comunes, 'intensidad_horaria' => '40 horas']]);
        $this->assertSame(['base'], $this->servicio()->cambios($p), 'los datos comunes');
        $p = $this->servicio()->reconfirmar($p, $this->admin());

        $plantilla = Plantilla::find($lote->plantilla_id);
        $diseno = $plantilla->diseno;
        $diseno['elements'][0]['fontSize'] = 18;
        $plantilla->update(['diseno' => $diseno]);
        $this->assertSame(['plantilla'], $this->servicio()->cambios($p), 'el diseño de la plantilla');
        $p = $this->servicio()->reconfirmar($p, $this->admin());
        $this->assertSame([[], 5], [$this->servicio()->cambios($p), $p->version], 'programar + 4 reconfirmaciones');
    }

    public function test_cambios_en_la_variante_qa_y_en_la_vista_previa_se_detectan(): void
    {
        config(['credential_flow.emision.exige_vista_previa' => true]);
        $lote = $this->base(2);
        $vista = app(ServicioVistaPrevia::class);
        $vista->registrarGenerada($lote->fresh());
        $vista->confirmar($lote->fresh());
        $p = $this->programar($lote->fresh());

        // La vista previa: volver a generarla con la plantilla actual conserva la confirmación; anularla es un cambio.
        $lote->fresh()->forceFill(['vista_previa_confirmada_at' => null])->save();
        $this->assertSame(['vista_previa'], $this->servicio()->cambios($p));
        $this->assertSame(EmisionException::VISTA_PREVIA_PENDIENTE, $this->codigo(fn () => $this->servicio()->reconfirmar($p, $this->admin())), 'sin vista previa confirmada no se reconfirma');
        $vista->confirmar($lote->fresh());
        $p = $this->servicio()->reconfirmar($p, $this->admin());

        // La variante QA: quitarla es un cambio y, mientras falte, no se puede reconfirmar.
        app(ServicioQa::class)->asignarVariante(Plantilla::find($lote->plantilla_id), null);
        $this->assertSame(['plantilla', 'variante_qa'], $this->servicio()->cambios($p), 'la plantilla oficial guarda el enlace a su variante');
        $this->assertSame(EmisionException::VARIANTE_QA_FALTANTE, $this->codigo(fn () => $this->servicio()->reconfirmar($p, $this->admin())));
    }

    public function test_reconfirmar_exige_que_la_hora_siga_siendo_futura(): void
    {
        $p = $this->programar($this->base(1), '2026-10-27 10:30');
        $this->travel(31)->minutes();
        $this->assertSame(ProgramacionException::FECHA_PASADA, $this->codigo(fn () => $this->servicio()->reconfirmar($p, $this->admin())));
    }

    // ── Validaciones de la base al programar ──────────────────────────────────

    public function test_sin_variante_qa_no_se_programa_aunque_no_haya_pruebas_pendientes(): void
    {
        $lote = $this->base(2, conVariante: false);
        $this->assertSame(EmisionException::VARIANTE_QA_FALTANTE, $this->codigo(fn () => $this->programar($lote)));
        // Decisión aprobada: la emisión programada exige SIEMPRE la variante QA válida, también en una base sin contactos QA.
        $sinQa = $this->base(2, conVariante: false, conQa: false);
        $this->assertSame(EmisionException::VARIANTE_QA_FALTANTE, $this->codigo(fn () => $this->programar($sinQa)));
        $this->assertSame(0, EmisionProgramada::count());
    }

    public function test_solo_una_base_activa_se_programa(): void
    {
        foreach ([Lote::BORRADOR, Lote::ARCHIVADA, Lote::PRUEBA] as $estado) {
            $lote = $this->base(1);
            $lote->update(['estado_operativo' => $estado]);
            $this->assertSame(ProgramacionException::BASE_NO_ACTIVA, $this->codigo(fn () => $this->programar($lote->fresh())), $estado);
        }
        $borrada = $this->base(1);
        $borrada->delete();
        $this->assertSame(ProgramacionException::BASE_NO_DISPONIBLE, $this->codigo(fn () => $this->programar($borrada)));

        // Y si deja de estar activa después de programar, tampoco se reconfirma ni se reprograma (cancelar sí).
        $lote = $this->base(1);
        $p = $this->programar($lote);
        $lote->update(['estado_operativo' => Lote::ARCHIVADA]);
        $this->assertSame(['base'], $this->servicio()->cambios($p));
        $this->assertSame(ProgramacionException::BASE_NO_ACTIVA, $this->codigo(fn () => $this->servicio()->reconfirmar($p, $this->admin())));
        $this->assertSame(ProgramacionException::BASE_NO_ACTIVA, $this->codigo(fn () => $this->servicio()->reprogramar($p, '2026-10-29 08:00', $this->admin())));
        $this->assertSame(EmisionProgramada::CANCELADA, $this->servicio()->cancelar($p, $this->admin(), 'Base archivada')->estado);
    }

    public function test_una_base_sin_pendientes_o_que_no_pasa_la_prevalidacion_no_se_programa(): void
    {
        $sinNadie = $this->loteCon(0);
        $this->assertSame(EmisionException::SIN_PENDIENTES, $this->codigo(fn () => $this->programar($sinNadie)));

        // Un nombre que no cabe en su caja: la misma prevalidación de la emisión real, antes de aceptar la hora.
        $lote = $this->base(1);
        $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => str_repeat('NOMBRE MUY LARGO ', 30)]);
        $this->assertSame(EmisionException::PREVALIDACION_FALLIDA, $this->codigo(fn () => $this->programar($lote->fresh())));
        $this->assertSame(0, EmisionProgramada::count());
    }

    // ── El candado de la emisión manual ───────────────────────────────────────

    public function test_con_una_programacion_pendiente_ninguna_emision_manual_sale_por_ninguna_via(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote);
        $real = $lote->participantes()->where('es_control_qa', false)->first();

        foreach ([route('credential-flow.lotes.emitir', $lote), route('credential-flow.lotes.emitir-qa', $lote), route('credential-flow.participantes.emitir', [$lote, $real])] as $url) {
            $r = $this->actingAs($this->admin())->postJson($url)->assertStatus(409);
            $this->assertSame(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $r->json('error.code'), $url);
            $this->assertStringContainsString('28/10/2026 08:00', $r->json('error.message'));
        }
        // Ni llamando a los servicios directamente.
        $this->assertSame(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $this->codigo(fn () => app(EmisorLote::class)->emitirTodo($lote, null)));
        $this->assertSame(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $this->codigo(fn () => app(EmisorLote::class)->emitirPendientes($lote, null, true)));
        // Ni inventando un boleto para la programación (sin reservarla, o con un boleto que no es el suyo).
        $this->assertSame(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $this->codigo(fn () => app(EmisorLote::class)->emitirTodo($lote, null, EjecucionAutorizada::nueva($p->id))));
        $this->assertSame(0, Emision::count());

        // Al cancelar, la emisión manual vuelve a funcionar igual que siempre.
        $this->servicio()->cancelar($p, $this->admin(), 'Se emite ahora');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2)->assertJsonPath('qa.total', 2);
    }

    public function test_solo_la_programacion_activa_en_ejecucion_puede_emitir_y_otra_base_no_queda_afectada(): void
    {
        $lote = $this->base(2);
        $otra = $this->base(1);
        $p = $this->programar($lote);

        // Otra base sin programación emite normalmente.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $otra))->assertCreated();

        // Solo el ejecutor que la RESERVÓ, con SU boleto, pasa el candado; un boleto inventado para la misma programación, no.
        $boleto = app(EjecutorProgramado::class)->reservar($p);
        $this->assertNotNull($boleto);
        $this->assertSame(EmisionProgramada::EJECUTANDO, $p->fresh()->estado);
        $this->assertSame(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $this->codigo(fn () => app(EmisorLote::class)->emitirTodo($lote, null)), 'la emisión manual sigue bloqueada');
        $this->assertSame(EmisionException::EMISION_PROGRAMADA_PENDIENTE, $this->codigo(fn () => app(EmisorLote::class)->emitirTodo($lote, null, EjecucionAutorizada::nueva($p->id))), 'boleto falso');
        $this->assertNull(app(EjecutorProgramado::class)->reservar($p), 'nadie más la reserva');
        $r = app(EmisorLote::class)->emitirTodo($lote, $p->programada_por, $boleto);
        $this->assertSame([2, 2], [$r['oficiales']['total'], $r['qa']['total']]);
        $this->assertSame([$this->admin()->id], Emision::where('lote_id', $lote->id)->distinct()->pluck('emitido_por')->all(), 'queda registrado quien programó');
    }

    public function test_la_reemision_de_un_certificado_ya_emitido_no_forma_parte_de_la_programacion(): void
    {
        $lote = $this->base(2);
        $real = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->first();
        $emitida = Emision::findOrFail($this->emitirPor($lote, $real)->assertCreated()->json('emision.id'));
        $p = $this->programar($lote->fresh());
        $this->assertSame(1, $p->oficiales_esperados, 'solo los pendientes');

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $emitida), ['motivo' => 'Corrección de un dato'])->assertCreated();
        $this->assertSame([], $this->servicio()->cambios($p), 'reemitir a quien ya tenía certificado no cambia lo programado');
    }

    // ── Compatibilidad ────────────────────────────────────────────────────────

    public function test_sin_programaciones_la_emision_y_sus_validaciones_son_las_de_siempre(): void
    {
        $lote = $this->base(3);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()
            ->assertJsonPath('resultado.total', 3)->assertJsonPath('qa.total', 2)->assertJsonPath('qa_error', null);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::SIN_PENDIENTES);

        $sinVariante = $this->base(1, conVariante: false);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $sinVariante))->assertStatus(409)->assertJsonPath('error.code', EmisionException::VARIANTE_QA_FALTANTE);
        $this->assertSame(0, Emision::where('lote_id', $sinVariante->id)->count());
    }

    public function test_verificar_emitible_no_genera_ni_guarda_nada_y_cuenta_todos_los_pendientes(): void
    {
        $lote = $this->base(3);
        $antes = $this->archivosDeEmisiones();
        $v = app(EmisorLote::class)->verificarEmitible($lote);

        $this->assertSame([3, 2], [$v['oficiales'], $v['qa']]);
        $this->assertSame($lote->plantilla_id, $v['plantilla_id']);
        $this->assertNotNull($v['variante_qa_id']);
        $this->assertSame($antes, $this->archivosDeEmisiones());
        $this->assertSame(0, Emision::count());
    }
}
