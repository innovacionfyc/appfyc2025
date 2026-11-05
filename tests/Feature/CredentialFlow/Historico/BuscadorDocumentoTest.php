<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Busqueda\ConsultaPorDocumento;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/**
 * Buscador administrativo por documento (E2): rutas, permisos, interruptor, límites, auditoría y privacidad. Datos 100 % ficticios: el histórico sintético de
 * PortalTestCase (Ana = 1000001, María/Mario = 3000001, Julia CC/CE = 2000004) más bases modernas creadas aquí.
 */
class BuscadorDocumentoTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_19_100000_cf_vinculos_identidad_moderna_table.php', '--force' => true])->assertExitCode(0);
        config(['credential_flow.buscador_documento.activo' => true]);
    }

    private function buscar(string $documento, ?string $tipo = null, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->superAdmin())->postJson(route('credential-flow.buscador-documento.buscar'), ['documento' => $documento, 'tipo' => $tipo]);
    }

    private function busquedas(): Collection
    {
        return Movimiento::query()->where('tipo', 'consulta')->where('modulo', 'credential-flow')->orderBy('id')->get();
    }

    private function emitido(Lote $lote, string $nombre, string $documento, ?string $modalidad = null): Emision
    {
        $p = $this->participante($lote, $nombre, $documento);
        if ($modalidad !== null) {
            $p->update(['modalidad' => $modalidad]);
        }
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::query()->where('participante_id', $p->id)->firstOrFail();
    }

    // ── Interruptor, sesión y rol ───────────────────────────────────────────

    public function test_apagado_responde_404_a_cualquiera_antes_de_mirar_sesion_o_rol(): void
    {
        config(['credential_flow.buscador_documento.activo' => false]);
        $this->get(route('credential-flow.buscador-documento.index'))->assertNotFound();
        $this->postJson(route('credential-flow.buscador-documento.buscar'), ['documento' => '1000001'])->assertNotFound();
        foreach ([$this->superAdmin(), $this->admin(), $this->comercial()] as $u) {
            $this->actingAs($u)->get(route('credential-flow.buscador-documento.index'))->assertNotFound();
            $this->buscar('1000001', null, $u)->assertNotFound();
        }
        $this->assertCount(0, $this->busquedas());
    }

    public function test_encendido_solo_super_admin_entra(): void
    {
        $this->get(route('credential-flow.buscador-documento.index'))->assertRedirect(route('login'));
        $this->postJson(route('credential-flow.buscador-documento.buscar'), ['documento' => '1000001'])->assertUnauthorized();
        foreach ([$this->admin(), $this->comercial()] as $u) {
            $this->actingAs($u)->get(route('credential-flow.buscador-documento.index'))->assertForbidden();
            $this->buscar('1000001', null, $u)->assertForbidden();
        }
        $this->assertCount(0, $this->busquedas(), 'un acceso rechazado no busca ni audita');

        $this->actingAs($this->superAdmin())->get(route('credential-flow.buscador-documento.index'))->assertOk()
            ->assertHeader('Cache-Control', 'no-store, private')
            ->assertInertia(fn (Assert $p) => $p->component('CredentialFlow/Buscador/Documento')->where('minimo', 6)->where('limites', ['minuto' => 20, 'dia' => 100])->has('tipos', 7)->missing('resultado'));
        $this->buscar('1000001')->assertOk()->assertHeader('Cache-Control', 'no-store, private');
    }

    public function test_la_tarjeta_del_inicio_solo_aparece_para_super_admin_con_el_interruptor_encendido(): void
    {
        $tarjeta = fn ($u) => $this->actingAs($u)->get(route('credential-flow.index'))->assertOk()->inertiaProps('buscadorDocumento');
        $this->assertTrue($tarjeta($this->superAdmin()));
        $this->assertFalse($tarjeta($this->admin()));
        config(['credential_flow.buscador_documento.activo' => false]);
        $this->assertFalse($tarjeta($this->superAdmin()));
    }

    // ── El documento nunca en la URL ────────────────────────────────────────

    public function test_la_busqueda_es_post_con_csrf_y_el_documento_nunca_viaja_en_la_url(): void
    {
        $ruta = Route::getRoutes()->getByName('credential-flow.buscador-documento.buscar');
        $this->assertSame(['POST'], $ruta->methods());
        $this->assertSame([], $ruta->parameterNames());
        $this->assertContains('web', $ruta->gatherMiddleware(), 'CSRF del grupo web');
        $this->assertSame(['GET', 'HEAD'], Route::getRoutes()->getByName('credential-flow.buscador-documento.index')->methods());

        // Un documento en la cadena de consulta se ignora: ni la pantalla ni la búsqueda lo usan.
        $this->actingAs($this->superAdmin())->get(route('credential-flow.buscador-documento.index', ['documento' => '1000001']))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->missing('resultado'));
        $this->actingAs($this->superAdmin())->postJson(route('credential-flow.buscador-documento.buscar', ['documento' => '1000001']), [])->assertStatus(422);
        $this->assertSame(0, $this->busquedas()->where('metadata.resultado', 'ok')->count());
    }

    // ── Validación y respuestas seguras ─────────────────────────────────────

    public function test_minimo_de_seis_caracteres_busqueda_exacta_y_mensajes_sin_el_dato(): void
    {
        $logs = [];
        Event::listen(MessageLogged::class, function (MessageLogged $m) use (&$logs) {
            $logs[] = $m->message.json_encode($m->context);
        });

        foreach (['12345', 'C.C. 12.345', '', '1%'] as $corto) {
            $r = $this->buscar($corto)->assertStatus(422);
            $this->assertStringContainsString('mínimo 6 caracteres', $r->json('message'));
            if ($corto !== '') {
                $this->assertStringNotContainsString($corto, $r->getContent());
            }
        }
        $this->buscar('1000001', 'XYZ')->assertStatus(422)->assertJsonMissingPath('resultado');
        // Exacto: un prefijo del número no encuentra nada (no hay búsqueda por partes).
        $this->assertSame(0, $this->buscar('100000')->assertOk()->json('resultado.resumen.certificados'));
        $this->assertGreaterThan(0, $this->buscar('1000001')->assertOk()->json('resultado.resumen.certificados'));

        foreach ($logs as $l) {
            $this->assertStringNotContainsString('1000001', $l);
            $this->assertStringNotContainsString('12345', $l);
        }
    }

    public function test_limites_de_20_por_minuto_y_100_por_dia_por_administrador(): void
    {
        for ($i = 0; $i < 20; $i++) {
            $this->buscar('1000001')->assertOk();
        }
        $r = $this->buscar('1000001')->assertStatus(429);
        $this->assertStringNotContainsString('1000001', $r->getContent());
        $this->assertCount(20, $this->busquedas(), 'una búsqueda rechazada por el límite no se ejecuta');

        for ($minuto = 1; $minuto <= 4; $minuto++) {
            $this->travel(61)->seconds();
            for ($i = 0; $i < 20; $i++) {
                $this->buscar('1000001')->assertOk();
            }
        }
        $this->travel(61)->seconds();
        $this->buscar('1000001')->assertStatus(429);
        $this->assertCount(100, $this->busquedas());

        $this->travel(1)->days();
        $this->buscar('1000001')->assertOk();
    }

    // ── Auditoría ───────────────────────────────────────────────────────────

    public function test_cada_busqueda_queda_en_movimientos_con_hmac_tipo_y_conteos_sin_la_cedula(): void
    {
        $super = $this->superAdmin();
        $this->buscar('C.C. 1.000.001', 'CC')->assertOk();
        $this->buscar('12345')->assertStatus(422);

        [$ok, $corta] = $this->busquedas()->all();
        $this->assertSame($super->id, $ok->user_id);
        $this->assertNotNull($ok->created_at);
        $this->assertSame('Búsqueda administrativa de certificados por documento', $ok->descripcion);
        $m = $ok->metadata;
        $this->assertSame(ConsultaPorDocumento::huella('1000001'), $m['documento_huella']);
        $this->assertSame(['CC', 'ok', $super->id], [$m['tipo_documento'], $m['resultado'], $m['usuario_id']]);
        $this->assertSame(['certificados', 'historicos', 'modernos', 'vigentes', 'qa', 'eventos', 'identidades', 'excluidos', 'otros_tipos'], array_keys($m['conteos']));
        $this->assertSame(['DOCUMENTO_CORTO', null], [$corta->metadata['resultado'], $corta->metadata['conteos']]);

        $todo = json_encode([$ok->getAttributes(), $corta->getAttributes()]);
        foreach (['1000001', '1.000.001', '12345', 'ANA UNO PRUEBA', self::CORREO] as $sensible) {
            $this->assertStringNotContainsString($sensible, $todo);
        }
    }

    // ── Contenido ───────────────────────────────────────────────────────────

    public function test_historicos_modernos_qa_y_doble_modalidad_en_la_misma_respuesta(): void
    {
        $lote = $this->loteCon(0);
        $lote->update(['evento_id' => $this->idEvento('Curso Gamma')]);
        $p = $this->emitido($lote->fresh(), 'ANA UNO PRUEBA', 'C.C. 1.000.001', 'Presencial');
        $v = $this->emitido($lote->fresh(), 'ANA UNO PRUEBA', 'C.C. 1.000.001', 'Virtual');
        $qa = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        Participante::query()->whereKey($qa->participante_id)->update(['es_control_qa' => true]);

        $r = $this->buscar('1000001')->assertOk()->json('resultado');
        $this->assertArrayNotHasKey('documento_huella', $r);
        $this->assertSame(1, $r['resumen']['identidades']);
        $this->assertSame(3, $r['resumen']['modernos']);
        $this->assertSame(1, $r['resumen']['qa']);
        $this->assertGreaterThan(0, $r['resumen']['historicos']);
        $certs = collect($r['identidades'][0]['certificados']);
        $doble = $certs->where('origen', 'moderno')->whereIn('id', [$p->id, $v->id]);
        $this->assertSame(['Presencial', 'Virtual'], $doble->sortBy('id')->pluck('modalidad')->values()->all());
        $this->assertCount(2, $doble->pluck('codigo')->unique());
        $this->assertSame([true, true], [$certs->firstWhere('id', $qa->id)['qa'], $certs->firstWhere('id', $qa->id)['sin_validez_academica']]);
        // El documento de cada registro va enmascarado.
        foreach ($certs as $c) {
            $this->assertStringNotContainsString('1000001', $c['documento']);
            $this->assertStringNotContainsString('1.000.001', $c['documento']);
            $this->assertStringStartsWith('*', $c['documento']);
        }
    }

    public function test_identidades_ambiguas_y_excluidos_se_muestran_separados_sin_descarga(): void
    {
        $r = $this->buscar('3000001')->assertOk()->json('resultado');
        $this->assertSame(2, $r['resumen']['identidades']);
        $this->assertTrue($r['resumen']['conflicto']);
        $this->assertSame(['A', 'B'], array_column($r['identidades'], 'etiqueta'));

        $this->emitido($this->loteCon(0), 'JULIA TRECE', 'T.I. 2.000.004');
        $r = $this->buscar('2000004')->assertOk()->json('resultado');
        $this->assertSame(1, $r['resumen']['excluidos']);
        $this->assertSame([false, ConsultaPorDocumento::IDENTIDAD_AMBIGUA], [$r['excluidos'][0]['descarga']['permitida'], $r['excluidos'][0]['descarga']['motivo']]);
        $this->assertTrue($r['identidades'][0]['tipos_distintos']);
    }

    // ── Solo lectura ────────────────────────────────────────────────────────

    public function test_buscar_no_toca_descargas_ni_genera_pdf_ni_cambia_certificados(): void
    {
        $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $tablas = ['cf_certificados_legado', 'cf_emisiones', 'cf_participantes', 'cf_descargas', 'cf_codigos_historicos', 'cf_vinculos_identidad', 'cf_correos'];
        $foto = fn () => array_map(fn ($t) => md5(json_encode(DB::table($t)->orderBy('id')->get())), array_combine($tablas, $tablas));
        $antes = [$foto(), Storage::disk('local')->allFiles(), DB::table('cf_certificados_legado')->whereNotNull('pdf_archivo')->count()];

        foreach (['1000001', '2000004', '3000001', '2000001'] as $d) {
            $this->buscar($d)->assertOk();
        }

        $this->assertSame($antes, [$foto(), Storage::disk('local')->allFiles(), DB::table('cf_certificados_legado')->whereNotNull('pdf_archivo')->count()]);
        $this->assertCount(4, $this->busquedas(), 'lo único que se escribe es la auditoría');
    }
}
