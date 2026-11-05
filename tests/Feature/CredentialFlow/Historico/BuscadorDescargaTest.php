<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Busqueda\ConsultaPorDocumento;
use App\Support\CredentialFlow\Busqueda\DescargasBuscador;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * Buscador administrativo por documento (E3): descarga desde el panel con un token temporal ligado a la búsqueda, a la sesión y al super-admin; revalidación
 * antes de servir; SHA-256; auditoría en Movimientos (nunca en cf_descargas); seguimiento de descargas del portal y del panel. Datos 100 % ficticios.
 */
class BuscadorDescargaTest extends PortalTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => 'database/migrations/2026_10_19_100000_cf_vinculos_identidad_moderna_table.php', '--force' => true])->assertExitCode(0);
        config(['credential_flow.buscador_documento.activo' => true]);
    }

    private function buscar(string $documento): array
    {
        return $this->actingAs($this->superAdmin())->postJson(route('credential-flow.buscador-documento.buscar'), ['documento' => $documento])->assertOk()->json('resultado');
    }

    /** @return Collection<int,array<string,mixed>> */
    private function registros(array $r): Collection
    {
        return collect($r['identidades'])->flatMap(fn ($i) => $i['certificados'])->merge($r['excluidos']);
    }

    private function registro(array $r, string $origen, int $id): array
    {
        $x = $this->registros($r)->first(fn ($c) => $c['origen'] === $origen && $c['id'] === $id);
        $this->assertNotNull($x, "falta {$origen} #{$id}");

        return $x;
    }

    private function descargar(?string $token): TestResponse
    {
        return $this->actingAs($this->superAdmin())->get(route('credential-flow.buscador-documento.descargar', ['token' => $token ?? str_repeat('a', 40)]));
    }

    private function emitido(Lote $lote, string $nombre, string $documento): Emision
    {
        $p = $this->participante($lote, $nombre, $documento);
        $this->emitirPor($lote, $p)->assertCreated();

        return Emision::query()->where('participante_id', $p->id)->firstOrFail();
    }

    private function conMarcadorQa(Emision $e): void
    {
        $d = $e->diseno_snapshot;
        $d['elements'][] = ['type' => 'text', 'field' => null, 'text' => 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA', 'x' => 10, 'y' => 10, 'width' => 300, 'height' => 20];
        DB::table('cf_emisiones')->where('id', $e->id)->update(['diseno_snapshot' => json_encode($d)]);
    }

    private function descargasAuditadas(): Collection
    {
        return Movimiento::query()->where('tipo', 'descarga')->where('modulo', 'credential-flow')->orderBy('id')->get();
    }

    // ── Tokens ──────────────────────────────────────────────────────────────

    public function test_la_busqueda_entrega_token_solo_a_lo_que_se_puede_descargar(): void
    {
        $oficial = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $qa = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $this->conMarcadorQa($qa);
        $revocada = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $revocada), ['motivo' => 'Revocación ficticia'])->assertOk();
        $danada = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        DB::table('cf_emisiones')->where('id', $danada->id)->update(['diseno_snapshot' => '{"elements":[]}']);

        $r = $this->buscar('1000001');
        $token = fn (array $c) => $c['descarga']['token'] ?? null;
        $this->assertMatchesRegularExpression('/^[A-Za-z0-9]{40}$/', (string) $token($this->registro($r, 'moderno', $oficial->id)));
        foreach ([$qa->id, $revocada->id, $danada->id] as $id) {
            $this->assertNull($token($this->registro($r, 'moderno', $id)), "sin token para #{$id}");
        }
        // Histórico sin PDF congelado: sin token (el buscador nunca lo genera).
        $this->assertNull($token($this->registro($r, 'historico', $this->idCert(1))));
        $this->registros($r)->each(fn ($c) => $this->assertSame($c['descarga']['permitida'], $token($c) !== null));
        // Duplicados y excluidos tampoco.
        $this->registros($this->buscar('2000001'))->where('descarga.motivo', ConsultaPorDocumento::DUPLICADO)->each(fn ($c) => $this->assertNull($token($c)));
    }

    public function test_la_ruta_de_descarga_solo_lleva_el_token_nunca_el_documento_ni_el_id(): void
    {
        $ruta = Route::getRoutes()->getByName('credential-flow.buscador-documento.descargar');
        $this->assertSame(['GET', 'HEAD'], $ruta->methods());
        $this->assertSame(['token'], $ruta->parameterNames());
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        $url = route('credential-flow.buscador-documento.descargar', $token);
        // La URL es exactamente el prefijo fijo + el token opaco: sin documento, sin id, sin cadena de consulta.
        $this->assertSame('/admin/credential-flow/buscar-documento/descargar/'.$token, parse_url($url, PHP_URL_PATH));
        $this->assertNull(parse_url($url, PHP_URL_QUERY));
        $this->assertStringNotContainsString('1000001', $url);
    }

    // ── Descarga moderna ────────────────────────────────────────────────────

    public function test_descarga_moderna_sirve_el_pdf_verificado_y_audita_sin_la_cedula(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $descargasAntes = DB::table('cf_descargas')->count();
        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];

        $res = $this->descargar($token)->assertOk()->assertHeader('Content-Type', 'application/pdf');
        $this->assertSame(AlmacenEmisiones::leerVerificado($e->fresh()), $res->getContent());
        $this->assertStringContainsString('no-store', (string) $res->headers->get('Cache-Control'));
        $this->assertStringContainsString('attachment', (string) $res->headers->get('Content-Disposition'));
        $this->assertStringNotContainsString('1000001', (string) $res->headers->get('Content-Disposition'));
        $this->assertSame('nosniff', $res->headers->get('X-Content-Type-Options'));

        $m = $this->descargasAuditadas()->sole();
        $this->assertSame([$this->superAdmin()->getKey(), 'ok', 'moderno:'.$e->id, $e->pdf_hash, ConsultaPorDocumento::huella('1000001')],
            [$m->user_id, $m->metadata['resultado'], $m->metadata['registro'], $m->metadata['sha256'], $m->metadata['documento_huella']]);
        $json = json_encode($m->toArray(), JSON_UNESCAPED_UNICODE);
        foreach (['1000001', '1.000.001', 'ANA UNO'] as $dato) {
            $this->assertStringNotContainsString($dato, $json);
        }
        $this->assertSame($descargasAntes, DB::table('cf_descargas')->count(), 'el panel nunca escribe en cf_descargas');
    }

    public function test_un_pdf_alterado_no_se_sirve_y_queda_auditado(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        Storage::disk('local')->put($e->pdf_archivo, $this->pdfContenido('alterado'));

        $res = $this->descargar($token)->assertStatus(409);
        $this->assertStringNotContainsString('%PDF', $res->getContent());
        $this->assertSame(DescargasBuscador::INTEGRIDAD, $this->descargasAuditadas()->sole()->metadata['resultado']);
    }

    // ── Histórico ───────────────────────────────────────────────────────────

    public function test_descarga_historica_solo_si_ya_estaba_congelado_y_nunca_genera(): void
    {
        $id = $this->idCert(1);
        app(CongeladorLegado::class)->servir(CertificadoLegado::findOrFail($id));   // lo congela el flujo normal, no el buscador
        $c = CertificadoLegado::findOrFail($id);
        $archivos = Storage::disk('local')->allFiles();
        $intentos = DB::table('cf_certificados_legado')->sum('intentos_generacion');
        $token = $this->registro($this->buscar('1000001'), 'historico', $id)['descarga']['token'];

        $res = $this->descargar($token)->assertOk();
        $this->assertSame(Storage::disk('local')->get($c->pdf_archivo), $res->getContent());
        $this->assertSame([$archivos, $intentos], [Storage::disk('local')->allFiles(), DB::table('cf_certificados_legado')->sum('intentos_generacion')]);
        $this->assertSame(['historico:'.$id, $c->pdf_hash], [$this->descargasAuditadas()->sole()->metadata['registro'], $this->descargasAuditadas()->sole()->metadata['sha256']]);
    }

    // ── Token ligado a búsqueda, sesión, usuario y tiempo ──────────────────

    public function test_tokens_inventados_ajenos_vencidos_o_de_una_busqueda_anterior_no_sirven(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $this->descargar(str_repeat('Z', 40))->assertNotFound();
        $this->actingAs($this->superAdmin())->get('/admin/credential-flow/buscar-documento/descargar/corto')->assertNotFound();

        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        // De otro administrador (la entrada de la sesión pertenece a otro usuario).
        $clave = DescargasBuscador::SESION;
        $tokens = session($clave);
        session([$clave => array_map(fn ($t) => ['usuario' => 999999] + $t, $tokens)]);
        $this->descargar($token)->assertNotFound();
        session([$clave => $tokens]);
        $this->descargar($token)->assertOk();

        // Una búsqueda nueva invalida los tokens anteriores.
        $this->buscar('3000001');
        $this->descargar($token)->assertNotFound();

        // Vencido.
        $nuevo = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        $this->travel((int) config('credential_flow.buscador_documento.token_minutos') + 1)->minutes();
        $this->descargar($nuevo)->assertNotFound();
        $this->assertSame(1, $this->descargasAuditadas()->where('metadata.resultado', 'ok')->count());
    }

    public function test_se_revalida_el_estado_justo_antes_de_descargar(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $otro = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $r = $this->buscar('1000001');
        [$t1, $t2] = [$this->registro($r, 'moderno', $e->id)['descarga']['token'], $this->registro($r, 'moderno', $otro->id)['descarga']['token']];

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Revocación ficticia'])->assertOk();
        Participante::query()->whereKey($otro->participante_id)->first()->delete();

        $this->assertStringNotContainsString('%PDF', $this->descargar($t1)->assertStatus(409)->getContent());
        $this->descargar($t2)->assertStatus(409);
        $this->assertSame([ConsultaPorDocumento::REVOCADA, DescargasBuscador::YA_NO_DISPONIBLE], $this->descargasAuditadas()->pluck('metadata.resultado')->all());
    }

    // ── Permisos e interruptor ─────────────────────────────────────────────

    public function test_apagado_404_y_solo_super_admin(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        $url = route('credential-flow.buscador-documento.descargar', $token);

        foreach ([$this->admin(), $this->comercial()] as $u) {
            $this->actingAs($u)->get($url)->assertForbidden();
        }
        config(['credential_flow.buscador_documento.activo' => false]);
        $this->actingAs($this->superAdmin())->get($url)->assertNotFound();
        $this->assertCount(0, $this->descargasAuditadas());
    }

    public function test_limites_por_minuto_y_por_dia_por_super_admin(): void
    {
        config(['credential_flow.buscador_documento.descarga_limite_por_minuto' => 2, 'credential_flow.buscador_documento.descarga_limite_por_dia' => 3]);
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];

        $this->descargar($token)->assertOk();
        $this->descargar($token)->assertOk();
        $this->descargar($token)->assertStatus(429);
        $this->travel(2)->minutes();
        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        $this->descargar($token)->assertOk();
        $this->descargar($token)->assertStatus(429);   // ya van 3 en el día
        $this->assertSame(3, $this->descargasAuditadas()->count());
    }

    public function test_los_limites_por_omision_son_30_por_minuto_y_200_por_dia(): void
    {
        $this->assertSame([30, 200], [config('credential_flow.buscador_documento.descarga_limite_por_minuto'), config('credential_flow.buscador_documento.descarga_limite_por_dia')]);
    }

    // ── Seguimiento ─────────────────────────────────────────────────────────

    public function test_seguimiento_distingue_portal_y_panel_con_los_registros_reales(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $id = $this->idCert(1);
        $fila = fn (array $x) => DB::table('cf_descargas')->insert($x + ['created_at' => now(), 'updated_at' => now()]);
        $fila(['emision_id' => $e->id, 'via' => 'portal', 'origen' => 'credential_flow', 'descargado_at' => '2026-11-01 10:00:00']);
        $fila(['emision_id' => $e->id, 'via' => 'portal', 'origen' => 'credential_flow', 'descargado_at' => '2026-11-02 11:00:00']);
        // El histórico sintético ya trae descargas importadas de este certificado: se cuentan las reales, sin inventar ninguna.
        $previas = DB::table('cf_descargas')->where('certificado_legado_id', $id)->where('via', 'portal')->count();
        $fila(['certificado_legado_id' => $id, 'via' => 'portal', 'origen' => 'legado_importado', 'descargado_at' => '2099-03-01 09:00:00']);

        $sin = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['seguimiento'];
        $this->assertSame(['total' => 2, 'ultima' => '2026-11-02 11:00:00', 'sistema_anterior' => 0], $sin['portal']);
        $this->assertSame(['total' => 0, 'ultima' => null], $sin['panel']);
        $esperado = ['total' => $previas + 1, 'ultima' => '2099-03-01 09:00:00',
            'sistema_anterior' => DB::table('cf_descargas')->where('certificado_legado_id', $id)->where('via', 'portal')->where('origen', 'legado_importado')->count()];
        $this->assertSame($esperado, $this->registro($this->buscar('1000001'), 'historico', $id)['seguimiento']['portal']);

        $token = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'];
        $this->descargar($token)->assertOk();
        $this->descargar($token)->assertOk();
        $panel = $this->registro($this->buscar('1000001'), 'moderno', $e->id)['seguimiento']['panel'];
        $this->assertSame(2, $panel['total']);
        $this->assertNotNull($panel['ultima']);
        $this->assertSame(2, $this->registro($this->buscar('1000001'), 'moderno', $e->id)['seguimiento']['portal']['total'], 'las del panel no se cuentan como portal');
    }

    // ── Participantes eliminados ───────────────────────────────────────────

    public function test_un_participante_eliminado_sale_de_la_busqueda_y_su_auditoria_se_conserva(): void
    {
        $e = $this->emitido($this->loteCon(0), 'ANA UNO PRUEBA', 'C.C. 1.000.001');
        $this->descargar($this->registro($this->buscar('1000001'), 'moderno', $e->id)['descarga']['token'])->assertOk();

        Participante::query()->whereKey($e->participante_id)->first()->delete();
        $this->assertNull($this->registros($this->buscar('1000001'))->first(fn ($c) => $c['origen'] === 'moderno' && $c['id'] === $e->id));
        $this->assertSame('moderno:'.$e->id, $this->descargasAuditadas()->sole()->metadata['registro']);
    }
}
