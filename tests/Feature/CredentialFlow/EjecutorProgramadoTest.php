<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\EspacioDisco;
use App\Support\CredentialFlow\Programacion\EjecutorProgramado;
use App\Support\CredentialFlow\Programacion\EstimacionMemoria;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * Emisión programada — P2: el ejecutor automático (`credential-flow:emisiones-programadas`). Reloj simulado (martes 27 de octubre de 2026, 10:00 de Colombia),
 * datos 100 % ficticios, base SQLite en memoria y discos falsos. La concurrencia REAL entre procesos está en Integracion\EmisionProgramadaConcurrenciaMysqlTest.
 */
class EjecutorProgramadoTest extends ProgramacionTestCase
{
    private function a(int $h, int $m, int $s = 0): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, 27, $h, $m, $s, 'America/Bogota'));
    }

    /** Corre el comando como el cron: SIN sesión web (se olvida cualquier usuario autenticado por la prueba) y devuelve lo que imprimió. */
    private function correr(array $opciones = []): string
    {
        app('auth')->forgetGuards();
        $this->assertFalse(Auth::check());
        $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas', $opciones));

        return Artisan::output();
    }

    /** Ejecuta `$f` y devuelve las sentencias SQL que ESCRIBEN (insert, update, delete, replace). */
    private function escriturasDurante(\Closure $f): array
    {
        $escrituras = [];
        DB::listen(function ($q) use (&$escrituras) {
            if (preg_match('/^\s*(insert|update|delete|replace)\b/i', $q->sql) === 1) {
                $escrituras[] = $q->sql;
            }
        });
        $f();

        return $escrituras;
    }

    private function emisionesPorParticipante(Lote $lote): array
    {
        return Emision::query()->where('lote_id', $lote->id)->selectRaw('participante_id, count(*) n')->groupBy('participante_id')->pluck('n', 'participante_id')->all();
    }

    // ── Futuras, vencidas y fuera de tolerancia ───────────────────────────────

    public function test_una_futura_no_se_ejecuta_y_al_llegar_la_hora_emite_oficiales_y_qa_en_nombre_de_quien_la_aprobo(): void
    {
        $lote = $this->base(3);
        $p = $this->programar($lote, '2026-10-27 10:30');

        $this->a(10, 29, 59);
        $this->assertStringContainsString('Sin emisiones programadas por ejecutar', $this->correr());
        $this->assertSame([EmisionProgramada::PENDIENTE, 0], [$p->fresh()->estado, Emision::count()]);

        $this->a(10, 30);
        $salida = $this->correr();
        $p->refresh();
        $this->assertStringContainsString("#{$p->id}: completada. Oficiales 3, QA 2.", $salida);
        $this->assertSame([EmisionProgramada::COMPLETADA, null, false, null, 1], [$p->estado, $p->lote_activa, $p->con_avisos, $p->error_codigo, $p->intentos]);
        $this->assertSame([3, 2, false], [$p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos'], $p->resultado['parcial']]);
        $this->assertCount(2, $p->resultado['operaciones'], 'una operación de oficiales y otra de QA, como «Emitir certificados»');
        $this->assertSame([$this->admin()->id], Emision::where('lote_id', $lote->id)->distinct()->pluck('emitido_por')->all(), 'en nombre de quien la aprobó');
        $this->assertNotNull(Cache::get(EjecutorProgramado::LATIDO), 'latido');
        $mov = Movimiento::where('descripcion', 'La emisión programada de una base terminó: completada')->sole();
        $this->assertSame([null, 'ejecutor-programado', $this->admin()->id, $p->id], [$mov->user_id, $mov->metadata['origen'], $mov->metadata['usuario_id'], $mov->metadata['programacion_id']]);

        // Ya no ocupa la base: la emisión manual vuelve a funcionar, y otra corrida no hace nada.
        $this->assertStringContainsString('Sin emisiones programadas', $this->correr());
        $this->assertSame(5, Emision::count());
    }

    public function test_emite_en_nombre_del_ultimo_que_la_aprobo_y_revisa_su_permiso_hoy_sin_sesion_web(): void
    {
        $lote = $this->base(2);
        $p = $this->servicio()->reconfirmar($this->programar($lote, '2026-10-27 10:30'), $this->superAdmin());

        // Quien la aprobó por última vez pierde el rol: sin sesión, el ejecutor lo ve en la base de datos y NO emite.
        $this->superAdmin()->perfilOrganizador->update(['rol_id' => $this->comercial()->perfilOrganizador->rol_id]);
        $this->a(10, 30);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, EjecutorProgramado::PERMISO, $lote->id, 0], [$p->estado, $p->error_codigo, $p->lote_activa, Emision::count()]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::EMISION_PROGRAMADA_PENDIENTE);

        // Otro administrador activo la reprograma: queda como quien aprobó y en su nombre se emite.
        $p = $this->servicio()->reprogramar($p, '2026-10-27 10:40', $this->admin());
        $this->assertSame([EmisionProgramada::PENDIENTE, 0, null], [$p->estado, $p->intentos, $p->error_codigo]);
        $this->a(10, 40);
        $this->correr();
        $this->assertSame(EmisionProgramada::COMPLETADA, $p->fresh()->estado);
        $this->assertSame([$this->admin()->id], Emision::distinct()->pluck('emitido_por')->all());

        // Una cuenta inactiva tampoco autoriza.
        $otra = $this->base(1);
        $q = $this->programar($otra, '2026-10-27 10:50');
        $this->admin()->update(['estado_id' => 2]);
        $this->a(10, 50);
        $this->correr();
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, EjecutorProgramado::PERMISO], [$q->fresh()->estado, $q->fresh()->error_codigo]);
    }

    public function test_si_el_servidor_estuvo_detenido_solo_ejecuta_dentro_de_la_tolerancia_de_seis_horas(): void
    {
        $dentro = $this->programar($this->base(1), '2026-10-27 10:30');
        $fuera = $this->programar($this->base(1), '2026-10-27 10:20');

        $this->a(16, 29);   // 5 h 59 min después de la primera; 6 h 09 min después de la segunda
        $salida = $this->correr();
        $this->assertSame(EmisionProgramada::COMPLETADA, $dentro->fresh()->estado);
        $f = $fuera->fresh();
        $this->assertSame([EmisionProgramada::FALLIDA, EjecutorProgramado::VENCIDA, null], [$f->estado, $f->error_codigo, $f->lote_activa]);
        $this->assertStringContainsString('vencida sin ejecutar', $salida);
        $this->assertSame(0, Emision::where('lote_id', $fuera->lote_id)->count(), 'nunca emite sola tan tarde');
    }

    // ── Reserva atómica y recuperación ────────────────────────────────────────

    public function test_la_reserva_es_atomica_y_una_ejecucion_viva_no_se_toca(): void
    {
        $p = $this->programar($this->base(1), '2026-10-27 10:30');
        $this->a(10, 30);
        $ejecutor = app(EjecutorProgramado::class);

        $boleto = $ejecutor->reservar($p);
        $this->assertNotNull($boleto);
        $this->assertNull($ejecutor->reservar($p), 'el segundo proceso no la obtiene');
        $this->assertSame([EmisionProgramada::EJECUTANDO, 1], [$p->fresh()->estado, $p->fresh()->intentos]);

        // Otra corrida mientras la primera sigue viva (latido reciente): no la toca.
        $this->a(10, 35);
        $this->assertStringContainsString('Sin emisiones programadas', $this->correr());
        $this->assertSame([EmisionProgramada::EJECUTANDO, $boleto->hash(), 0], [$p->fresh()->estado, $p->fresh()->reclamo_hash, Emision::count()]);
    }

    public function test_un_reinicio_a_mitad_de_camino_se_recupera_sin_emitir_dos_veces(): void
    {
        $lote = $this->base(205);
        $p = $this->programar($lote, '2026-10-27 10:30');
        $this->a(10, 30);

        // Primera operación hecha (200 oficiales + 2 QA) y el proceso «muere» antes de anotarla: sin latido desde la reserva.
        $boleto = app(EjecutorProgramado::class)->reservar($p);
        $r = app(EmisorLote::class)->emitirTodo($lote, $p->confirmada_por, $boleto);
        $this->assertSame([200, 2], [$r['oficiales']['total'], $r['qa']['total']]);
        $this->assertNull($p->fresh()->resultado);

        $this->a(10, 41);   // más de 10 minutos sin latido
        $salida = $this->correr();
        $p->refresh();
        $this->assertStringContainsString('ejecución interrumpida recuperada', $salida);
        $this->assertSame([EmisionProgramada::COMPLETADA, 2], [$p->estado, $p->intentos]);
        $this->assertSame([205, 2], [$p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos']], 'adopta lo que hizo antes de caer');
        $this->assertSame(207, Emision::where('lote_id', $lote->id)->count());
        $this->assertSame([1], array_values(array_unique($this->emisionesPorParticipante($lote))), 'nadie recibió dos certificados');
    }

    public function test_tres_interrupciones_dejan_la_programacion_fallida(): void
    {
        $p = $this->programar($this->base(1), '2026-10-27 10:30');
        $p->forceFill(['estado' => EmisionProgramada::EJECUTANDO, 'intentos' => 3, 'reclamo_hash' => str_repeat('a', 64), 'latido_at' => '2026-10-27 10:30:00'])->save();
        $this->a(10, 45);
        $this->correr();
        $this->assertSame([EmisionProgramada::FALLIDA, EjecutorProgramado::INTERRUMPIDA, null], [$p->fresh()->estado, $p->fresh()->error_codigo, $p->fresh()->lote_activa]);
    }

    // ── Emisión parcial y más de 200 ──────────────────────────────────────────

    public function test_oficiales_emitidos_y_pruebas_qa_fallidas_es_completada_con_avisos(): void
    {
        $lote = $this->base(3);
        $p = $this->programar($lote, '2026-10-27 10:30');
        // Los 3 PDF oficiales se escriben; los de las pruebas QA fallan al guardarse.
        $escritos = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$escritos) {
            if (++$escritos > 3) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar un PDF de la emisión masiva.', 500);
            }
        };
        $this->a(10, 30);
        $salida = $this->correr();
        $p->refresh();
        $this->assertStringContainsString('completada con avisos', $salida);
        $this->assertSame([EmisionProgramada::COMPLETADA, true, 'QA_PENDIENTES', null], [$p->estado, $p->con_avisos, $p->error_codigo, $p->lote_activa]);
        $this->assertSame([3, 0, ['ERROR_ESCRITURA']], [$p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos'], $p->resultado['avisos']]);

        // La base quedó libre: las pruebas se completan con «Emitir pruebas internas».
        AlmacenEmisiones::$antesDeEscribir = null;
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated()->assertJsonPath('resultado.total', 2);
    }

    public function test_mas_de_200_certificados_se_emiten_en_varias_operaciones_hasta_terminar(): void
    {
        $lote = $this->base(205);
        $p = $this->programar($lote, '2026-10-27 10:30');
        $this->a(10, 30);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::COMPLETADA, 205, 2], [$p->estado, $p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos']]);
        $this->assertCount(3, $p->resultado['operaciones'], '200 + QA en la primera; 5 en la segunda');
        $this->assertSame(207, Emision::where('lote_id', $lote->id)->count());
        $this->assertSame([1], array_values(array_unique($this->emisionesPorParticipante($lote))));
    }

    // ── Errores transitorios y permanentes ────────────────────────────────────

    public function test_un_error_transitorio_se_reintenta_a_los_2_y_5_minutos_y_luego_completa(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote, '2026-10-27 10:30');
        EspacioDisco::simular(1);   // sin espacio en disco: transitorio

        $this->a(10, 30);
        $this->assertStringContainsString('se reintentará en 2 minutos (intento 1 de 3)', $this->correr());
        $p->refresh();
        $this->assertSame([EmisionProgramada::PENDIENTE, 1, EmisionException::ESPACIO_INSUFICIENTE, '2026-10-27 10:32:00', $lote->id], [$p->estado, $p->intentos, $p->error_codigo, $p->proximo_intento_at?->format('Y-m-d H:i:s'), $p->lote_activa]);
        $this->assertSame(0, Emision::count());

        $this->a(10, 31);
        $this->assertStringContainsString('Sin emisiones programadas', $this->correr(), 'respeta la espera');
        $this->a(10, 32);
        $this->assertStringContainsString('se reintentará en 5 minutos (intento 2 de 3)', $this->correr());

        EspacioDisco::simular(null);   // se liberó espacio
        $this->a(10, 37);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::COMPLETADA, 3, null, 4], [$p->estado, $p->intentos, $p->error_codigo, Emision::count()]);
    }

    public function test_tres_errores_transitorios_agotan_los_intentos(): void
    {
        $p = $this->programar($this->base(1), '2026-10-27 10:30');
        EspacioDisco::simular(1);
        foreach ([[10, 30], [10, 32], [10, 37]] as [$h, $m]) {
            $this->a($h, $m);
            $this->correr();
        }
        $p->refresh();
        $this->assertSame([EmisionProgramada::FALLIDA, 3, EmisionException::ESPACIO_INSUFICIENTE, null], [$p->estado, $p->intentos, $p->error_codigo, $p->lote_activa]);
        $this->assertStringContainsString('Se agotaron los 3 intentos', $p->error_mensaje);
    }

    public function test_un_error_permanente_falla_de_inmediato_sin_reintentos(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote, '2026-10-27 10:30');
        Storage::disk('local')->delete(Plantilla::find($lote->plantilla_id)->rutaPdfEsperada());   // el PDF base de la plantilla desapareció

        $this->a(10, 30);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::FALLIDA, 1, 'PLANTILLA_SIN_PDF', null, null], [$p->estado, $p->intentos, $p->error_codigo, $p->proximo_intento_at, $p->lote_activa]);
        $this->assertSame([0, false], [Emision::count(), $p->resultado['parcial']]);
    }

    public function test_si_no_alcanza_la_memoria_falla_antes_de_generar_nada(): void
    {
        $p = $this->programar($this->base(2), '2026-10-27 10:30');
        EstimacionMemoria::$limiteSimuladoMb = 40;
        $this->a(10, 30);
        $this->correr();
        $this->assertSame([EmisionProgramada::FALLIDA, EjecutorProgramado::MEMORIA, 0], [$p->fresh()->estado, $p->fresh()->error_codigo, Emision::count()]);
    }

    // ── Cambios antes y durante la ejecución ──────────────────────────────────

    public function test_un_cambio_despues_de_programar_exige_reconfirmar_y_no_emite_nada(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote, '2026-10-27 10:30');
        $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'PERSONA CORREGIDA']);

        $this->a(10, 30);
        $salida = $this->correr();
        $p->refresh();
        $this->assertStringContainsString('requiere confirmación', $salida);
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, EjecutorProgramado::CAMBIOS, ['participantes'], $lote->id, 0],
            [$p->estado, $p->error_codigo, $p->resultado['cambios'], $p->lote_activa, Emision::count()]);
        $this->assertNull($p->terminada_at);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409);

        // Reprogramar acepta los datos de ahora y vuelve a «pendiente».
        $this->servicio()->reprogramar($p, '2026-10-27 10:40', $this->admin());
        $this->a(10, 40);
        $this->correr();
        $this->assertSame(EmisionProgramada::COMPLETADA, $p->fresh()->estado);
        $this->assertSame('PERSONA CORREGIDA', Emision::where('lote_id', $lote->id)->get()->pluck('datos_snapshot.nombre_completo')->first(fn ($n) => $n === 'PERSONA CORREGIDA'));
    }

    public function test_un_cambio_durante_la_ejecucion_la_detiene_antes_de_la_siguiente_operacion(): void
    {
        $lote = $this->base(205);
        $p = $this->programar($lote, '2026-10-27 10:30');
        EjecutorProgramado::$entreOperaciones = function () use ($lote) {
            EjecutorProgramado::$entreOperaciones = null;
            $lote->participantes()->where('es_control_qa', false)->whereDoesntHave('emisiones')->first()->update(['nombre_completo' => 'CAMBIO A MITAD DE CAMINO']);
        };
        $this->a(10, 30);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, EjecutorProgramado::CAMBIOS, ['participantes']], [$p->estado, $p->error_codigo, $p->resultado['cambios']]);
        $this->assertSame([200, 2, true], [$p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos'], $p->resultado['parcial']], 'la primera operación ya salió');
        $this->assertSame(202, Emision::where('lote_id', $lote->id)->count(), 'no emitió nada con el dato nuevo');

        // Al reprogramar, la ejecución anterior queda como historial y la nueva emite solo lo que falta.
        $p = $this->servicio()->reprogramar($p, '2026-10-27 10:40', $this->admin());
        $this->assertSame([5, 0], [$p->oficiales_esperados, $p->qa_esperados]);
        $this->assertCount(1, $p->resultado['ejecuciones_anteriores']);
        $this->a(10, 40);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::COMPLETADA, 5, 0], [$p->estado, $p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos']]);
        $this->assertSame(207, Emision::where('lote_id', $lote->id)->count());
        $this->assertSame([1], array_values(array_unique($this->emisionesPorParticipante($lote))));
    }

    public function test_una_base_archivada_o_sin_variante_qa_al_llegar_la_hora_exige_reconfirmar(): void
    {
        $archivada = $this->base(1);
        $p = $this->programar($archivada, '2026-10-27 10:30');
        $archivada->update(['estado_operativo' => Lote::ARCHIVADA]);
        $sinVariante = $this->base(1);
        $q = $this->programar($sinVariante, '2026-10-27 10:30');
        app(ServicioQa::class)->asignarVariante(Plantilla::find($sinVariante->plantilla_id), null);

        $this->a(10, 30);
        $this->correr();
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, ['base']], [$p->fresh()->estado, $p->fresh()->resultado['cambios']]);
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, ['plantilla', 'variante_qa']], [$q->fresh()->estado, $q->fresh()->resultado['cambios']]);
        $this->assertSame(0, Emision::count());
    }

    // ── Certificados ya emitidos con una programación pendiente ───────────────

    public function test_revocar_y_reemplazar_certificados_ya_emitidos_se_permite_auditado_y_no_altera_la_programacion(): void
    {
        $lote = $this->base(4);
        [$uno, $dos] = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->take(2)->get()->all();
        $e1 = Emision::findOrFail($this->emitirPor($lote, $uno)->assertCreated()->json('emision.id'));
        $e2 = Emision::findOrFail($this->emitirPor($lote, $dos)->assertCreated()->json('emision.id'));
        $p = $this->programar($lote->fresh(), '2026-10-27 10:30');
        $this->assertSame(2, $p->oficiales_esperados);

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e1), ['motivo' => 'Datos incorrectos'])->assertOk();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e2), ['motivo' => 'Corrección de un dato'])->assertCreated();
        $this->assertSame($p->id, Movimiento::where('descripcion', 'Se revocó una emisión de Credential Flow')->sole()->metadata['programacion_activa_id']);
        $this->assertSame($p->id, Movimiento::where('descripcion', 'Se reemitió una credencial de Credential Flow')->sole()->metadata['programacion_activa_id']);

        // Volver a emitir al revocado es una PRIMERA emisión en la base: bloqueada mientras la programación esté activa.
        $this->emitirPor($lote, $uno)->assertStatus(409)->assertJsonPath('error.code', EmisionException::EMISION_PROGRAMADA_PENDIENTE);

        $this->assertSame([], $this->servicio()->cambios($p), 'no cambia lo programado');
        $this->a(10, 30);
        $this->correr();
        $p->refresh();
        $this->assertSame([EmisionProgramada::COMPLETADA, 2, 2], [$p->estado, $p->resultado['oficiales_emitidos'], $p->resultado['qa_emitidos']]);
    }

    // ── Interruptor y simulación: cero escrituras ─────────────────────────────

    public function test_con_el_interruptor_apagado_no_hace_nada_ni_escribe_nada(): void
    {
        $p = $this->programar($this->base(1), '2026-10-27 10:30');
        config(['credential_flow.programacion.activa' => false]);
        $this->a(10, 30);
        $salida = '';
        $escrituras = $this->escriturasDurante(function () use (&$salida) {
            $salida = $this->correr();
        });
        $this->assertStringContainsString('desactivada', $salida);
        $this->assertSame([], $escrituras);
        $this->assertSame([EmisionProgramada::PENDIENTE, 0, 0], [$p->fresh()->estado, $p->fresh()->intentos, Emision::count()]);
        $this->assertNull(Cache::get(EjecutorProgramado::LATIDO));
    }

    public function test_simular_no_escribe_nada_ni_genera_pdf_y_dice_que_haria(): void
    {
        $vencible = $this->programar($this->base(2), '2026-10-27 10:30');
        $interrumpida = $this->programar($this->base(1), '2026-10-27 10:45');
        $atrasada = $this->programar($this->base(1), '2026-10-27 10:35');
        $cambiada = $this->programar($this->base(1), '2026-10-27 15:00');
        Lote::find($cambiada->lote_id)->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'OTRO NOMBRE']);
        $this->a(10, 45);
        app(EjecutorProgramado::class)->reservar($interrumpida);   // ejecutándose…
        $this->a(16, 40);                                           // …y sin latido desde hace horas; las de 10:30 y 10:35 pasaron la tolerancia
        $antes = DB::table('cf_emisiones_programadas')->orderBy('id')->get()->toArray();
        $archivos = $this->archivosDeEmisiones();

        $salida = '';
        $escrituras = $this->escriturasDurante(function () use (&$salida) {
            $salida = $this->correr(['--simular' => true]);
        });

        $this->assertSame([], $escrituras, 'ninguna escritura en la base');
        $this->assertEquals($antes, DB::table('cf_emisiones_programadas')->orderBy('id')->get()->toArray());
        $this->assertSame($archivos, $this->archivosDeEmisiones(), 'ningún PDF');
        $this->assertNull(Cache::get(EjecutorProgramado::LATIDO), 'ni el latido');
        $this->assertSame(0, Emision::count());
        $this->assertStringContainsString('SIMULACIÓN', $salida);
        $this->assertStringContainsString("#{$interrumpida->id}: se retomaría una ejecución interrumpida", $salida);
        $this->assertStringContainsString("#{$vencible->id}: se marcaría vencida", $salida);
        $this->assertStringContainsString("#{$atrasada->id}: se marcaría vencida", $salida);
        $this->assertStringContainsString("#{$cambiada->id}: pediría confirmación (CAMBIOS_SIN_CONFIRMAR: participantes)", $salida);

        // Y lo que dice coincide con lo que hace de verdad para una que sí toca.
        $nueva = $this->programar($this->base(3), '2026-10-27 16:50');
        $this->a(16, 50);
        $this->assertStringContainsString("#{$nueva->id}: emitiría 3 oficiales + 2 QA en 1 operación", $this->correr(['--simular' => true]));
        $this->assertSame(0, Emision::count());
    }

    // ── Sin datos personales ──────────────────────────────────────────────────

    public function test_la_programacion_y_su_auditoria_no_guardan_datos_personales(): void
    {
        $lote = $this->base(3);
        $p = $this->programar($lote, '2026-10-27 10:30');
        $this->a(10, 30);
        $this->correr();
        $fila = json_encode(DB::table('cf_emisiones_programadas')->find($p->id), JSON_UNESCAPED_UNICODE);
        $movs = json_encode(Movimiento::where('modulo', 'credential-flow')->where('metadata', 'like', '%programacion_id%')->get(), JSON_UNESCAPED_UNICODE);
        foreach (['PERSONA NÚMERO', '20.000.001', 'QA PROGRAMADA', '72000001', '@example.test'] as $dato) {
            $this->assertStringNotContainsString($dato, $fila, $dato);
            $this->assertStringNotContainsString($dato, $movs, $dato);
        }
    }
}
