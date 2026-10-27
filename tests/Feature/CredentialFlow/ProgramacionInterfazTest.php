<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Programacion\EjecutorProgramado;
use App\Support\CredentialFlow\Programacion\PresentadorProgramacion;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Emisión programada — P3: la interfaz administrativa (pantalla de la base y sus endpoints JSON). Las reglas viven en ServicioProgramacion (P1) y en el
 * ejecutor (P2); aquí se comprueba que la pantalla las expone bien, que ningún botón ni endpoint las salta y que cada estado se cuenta sin engañar.
 * Reloj simulado (martes 27 de octubre de 2026, 10:00 de Colombia), datos 100 % ficticios, SQLite en memoria y discos falsos.
 */
class ProgramacionInterfazTest extends ProgramacionTestCase
{
    private function a(int $h, int $m, int $dia = 27): void
    {
        $this->travelTo(CarbonImmutable::create(2026, 10, $dia, $h, $m, 0, 'America/Bogota'));
    }

    /** El bloque `lote.programacion` que recibe la pantalla de la base. */
    private function bloque(Lote $lote): array
    {
        $props = null;
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()
            ->assertInertia(function (Assert $page) use (&$props) {
                $props = $page->toArray()['props']['lote']['programacion'];
            });

        return (array) $props;
    }

    private function previa(Lote $lote, mixed $cuando = '2026-10-28T08:00')
    {
        return $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.programacion.previa', $lote), ['programada_para' => $cuando]);
    }

    /** Programar como la pantalla: primero el resumen y luego la confirmación con su sello (si el resumen falla, un sello cualquiera con forma válida). */
    private function crear(Lote $lote, mixed $cuando = '2026-10-28T08:00', ?string $sello = null)
    {
        $sello ??= $this->previa($lote, $cuando)->json('previa.sello') ?? str_repeat('0', 64);

        return $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.programacion.store', $lote), ['programada_para' => $cuando, 'sello' => $sello]);
    }

    /** Reprogramar y reconfirmar llevan, como la pantalla, el sello de lo que se revisó (salvo que la prueba lo indique). */
    private function accion(string $accion, Lote $lote, EmisionProgramada $p, array $datos = [])
    {
        if (! array_key_exists('sello', $datos)) {
            $datos['sello'] = match ($accion) {
                'reprogramar' => $this->previa($lote, $datos['programada_para'] ?? '')->json('previa.sello') ?? str_repeat('0', 64),
                'reconfirmar' => $this->bloque($lote)['activa']['sello'] ?? str_repeat('0', 64),
                default => null,
            };
        }

        return $this->actingAs($this->admin())->postJson(route("credential-flow.lotes.programacion.{$accion}", [$lote, $p]), array_filter($datos, fn ($v) => $v !== null));
    }

    private function correr(): void
    {
        app('auth')->forgetGuards();
        $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas'));
    }

    // ── Flujo completo desde la pantalla ──────────────────────────────────────

    public function test_sin_programacion_la_pantalla_ofrece_programar_con_los_limites_y_sin_falso_programador_activo(): void
    {
        $b = $this->bloque($this->base(2));

        $this->assertTrue($b['habilitada']);
        $this->assertTrue($b['puede_gestionar']);
        $this->assertNull($b['activa']);
        $this->assertNull($b['ultima']);
        $this->assertSame('Hora de Colombia (UTC−5)', $b['limites']['zona']);
        $this->assertSame([5, 90], [$b['limites']['minimo_minutos'], $b['limites']['maximo_dias']]);
        $this->assertSame(['2026-10-27T10:06', '2027-01-25T10:00'], [$b['limites']['min'], $b['limites']['max']]);
        // Sin ningún latido del programador (la tarea del servidor aún no existe) NUNCA se muestra «activo».
        $this->assertSame('sin_senal', $b['programador']['estado']);
    }

    public function test_programar_desde_la_pantalla_muestra_el_resumen_sin_guardar_y_luego_la_tarjeta_y_bloquea_la_emision_manual(): void
    {
        $lote = $this->base(3);

        // 1. Resumen previo: lo que se emitiría, sin guardar nada.
        $r = $this->previa($lote)->assertOk();
        $r->assertJsonPath('previa.programada_para', '2026-10-28T08:00')
            ->assertJsonPath('previa.programada_para_texto', 'miércoles 28 de octubre de 2026 a las 8:00 a. m.')
            ->assertJsonPath('previa.oficiales', 3)
            ->assertJsonPath('previa.qa', 2)
            ->assertJsonPath('previa.plantilla', Plantilla::find($lote->plantilla_id)->nombre)
            ->assertJsonPath('previa.variante_qa', Plantilla::find(Plantilla::find($lote->plantilla_id)->variante_qa_id)->nombre);
        $this->assertContains($this->bloque($lote)['programador']['texto'], $r->json('previa.avisos'), 'avisa que el programador no da señales');
        $this->assertSame(0, EmisionProgramada::count(), 'el resumen no guarda nada');

        // 2. Confirmar.
        $this->crear($lote)->assertCreated()->assertJsonPath('programacion.activa.estado', EmisionProgramada::PENDIENTE);
        $p = EmisionProgramada::sole();
        $this->assertSame([EmisionProgramada::PENDIENTE, $this->admin()->id, 3, 2], [$p->estado, $p->programada_por, $p->oficiales_esperados, $p->qa_esperados]);

        // 3. La tarjeta.
        $a = $this->bloque($lote)['activa'];
        $this->assertSame('miércoles 28 de octubre de 2026 a las 8:00 a. m.', $a['programada_para_texto']);
        $this->assertSame(['Programada', 'info'], [$a['situacion']['titulo'], $a['situacion']['tono']]);
        $this->assertSame([3, 2, true, true, []], [$a['oficiales_esperados'], $a['qa_esperados'], $a['modificable'], $a['puede_reconfirmar'], $a['cambios']]);
        $this->assertNotNull($a['programada_por']);

        // 4. Ningún camino manual emite mientras está pendiente.
        $participante = $lote->participantes()->where('es_control_qa', false)->first();
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::EMISION_PROGRAMADA_PENDIENTE);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::EMISION_PROGRAMADA_PENDIENTE);
        $this->actingAs($this->admin())->postJson(route('credential-flow.participantes.emitir', [$lote, $participante]))->assertStatus(409)->assertJsonPath('error.code', EmisionException::EMISION_PROGRAMADA_PENDIENTE);
        $this->assertSame(0, Emision::count());
    }

    public function test_la_hora_se_valida_en_colombia_con_minimo_5_minutos_y_maximo_90_dias(): void
    {
        $lote = $this->base(1);
        foreach ([
            ['', 'FECHA_INVALIDA'], ['mañana a las 8', 'FECHA_INVALIDA'], [['2026-10-28T08:00'], 'FECHA_INVALIDA'], ['2026-02-30T08:00', 'FECHA_INVALIDA'],
            ['2026-10-28T08:00:30', 'FECHA_INVALIDA'], ['2026-10-28T08:00Z', 'FECHA_INVALIDA'],
            ['2026-10-27T09:59', 'FECHA_PASADA'], ['2026-10-27T10:00', 'FECHA_PASADA'], ['2026-10-27T10:04', 'FECHA_MUY_PRONTO'],
            ['2027-01-25T10:01', 'FECHA_MUY_LEJANA'],
        ] as [$valor, $codigo]) {
            $this->previa($lote, $valor)->assertStatus(422)->assertJsonPath('error.code', $codigo);
            $this->crear($lote, $valor)->assertStatus(422)->assertJsonPath('error.code', $codigo);
        }
        $this->previa($lote, '2026-10-27T10:05')->assertOk();
        $this->previa($lote, '2027-01-25T10:00')->assertOk();
        $this->assertSame(0, EmisionProgramada::count());
    }

    // ── Permisos y accesos indebidos ──────────────────────────────────────────

    public function test_solo_administradores_activos_pueden_programar_cancelar_reconfirmar_o_reprogramar(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote);
        $rutas = [
            route('credential-flow.lotes.programacion.previa', $lote), route('credential-flow.lotes.programacion.store', $lote),
            route('credential-flow.lotes.programacion.reprogramar', [$lote, $p]), route('credential-flow.lotes.programacion.reconfirmar', [$lote, $p]),
            route('credential-flow.lotes.programacion.cancelar', [$lote, $p]),
        ];
        $datos = ['programada_para' => '2026-10-29T08:00', 'version' => 1, 'motivo' => 'Prueba de acceso indebido'];

        // Sin sesión: al inicio de sesión. Rol sin acceso (comercial): prohibido.
        foreach ($rutas as $url) {
            app('auth')->forgetGuards();
            $this->post($url, $datos)->assertRedirect(route('login'));
            $this->actingAs($this->comercial())->postJson($url, $datos)->assertForbidden();
        }
        // Administrador con la cuenta inactiva: el servicio lo rechaza.
        $inactivo = $this->admin();
        $inactivo->forceFill(['estado_id' => 2])->save();
        foreach ($rutas as $url) {
            $this->actingAs($inactivo->fresh())->postJson($url, $datos)->assertForbidden()->assertJsonPath('error.code', 'SIN_PERMISO');
        }
        $p->refresh();
        $this->assertSame([EmisionProgramada::PENDIENTE, 1, 1], [$p->estado, $p->version, EmisionProgramada::count()]);
    }

    public function test_una_programacion_solo_se_gestiona_desde_su_propia_base(): void
    {
        $lote = $this->base(1);
        $otra = $this->base(1);
        $p = $this->programar($lote);

        foreach (['reprogramar', 'reconfirmar', 'cancelar'] as $accion) {
            $this->accion($accion, $otra, $p, ['programada_para' => '2026-10-29T08:00', 'version' => 1, 'motivo' => 'Desde otra base'])->assertNotFound();
        }
        $this->assertSame([EmisionProgramada::PENDIENTE, 1], [$p->fresh()->estado, $p->fresh()->version]);
    }

    public function test_con_el_interruptor_apagado_no_se_ofrece_ni_se_acepta_programar_pero_si_cancelar(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote);
        config(['credential_flow.programacion.activa' => false]);
        Cache::put(EjecutorProgramado::LATIDO, now()->toIso8601String(), now()->addDay());

        $b = $this->bloque($lote);
        $this->assertFalse($b['habilitada']);
        $this->assertSame('desactivado', $b['programador']['estado'], 'con el interruptor apagado nunca dice «activo», aunque haya un latido reciente');
        $this->previa($lote)->assertForbidden()->assertJsonPath('error.code', 'PROGRAMACION_DESACTIVADA');
        $this->accion('reprogramar', $lote, $p, ['programada_para' => '2026-10-29T08:00', 'version' => 1])->assertForbidden()->assertJsonPath('error.code', 'PROGRAMACION_DESACTIVADA');
        $this->accion('reconfirmar', $lote, $p, ['version' => 1])->assertForbidden()->assertJsonPath('error.code', 'PROGRAMACION_DESACTIVADA');

        $this->accion('cancelar', $lote, $p, ['version' => 1, 'motivo' => 'Ya no se emitirá así'])->assertOk()->assertJsonPath('programacion.activa', null);
        $this->assertSame(EmisionProgramada::CANCELADA, $p->fresh()->estado);
    }

    // ── Doble clic y cambios concurrentes ─────────────────────────────────────

    public function test_doble_clic_y_versiones_viejas_no_crean_ni_pisan_nada(): void
    {
        $lote = $this->base(2);
        $this->crear($lote)->assertCreated();
        $this->crear($lote)->assertStatus(409)->assertJsonPath('error.code', 'YA_EXISTE_ACTIVA');
        $this->assertSame(1, EmisionProgramada::count());
        $p = EmisionProgramada::sole();

        // Primer clic de «Reprogramar» (versión 1) gana; el segundo con la misma versión vieja se rechaza.
        $this->accion('reprogramar', $lote, $p, ['programada_para' => '2026-10-29T09:15', 'version' => 1])->assertOk()
            ->assertJsonPath('programacion.activa.programada_para_texto', 'jueves 29 de octubre de 2026 a las 9:15 a. m.')
            ->assertJsonPath('programacion.activa.version', 2);
        $this->accion('reprogramar', $lote, $p, ['programada_para' => '2026-10-30T09:15', 'version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'CAMBIO_CONCURRENTE');
        $this->accion('cancelar', $lote, $p, ['version' => 1, 'motivo' => 'Cancelación con versión vieja'])->assertStatus(409)->assertJsonPath('error.code', 'CAMBIO_CONCURRENTE');
        // Sin versión no se acepta (la interfaz siempre la envía).
        $this->accion('cancelar', $lote, $p, ['motivo' => 'Cancelación sin versión'])->assertStatus(422)->assertJsonPath('error.code', 'CAMBIO_CONCURRENTE');
        $this->accion('reconfirmar', $lote, $p, ['version' => 'dos'])->assertStatus(422);

        $p->refresh();
        $this->assertSame([EmisionProgramada::PENDIENTE, 2, '2026-10-29 09:15:00', 1], [$p->estado, $p->version, $p->getRawOriginal('programada_para'), $p->reprogramaciones]);
    }

    // ── Lo que se aprueba es exactamente lo que se revisó ─────────────────────

    public function test_sin_el_sello_del_resumen_no_se_aprueba_nada(): void
    {
        $lote = $this->base(2);
        foreach ([null, '', 'abc', ['x'], str_repeat('G', 64)] as $sello) {
            $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.programacion.store', $lote), array_filter(['programada_para' => '2026-10-28T08:00', 'sello' => $sello], fn ($v) => $v !== null))
                ->assertStatus(422)->assertJsonPath('error.code', 'RESUMEN_DESACTUALIZADO');
        }
        $p = $this->programar($lote);
        $this->accion('reconfirmar', $lote, $p, ['version' => 1, 'sello' => null])->assertStatus(422)->assertJsonPath('error.code', 'RESUMEN_DESACTUALIZADO');
        $this->accion('reprogramar', $lote, $p, ['programada_para' => '2026-10-29T08:00', 'version' => 1, 'sello' => null])->assertStatus(422)->assertJsonPath('error.code', 'RESUMEN_DESACTUALIZADO');
        $this->assertSame([1, 1], [EmisionProgramada::count(), $p->fresh()->version]);
    }

    public function test_si_la_base_cambia_despues_de_ver_el_resumen_programar_no_guarda_nada(): void
    {
        $lote = $this->base(2);
        $sello = $this->previa($lote)->assertOk()->json('previa.sello');
        $this->assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $sello);

        // Otra persona agrega un participante mientras el administrador mira el resumen (2 oficiales).
        $doc = 'C.C. 90.000.001';
        $lote->participantes()->create(['nombre_completo' => 'PERSONA AGREGADA DESPUÉS', 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc)]);

        $this->crear($lote, '2026-10-28T08:00', $sello)->assertStatus(409)->assertJsonPath('error.code', 'RESUMEN_DESACTUALIZADO');
        $this->assertSame(0, EmisionProgramada::count(), 'no se aprobó lo que no se vio');

        // Con el resumen nuevo (3 oficiales) sí.
        $r = $this->previa($lote)->assertOk()->assertJsonPath('previa.oficiales', 3);
        $this->crear($lote, '2026-10-28T08:00', $r->json('previa.sello'))->assertCreated();
        $this->assertSame(3, EmisionProgramada::sole()->oficiales_esperados);
    }

    public function test_reconfirmar_solo_aprueba_los_cambios_que_se_mostraron(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote);
        $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'PERSONA CORREGIDA']);
        $vista = $this->bloque($lote)['activa'];
        $this->assertSame([PresentadorProgramacion::PARTES['participantes']], $vista['cambios']);

        // Un segundo cambio llega después de que el administrador abrió «Revisar y confirmar».
        $lote->update(['datos_comunes' => ['evento' => 'EVENTO CAMBIADO DESPUÉS'] + $lote->datos_comunes]);
        $antes = $p->fresh()->huella;
        $this->accion('reconfirmar', $lote, $p, ['version' => $vista['version'], 'sello' => $vista['sello']])->assertStatus(409)->assertJsonPath('error.code', 'RESUMEN_DESACTUALIZADO');
        $this->assertSame([$antes, 1], [$p->fresh()->huella, $p->fresh()->version], 'la aprobación anterior sigue intacta');

        // Al recargar ve los dos cambios y entonces sí puede confirmarlos.
        $nueva = $this->bloque($lote)['activa'];
        $this->assertSame([PresentadorProgramacion::PARTES['base'], PresentadorProgramacion::PARTES['participantes']], $nueva['cambios']);
        $this->accion('reconfirmar', $lote, $p, ['version' => $nueva['version'], 'sello' => $nueva['sello']])->assertOk()->assertJsonPath('programacion.activa.cambios', []);
    }

    // ── Cambios después de programar ──────────────────────────────────────────

    public function test_un_cambio_despues_de_programar_se_explica_y_se_reconfirma_desde_la_pantalla(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote);
        $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'PERSONA CORREGIDA']);
        $lote->update(['datos_comunes' => ['evento' => 'EVENTO CORREGIDO'] + $lote->datos_comunes]);

        $a = $this->bloque($lote)['activa'];
        $this->assertSame([PresentadorProgramacion::PARTES['base'], PresentadorProgramacion::PARTES['participantes']], $a['cambios']);
        $this->assertSame(EmisionProgramada::PENDIENTE, $a['estado']);

        $this->accion('reconfirmar', $lote, $p, ['version' => 1])->assertOk()->assertJsonPath('programacion.activa.cambios', [])->assertJsonPath('programacion.activa.version', 2);
        $this->assertSame(0, Emision::count(), 'reconfirmar no emite');
    }

    public function test_requiere_confirmacion_se_muestra_y_si_la_hora_ya_paso_solo_se_puede_reprogramar(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote, '2026-10-27 10:30');
        $lote->participantes()->where('es_control_qa', false)->first()->update(['nombre_completo' => 'PERSONA CORREGIDA']);
        $this->a(10, 30);
        $this->correr();

        $a = $this->bloque($lote)['activa'];
        $this->assertSame([EmisionProgramada::REQUIERE_CONFIRMACION, 'Requiere confirmación', 'aviso', true, false],
            [$a['estado'], $a['situacion']['titulo'], $a['situacion']['tono'], $a['modificable'], $a['puede_reconfirmar']]);
        $this->assertSame([PresentadorProgramacion::PARTES['participantes']], $a['cambios']);
        $this->assertNull($a['error_mensaje'], 'la lista de cambios ya lo explica: no se repite con las claves internas del ejecutor');

        $this->accion('reconfirmar', $lote, $p, ['version' => $a['version']])->assertStatus(422)->assertJsonPath('error.code', 'FECHA_PASADA');
        $this->accion('reprogramar', $lote, $p, ['programada_para' => '2026-10-27T11:00', 'version' => $a['version']])->assertOk()
            ->assertJsonPath('programacion.activa.estado', EmisionProgramada::PENDIENTE)->assertJsonPath('programacion.activa.cambios', []);
        $this->assertSame(0, Emision::count());
    }

    public function test_cancelar_exige_motivo_libera_la_base_y_la_emision_manual_vuelve_a_funcionar(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote);
        $this->accion('cancelar', $lote, $p, ['version' => 1, 'motivo' => 'no'])->assertStatus(422)->assertJsonPath('error.code', 'MOTIVO_INVALIDO');
        $this->accion('cancelar', $lote, $p, ['version' => 1, 'motivo' => str_repeat('x', 256)])->assertStatus(422)->assertJsonPath('error.code', 'MOTIVO_INVALIDO');

        $this->accion('cancelar', $lote, $p, ['version' => 1, 'motivo' => 'El cliente pidió esperar'])->assertOk()->assertJsonPath('programacion.activa', null)
            ->assertJsonPath('programacion.ultima.estado', EmisionProgramada::CANCELADA)
            ->assertJsonPath('programacion.ultima.situacion.titulo', 'Cancelada')
            ->assertJsonPath('programacion.ultima.motivo_cancelacion', 'El cliente pidió esperar');
        // Cancelada: no se puede reconfirmar ni reprogramar.
        $this->accion('reconfirmar', $lote, $p, ['version' => 2])->assertStatus(409)->assertJsonPath('error.code', 'NO_MODIFICABLE');

        // La emisión manual de siempre funciona igual que antes.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2)->assertJsonPath('qa.total', 2);
        // Ya no queda nada pendiente: el resumen lo dice con el mensaje de la emisión y no guarda nada.
        $this->previa($lote)->assertStatus(409)->assertJsonPath('error.code', EmisionException::SIN_PENDIENTES);
        $this->assertSame(1, EmisionProgramada::count());
    }

    // ── Estado del programador según su último latido ─────────────────────────

    public function test_el_programador_se_muestra_segun_su_ultimo_latido_real(): void
    {
        $lote = $this->base(1);
        $this->assertSame('sin_senal', $this->bloque($lote)['programador']['estado']);

        Cache::put(EjecutorProgramado::LATIDO, now()->subSeconds(30)->toIso8601String(), now()->addDay());
        $b = $this->bloque($lote)['programador'];
        $this->assertSame(['activo', 'Programador automático activo · última señal hace menos de un minuto.'], [$b['estado'], $b['texto']]);

        Cache::put(EjecutorProgramado::LATIDO, now()->subMinutes(2)->toIso8601String(), now()->addDay());
        $this->assertSame('activo', $this->bloque($lote)['programador']['estado']);

        Cache::put(EjecutorProgramado::LATIDO, now()->subMinutes(10)->toIso8601String(), now()->addDay());
        $b = $this->bloque($lote)['programador'];
        $this->assertSame('detenido', $b['estado']);
        $this->assertStringContainsString('martes 27 de octubre de 2026 a las 9:50 a. m.', $b['texto']);

        // El latido lo deja el comando real (un minuto después ya cuenta como activo).
        Cache::forget(EjecutorProgramado::LATIDO);
        $this->correr();
        $this->assertSame('activo', $this->bloque($lote)['programador']['estado']);
    }

    // ── Resultados: completa, con avisos, fallida, ejecutando ─────────────────

    public function test_una_completada_se_muestra_con_su_resultado_y_libera_la_base(): void
    {
        $lote = $this->base(3);
        $this->programar($lote, '2026-10-27 10:30');
        $this->a(10, 30);
        $this->correr();

        $b = $this->bloque($lote);
        $this->assertNull($b['activa']);
        $u = $b['ultima'];
        $this->assertSame([EmisionProgramada::COMPLETADA, 'Completada', 'exito', 3, 2, false], [$u['estado'], $u['situacion']['titulo'], $u['situacion']['tono'], $u['oficiales_emitidos'], $u['qa_emitidos'], $u['modificable']]);
        $this->assertSame('martes 27 de octubre de 2026 a las 10:30 a. m.', $u['terminada_texto']);
    }

    public function test_completada_con_avisos_no_se_presenta_como_un_exito_completo(): void
    {
        $lote = $this->base(3);
        $this->programar($lote, '2026-10-27 10:30');
        $escritos = 0;
        AlmacenEmisiones::$antesDeEscribir = function () use (&$escritos) {
            if (++$escritos > 3) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo guardar un PDF.', 500);
            }
        };
        $this->a(10, 30);
        $this->correr();
        AlmacenEmisiones::$antesDeEscribir = null;

        $u = $this->bloque($lote)['ultima'];
        $this->assertSame([EmisionProgramada::COMPLETADA, true, 'Completada con avisos', 'aviso', 3, 0], [$u['estado'], $u['con_avisos'], $u['situacion']['titulo'], $u['situacion']['tono'], $u['oficiales_emitidos'], $u['qa_emitidos']]);
    }

    public function test_una_fallida_nunca_se_muestra_como_completada_y_dice_si_fue_parcial(): void
    {
        $lote = $this->base(2);
        $this->programar($lote, '2026-10-27 10:30');
        Storage::disk('local')->delete(Plantilla::find($lote->plantilla_id)->rutaPdfEsperada());
        $this->a(10, 30);
        $this->correr();

        $u = $this->bloque($lote)['ultima'];
        $this->assertSame([EmisionProgramada::FALLIDA, 'No se completó', 'error', false, 0], [$u['estado'], $u['situacion']['titulo'], $u['situacion']['tono'], $u['parcial'], $u['oficiales_emitidos']]);
        $this->assertSame('PLANTILLA_SIN_PDF', $u['error_codigo']);
        $this->assertStringNotContainsString('Complet', $u['situacion']['titulo']);

        // Una fallida parcial lo dice.
        $p = EmisionProgramada::sole();
        $p->forceFill(['resultado' => ['oficiales_emitidos' => 1, 'qa_emitidos' => 0, 'parcial' => true, 'operaciones' => []]])->save();
        $u = $this->bloque($lote)['ultima'];
        $this->assertSame(['No se completó (parcial)', true, 1], [$u['situacion']['titulo'], $u['parcial'], $u['oficiales_emitidos']]);
    }

    public function test_mientras_se_ejecuta_no_se_puede_cancelar_reprogramar_ni_emitir_a_mano(): void
    {
        $lote = $this->base(2);
        $p = $this->programar($lote, '2026-10-27 10:30');
        DB::table('cf_emisiones_programadas')->where('id', $p->id)->update(['estado' => EmisionProgramada::EJECUTANDO, 'iniciada_at' => now(), 'latido_at' => now(), 'reclamo_hash' => str_repeat('a', 64)]);

        $a = $this->bloque($lote)['activa'];
        $this->assertSame([EmisionProgramada::EJECUTANDO, 'Emitiendo ahora', false, false], [$a['estado'], $a['situacion']['titulo'], $a['modificable'], $a['puede_reconfirmar']]);
        $this->accion('cancelar', $lote, $p, ['version' => 1, 'motivo' => 'Intento durante la ejecución'])->assertStatus(409)->assertJsonPath('error.code', 'NO_MODIFICABLE');
        $this->accion('reprogramar', $lote, $p, ['programada_para' => '2026-10-29T08:00', 'version' => 1])->assertStatus(409)->assertJsonPath('error.code', 'NO_MODIFICABLE');
        $this->crear($lote, '2026-10-29T08:00')->assertStatus(409)->assertJsonPath('error.code', 'YA_EXISTE_ACTIVA');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::EMISION_PROGRAMADA_PENDIENTE);
        $this->assertSame(0, Emision::count());
    }

    public function test_un_reintento_pendiente_se_explica_como_tal(): void
    {
        $lote = $this->base(1);
        $p = $this->programar($lote, '2026-10-27 10:30');
        DB::table('cf_emisiones_programadas')->where('id', $p->id)->update(['intentos' => 1, 'error_codigo' => 'BASE_DE_DATOS_OCUPADA', 'error_mensaje' => 'La base de datos estaba ocupada.', 'proximo_intento_at' => '2026-10-27 10:32:00']);

        $a = $this->bloque($lote)['activa'];
        $this->assertSame(['Pendiente · se reintentará', 'aviso', '10:32 a. m.'], [$a['situacion']['titulo'], $a['situacion']['tono'], $a['proximo_intento_texto']]);
    }

    // ── Requisitos que faltan ─────────────────────────────────────────────────

    public function test_sin_requisitos_el_resumen_lo_dice_y_no_se_guarda_nada(): void
    {
        $sinVariante = $this->base(1, conVariante: false);
        $this->previa($sinVariante)->assertStatus(409)->assertJsonPath('error.code', EmisionException::VARIANTE_QA_FALTANTE);

        $archivada = $this->base(1);
        $archivada->forceFill(['estado_operativo' => Lote::ARCHIVADA])->save();
        $this->previa($archivada)->assertStatus(409)->assertJsonPath('error.code', 'BASE_NO_ACTIVA');

        $this->assertSame(0, EmisionProgramada::count());
    }

    // ── Datos y compatibilidad ────────────────────────────────────────────────

    public function test_la_pantalla_no_expone_datos_personales_en_el_bloque_de_programacion(): void
    {
        $lote = $this->base(2);
        $this->programar($lote);
        $texto = json_encode($this->bloque($lote), JSON_UNESCAPED_UNICODE);
        foreach (['PERSONA NÚMERO', '20.000.001', 'QA PROGRAMADA', '72000001', '@example.test', '@fycconsultores.com', 'huella'] as $dato) {
            $this->assertStringNotContainsString($dato, $texto, $dato);
        }
    }

    public function test_sin_la_tabla_la_pantalla_sigue_funcionando_y_no_ofrece_programar(): void
    {
        $lote = $this->base(1);
        Schema::drop('cf_emisiones_programadas');
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()
            ->assertInertia(fn (Assert $page) => $page->where('lote.programacion', null));
    }
}
