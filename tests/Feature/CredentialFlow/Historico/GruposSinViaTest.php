<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\DetectorGruposSinVia;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida as No;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Identidad\GruposSinVia;
use App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;

/**
 * Fase 10B-3C-1: recuperación controlada de GRUPOS SIN VÍA por correo compartido. Un caso administrativo NO concede nada; la recuperación es una `correo_autorizado`
 * del correo compartido a UN grupo (scope [G]); el hermano que ya entra con su correo exclusivo no cambia. Los grupos sin correo o que exigen evidencia externa quedan
 * en soporte; el de plantilla inválida depende de su plantilla (sin caso de identidad).
 */
class GruposSinViaTest extends PortalTestCase
{
    private const EVID = 'Confirmado por el organizador del evento mediante correo institucional.';

    private const MOT = 'Decisión administrativa de prueba.';

    /** @var array<string,array{clave:string,ids:array<string,int>}> */
    private array $docs = [];

    protected function setUp(): void
    {
        parent::setUp();
        // D1: S entra con c (exclusivo); G solo tiene x, compartido con S → correo_compartido.
        $this->doc('cc', '3000090', ['LAURA RIOS S' => ['c' => ['c90@example.test', 'x90@example.test'], 'ev' => 3], 'LAURA RIOS G' => ['c' => ['x90@example.test'], 'ev' => 1]]);
        // D2: el grupo que comparte x tiene un nombre realmente distinto → evidencia_externa.
        $this->doc('ev', '3000091', ['ALFREDO MONTOYA' => ['c' => ['c91@example.test', 'x91@example.test'], 'ev' => 3], 'ZENAIDA QUINTERO' => ['c' => ['x91@example.test'], 'ev' => 1]]);
        // D3: G sin ningún correo → sin_correo.
        $this->doc('sc', '3000092', ['ROSA DIAZ S' => ['c' => ['c92@example.test'], 'ev' => 3], 'ROSA DIAZ G' => ['c' => [], 'ev' => 1]]);
        // D4: G con correo propio pero su fila no es habilitante → sin_fila_habilitante.
        $this->doc('sf', '3000093', ['ELSA MORA S' => ['c' => ['c93@example.test'], 'ev' => 3], 'ELSA MORA G' => ['c' => ['g93@example.test'], 'ev' => 1, 'estado' => 'pendiente_plantilla']]);
        // D5: dos grupos objetivo en un mismo documento (cada uno comparte un correo distinto con S).
        $this->doc('d5', '3000094', ['PEDRO LEON S' => ['c' => ['c94@example.test', 'x94@example.test', 'y94@example.test'], 'ev' => 3], 'PEDRO LEON G1' => ['c' => ['x94@example.test'], 'ev' => 1], 'PEDRO LEON G2' => ['c' => ['y94@example.test'], 'ev' => 2]]);
    }

    /** @param array<string,array{c:list<string>,ev:int,estado?:string}> $spec */
    private function doc(string $k, string $clave, array $spec): void
    {
        $molde = (array) DB::table('cf_certificados_legado')->where('id', $this->idCert(40))->first();
        unset($molde['id']);
        foreach ($spec as $nombre => $s) {
            $id = DB::table('cf_certificados_legado')->insertGetId(['documento' => $clave, 'documento_clave' => $clave, 'nombre_completo' => $nombre, 'evento_id' => $s['ev'], 'codigo_legado' => null, 'conciliacion_estado' => $s['estado'] ?? 'ok'] + $molde);
            foreach ($s['c'] as $i => $mail) {
                DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $mail, 'correo_normalizado' => $mail, 'estado' => 'valido', 'orden' => $i + 1, 'es_principal' => 0, 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()]);
            }
            $this->docs[$k]['ids'][$nombre] = $id;
        }
        $this->docs[$k]['clave'] = $clave;
    }

    private function id(string $k, string $nombre): int
    {
        return $this->docs[$k]['ids'][$nombre];
    }

    private function g(string $nombre): string
    {
        return NombreConservador::grupoId($nombre);
    }

    private function flags(bool $d, bool $m): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => $d, 'credential_flow.identidad.multi_scope_enabled' => $m]);
    }

    private function detectar(): array
    {
        return app(DetectorGruposSinVia::class)->ejecutar();
    }

    private function caso(string $k, string $motivo = GruposSinVia::MOTIVO_COMPARTIDO): Conciliacion
    {
        return Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento($this->docs[$k]['clave']))->where('motivo_origen', $motivo)->firstOrFail();
    }

    private function svc(): DecisionesIdentidad
    {
        return app(DecisionesIdentidad::class);
    }

    private function autorizar(Conciliacion $caso, string $nombre, string $correo): array
    {
        return $this->svc()->crear($caso->id, $this->admin()->id, 'correo_autorizado', self::MOT, ['grupo' => $this->g($nombre), 'correo_hmac' => EvidenciaIdentidad::hashCorreo($correo), 'evidencia' => self::EVID, 'reforzada' => true]);
    }

    private function codigo(callable $f): string
    {
        try {
            $f();
        } catch (No $e) {
            return $e->codigo;
        }

        return 'OK';
    }

    private function ingresar(string $doc, string $correo): TestResponse
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => $doc, 'correo' => $correo])->assertRedirect(route('portal.codigo'));
        $codigo = $this->ultimoCodigo();
        $this->assertNotNull($codigo, 'se esperaba un OTP');

        return $this->validarCodigo($codigo);
    }

    private function sinOtp(string $doc, string $correo): void
    {
        Mail::fake();
        DB::table('cf_accesos_otp')->delete();
        $this->post(route('portal.solicitar'), ['documento' => $doc, 'correo' => $correo]);
        $this->assertNull($this->ultimoCodigo(), "$correo no debe recibir OTP");
    }

    // ── Clasificación y read-model ───────────────────────────────────────────

    public function test_clasifica_cada_grupo_sin_via_por_su_causa_sin_mezclarlas(): void
    {
        $a = app(GruposSinVia::class);
        $causa = fn (string $k, string $n) => $a->analizarDocumento($this->docs[$k]['clave'])[$this->g($n)]['causa'];

        $this->assertSame('correo_compartido', $causa('cc', 'LAURA RIOS G'));
        $this->assertNull($causa('cc', 'LAURA RIOS S'), 'el hermano ya entra con su correo exclusivo');
        $this->assertSame('evidencia_externa', $causa('ev', 'ZENAIDA QUINTERO'));
        $this->assertSame('sin_correo', $causa('sc', 'ROSA DIAZ G'));
        $this->assertSame('sin_fila_habilitante', $causa('sf', 'ELSA MORA G'));
        $this->assertSame(['correo_compartido', 'correo_compartido'], [$causa('d5', 'PEDRO LEON G1'), $causa('d5', 'PEDRO LEON G2')]);
    }

    public function test_el_read_model_informa_causa_resolucion_y_dependencia_sin_pii(): void
    {
        $antes = (new GruposSinVia)->reporte();
        $this->detectar();
        $r = (new GruposSinVia)->reporte();

        $this->assertSame([$antes['grupos_accesibles'], $antes['grupos_sin_via']], [$r['grupos_accesibles'], $r['grupos_sin_via']], 'abrir casos no cambia el gate');
        $mios = collect($r['items'])->whereIn('documento_hash', array_map(fn ($d) => EvidenciaIdentidad::hashDocumento($d['clave']), $this->docs))->keyBy(fn ($i) => substr($i['grupo_hash'], 0, 10));
        $de = fn (string $n) => $mios[substr($this->g($n), 0, 10)];

        $this->assertSame(['correo_compartido', 'correo_autorizado', 'administrativa'], [$de('LAURA RIOS G')['causa'], $de('LAURA RIOS G')['resolucion'], $de('LAURA RIOS G')['dependencia']]);
        $this->assertSame($this->caso('cc')->id, $de('LAURA RIOS G')['caso_id']);
        $this->assertSame(['evidencia_externa', 'externa'], [$de('ZENAIDA QUINTERO')['causa'], $de('ZENAIDA QUINTERO')['dependencia']]);
        $this->assertSame(['sin_correo', 'externa'], [$de('ROSA DIAZ G')['causa'], $de('ROSA DIAZ G')['dependencia']]);
        $this->assertSame(['sin_fila_habilitante', 'resolver_plantilla', 'tecnica', null], [$de('ELSA MORA G')['causa'], $de('ELSA MORA G')['resolucion'], $de('ELSA MORA G')['dependencia'], $de('ELSA MORA G')['caso_id']]);
        $this->assertSame('Este grupo recuperará acceso cuando se resuelva su plantilla.', $de('ELSA MORA G')['mensaje']);
        $json = json_encode($r);
        foreach (['LAURA', 'RIOS', 'c90@example.test', 'x90@example.test', '3000090'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);
        }
    }

    // ── Detector ─────────────────────────────────────────────────────────────

    public function test_el_detector_crea_un_caso_por_documento_y_causa_con_su_estado(): void
    {
        $r = $this->detectar();

        $this->assertSame([2, 1, 1], [$r['por_motivo'][GruposSinVia::MOTIVO_COMPARTIDO]['nuevos'], $r['por_motivo'][GruposSinVia::MOTIVO_EVIDENCIA]['nuevos'], $r['por_motivo'][GruposSinVia::MOTIVO_SIN_CORREO]['nuevos'] - ($this->casosPreexistentesSinCorreo())], 'D1 y D5 (cc), D2 (ev), D3 (sc)');
        $this->assertSame('abierto', $this->caso('cc')->estado);
        $this->assertSame('requiere_soporte', $this->caso('ev', GruposSinVia::MOTIVO_EVIDENCIA)->estado);
        $this->assertSame('requiere_soporte', $this->caso('sc', GruposSinVia::MOTIVO_SIN_CORREO)->estado);
        $this->assertSame(0, Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento($this->docs['sf']['clave']))->count(), 'sin_fila_habilitante NO recibe caso de identidad');
        // D5: UN caso con sus dos grupos objetivo; el pivote lleva todos los certificados del documento.
        $d5 = $this->caso('d5');
        $this->assertSame(3, DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $d5->id)->count());
        $this->assertEqualsCanonicalizing([$this->g('PEDRO LEON G1'), $this->g('PEDRO LEON G2')], app(GruposSinVia::class)->objetivosDe($d5));
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $this->caso('cc')->id)->where('accion', 'detectado')->count());
    }

    private function casosPreexistentesSinCorreo(): int
    {
        return 1;   // el documento 3000003 del fixture del portal ya es un grupo sin correo (ANA RUIZ X)
    }

    public function test_el_detector_es_idempotente_y_no_toca_casos_existentes(): void
    {
        $this->detectar();
        $caso = $this->caso('cc');
        $caso->update(['estado' => 'requiere_soporte']);   // decisión humana posterior: el detector no la deshace
        $firma = md5(json_encode([DB::table('cf_conciliaciones')->get(), DB::table('cf_conciliaciones_certificados')->get(), DB::table('cf_conciliaciones_eventos')->get()]));

        $r = $this->detectar();

        $this->assertSame(0, array_sum(array_column($r['por_motivo'], 'nuevos')));
        $this->assertSame(0, array_sum(array_column($r['por_motivo'], 'relaciones_agregadas')));
        $this->assertSame($firma, md5(json_encode([DB::table('cf_conciliaciones')->get(), DB::table('cf_conciliaciones_certificados')->get(), DB::table('cf_conciliaciones_eventos')->get()])));
        $this->assertSame(1, Conciliacion::where('clave_idempotencia', 'like', '%:sv_cc')->where('referencia_clave', EvidenciaIdentidad::hashDocumento($this->docs['cc']['clave']))->count());
        // La simulación no escribe nada.
        $this->assertSame(0, app(DetectorGruposSinVia::class)->ejecutar(true)['por_motivo'][GruposSinVia::MOTIVO_COMPARTIDO]['nuevos']);
    }

    public function test_crear_los_casos_no_concede_acceso_alguno(): void
    {
        $this->flags(true, true);
        $this->sinOtp($this->docs['cc']['clave'], 'x90@example.test');
        $acceso = new AccesoPortal;
        $antes = md5(json_encode([$acceso->alcance($this->docs['cc']['clave'], 'c90@example.test'), $acceso->alcance($this->docs['cc']['clave'], 'x90@example.test'), $acceso->tarjetas($this->docs['cc']['clave'])]));

        $this->detectar();

        $this->assertSame($antes, md5(json_encode([$acceso->alcance($this->docs['cc']['clave'], 'c90@example.test'), $acceso->alcance($this->docs['cc']['clave'], 'x90@example.test'), $acceso->tarjetas($this->docs['cc']['clave'])])));
        $this->sinOtp($this->docs['cc']['clave'], 'x90@example.test');
        $r = app(ScopeIdentidadAprobado::class)->resolver($this->docs['cc']['clave'], 'x90@example.test');
        $this->assertFalse($r->habilita(), 'el correo compartido sigue bloqueado: existir el caso no abre nada');
    }

    // ── Backend: qué admite cada caso ────────────────────────────────────────

    public function test_el_backend_solo_admite_correo_autorizado_del_correo_compartido_para_un_grupo_objetivo(): void
    {
        $this->detectar();
        $cc = $this->caso('cc');
        $admin = $this->admin()->id;
        $grupos = [$this->g('LAURA RIOS S'), $this->g('LAURA RIOS G')];

        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->svc()->crear($cc->id, $admin, 'misma_persona', self::MOT, ['grupos' => $grupos, 'evidencia' => self::EVID, 'confirmo' => true, 'evidencia_externa' => true])));
        $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->svc()->crear($cc->id, $admin, 'personas_distintas', self::MOT, ['grupos' => $grupos, 'confirmo' => true])));
        // El hermano accesible NO es un grupo objetivo.
        $this->assertSame(No::GRUPO_NO_PERTENECE, $this->codigo(fn () => $this->autorizar($cc, 'LAURA RIOS S', 'x90@example.test')));
        // El correo exclusivo del hermano no pertenece a G; y un correo no compartido no se admite.
        $this->assertSame(No::CORREO_NO_PERTENECE, $this->codigo(fn () => $this->autorizar($cc, 'LAURA RIOS G', 'c90@example.test')));
        $this->assertSame(No::CONFIRMACION_REQUERIDA, $this->codigo(fn () => $this->svc()->crear($cc->id, $admin, 'correo_autorizado', self::MOT, ['grupo' => $this->g('LAURA RIOS G'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo('x90@example.test'), 'evidencia' => self::EVID])), 'confirmación reforzada obligatoria');
        $this->assertSame(No::EVIDENCIA_REQUERIDA, $this->codigo(fn () => $this->svc()->crear($cc->id, $admin, 'correo_autorizado', self::MOT, ['grupo' => $this->g('LAURA RIOS G'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo('x90@example.test'), 'reforzada' => true])));
        $this->assertSame(0, DB::table('cf_decisiones_identidad')->count());
        $this->assertSame('OK', $this->codigo(fn () => $this->autorizar($cc, 'LAURA RIOS G', 'x90@example.test')));

        // Los casos que exigen evidencia externa o no tienen correo solo admiten soporte / no resoluble.
        foreach ([['ev', GruposSinVia::MOTIVO_EVIDENCIA, 'ZENAIDA QUINTERO', 'x91@example.test'], ['sc', GruposSinVia::MOTIVO_SIN_CORREO, 'ROSA DIAZ G', 'c92@example.test']] as [$k, $m, $n, $c]) {
            $caso = $this->caso($k, $m);
            $this->assertSame(No::TIPO_NO_ADMITIDO, $this->codigo(fn () => $this->autorizar($caso, $n, $c)), $m);
            $this->assertSame('OK', $this->codigo(fn () => $this->svc()->crear($caso->id, $admin, 'no_resoluble', self::MOT)));
            $this->assertSame('requiere_soporte', $caso->fresh()->estado);
        }
    }

    public function test_la_pantalla_ofrece_solo_lo_que_cada_motivo_admite_y_sin_pii(): void
    {
        $this->detectar();
        $props = fn (Conciliacion $c) => $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $c->id))->assertOk()->viewData('page')['props']['caso'];

        $cc = $props($this->caso('cc'))['identidad'];
        $this->assertSame(['grupo_sin_via', ['correo_autorizado', 'requiere_soporte', 'no_resoluble'], [$this->g('LAURA RIOS G')]], [$cc['modo'], $cc['tipos'], $cc['objetivos']]);
        $this->assertStringContainsString('NO significa que sean la misma persona', $cc['mensajes_sin_via'][0]);
        $sc = $props($this->caso('sc', GruposSinVia::MOTIVO_SIN_CORREO))['identidad'];
        $this->assertSame(['requiere_soporte', 'no_resoluble'], $sc['tipos']);
        $this->assertStringContainsString('No existe un correo histórico propio para autenticar este grupo', $sc['mensajes_sin_via'][0]);
        $this->assertStringContainsString('evidencia externa', $props($this->caso('ev', GruposSinVia::MOTIVO_EVIDENCIA))['identidad']['mensajes_sin_via'][0]);

        $json = json_encode($props($this->caso('cc')));
        foreach (['LAURA', 'RIOS', 'c90@example.test', 'x90@example.test', '3000090'] as $pii) {
            $this->assertStringNotContainsString($pii, $json);   // las máscaras `c***@dominio` conservan el dominio por política; nunca la dirección completa
        }
        // Listado sin PII y con los motivos etiquetados.
        $lista = json_encode($this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index'))->assertOk()->viewData('page')['props'], JSON_UNESCAPED_UNICODE);
        $this->assertStringContainsString('Grupo sin vía', $lista);
        foreach (['LAURA', 'c90@example.test'] as $pii) {
            $this->assertStringNotContainsString($pii, $lista);
        }
    }

    public function test_dos_grupos_que_se_disputan_el_unico_correo_pasan_a_evidencia_externa(): void
    {
        // Ambos solo tienen x: una autorización asigna el correo a UN grupo, así que no se pueden recuperar los dos con esta vía.
        $this->doc('dis', '3000095', ['MARTA NIETO S' => ['c' => ['c95@example.test', 'x95@example.test'], 'ev' => 3], 'MARTA NIETO G1' => ['c' => ['x95@example.test'], 'ev' => 1], 'MARTA NIETO G2' => ['c' => ['x95@example.test'], 'ev' => 2]]);
        $a = app(GruposSinVia::class)->analizarDocumento($this->docs['dis']['clave']);
        $this->assertSame(['evidencia_externa', 'evidencia_externa', null], [$a[$this->g('MARTA NIETO G1')]['causa'], $a[$this->g('MARTA NIETO G2')]['causa'], $a[$this->g('MARTA NIETO S')]['causa']]);
        $this->detectar();
        $this->assertSame(0, Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento('3000095'))->where('motivo_origen', GruposSinVia::MOTIVO_COMPARTIDO)->count());
        $this->assertSame('requiere_soporte', Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento('3000095'))->where('motivo_origen', GruposSinVia::MOTIVO_EVIDENCIA)->value('estado'));
    }

    public function test_un_grupo_de_correo_compartido_con_fila_no_habilitante_depende_de_su_plantilla(): void
    {
        $this->doc('pen', '3000096', ['JUAN MESA S' => ['c' => ['c96@example.test', 'x96@example.test'], 'ev' => 3], 'JUAN MESA G' => ['c' => ['x96@example.test'], 'ev' => 1, 'estado' => 'pendiente_plantilla']]);
        $a = app(GruposSinVia::class)->analizarDocumento('3000096');
        $this->assertSame(['sin_fila_habilitante', 'correo_compartido'], [$a[$this->g('JUAN MESA G')]['causa'], $a[$this->g('JUAN MESA G')]['motivo']], 'la clasificación técnica original se conserva');
        $this->detectar();
        $this->assertSame(0, Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento('3000096'))->count(), 'sin caso de identidad');
        // Al resolver su plantilla (fila habilitante) vuelve a ser un objetivo recuperable en la siguiente pasada del detector.
        DB::table('cf_certificados_legado')->where('id', $this->id('pen', 'JUAN MESA G'))->update(['conciliacion_estado' => 'ok']);
        $this->detectar();
        $this->assertSame('abierto', Conciliacion::where('referencia_clave', EvidenciaIdentidad::hashDocumento('3000096'))->where('motivo_origen', GruposSinVia::MOTIVO_COMPARTIDO)->value('estado'));
    }

    // ── Recuperación de extremo a extremo (HTTP real) ────────────────────────

    public function test_la_autorizacion_abre_solo_ese_grupo_y_el_hermano_no_cambia(): void
    {
        $this->flags(true, true);
        $this->detectar();
        $d = $this->docs['cc']['clave'];
        $sId = $this->id('cc', 'LAURA RIOS S');
        $gId = $this->id('cc', 'LAURA RIOS G');
        $acceso = new AccesoPortal;
        $huella = fn () => md5(json_encode([$acceso->alcance($d, 'c90@example.test'), $acceso->tarjetas($d, $this->g('LAURA RIOS S'))]));
        $antes = $huella();

        $this->sinOtp($d, 'x90@example.test');
        $caso = $this->caso('cc');
        $this->actingAs($this->admin())->post(route('credential-flow.historico.casos.identidad.crear', $caso->id), [
            'tipo' => 'correo_autorizado', 'motivo' => self::MOT, 'grupo' => $this->g('LAURA RIOS G'), 'correo_hmac' => EvidenciaIdentidad::hashCorreo('x90@example.test'), 'evidencia' => self::EVID, 'reforzada' => '1',
        ])->assertSessionHas('success');
        auth()->logout();

        $this->assertSame($antes, $huella(), 'el acceso del hermano no cambia');
        $this->assertSame(['resuelto', 'identidad_aplicada'], [$caso->fresh()->estado, $caso->fresh()->resolucion]);
        $this->ingresar($d, 'x90@example.test')->assertRedirect(route('portal.panel'));
        $ctx = session('cf_portal.contexto');
        $this->assertSame([$this->g('LAURA RIOS G')], $ctx['grupos'], 'scope de UN solo grupo');
        $this->assertCount(1, $this->tipos($this->get(route('portal.panel'))));
        $this->get(route('portal.descargar', $gId))->assertOk();
        $this->get(route('portal.descargar', $sId))->assertNotFound('el hermano no se abre aunque el correo exista también en él');
        $this->post(route('portal.salir'));

        // El hermano sigue entrando por su correo exclusivo, con su sesión histórica.
        $this->ingresar($d, 'c90@example.test')->assertRedirect(route('portal.panel'));
        $this->assertSame($this->g('LAURA RIOS S'), session('cf_portal.contexto')['grupo']);
        $this->assertArrayNotHasKey('scope_hash', session('cf_portal.contexto'));
        $this->get(route('portal.descargar', $gId))->assertNotFound();
    }

    public function test_revocar_mata_la_sesion_reabre_el_caso_y_el_correo_vuelve_a_quedar_bloqueado(): void
    {
        $this->flags(true, true);
        $this->detectar();
        $d = $this->docs['cc']['clave'];
        $caso = $this->caso('cc');
        $dec = $this->autorizar($caso, 'LAURA RIOS G', 'x90@example.test')['decision_id'];
        $this->ingresar($d, 'x90@example.test');
        $this->get(route('portal.panel'))->assertOk();

        $this->svc()->revocar($dec, $this->admin()->id, 'Se retira la autorización por nueva información.');

        $this->get(route('portal.panel'))->assertRedirect(route('portal.inicio'));
        $this->assertSame(['abierto', null], [$caso->fresh()->estado, $caso->fresh()->resolucion]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'identidad_acceso_revocado')->count());
        $this->sinOtp($d, 'x90@example.test');
    }

    public function test_con_dos_grupos_objetivo_el_caso_solo_se_cierra_cuando_ambos_estan_cubiertos(): void
    {
        $this->flags(true, true);
        $this->detectar();
        $caso = $this->caso('d5');

        $a = $this->autorizar($caso, 'PEDRO LEON G1', 'x94@example.test')['decision_id'];
        $this->assertSame('abierto', $caso->fresh()->estado, 'falta G2');
        $b = $this->autorizar($caso, 'PEDRO LEON G2', 'y94@example.test')['decision_id'];
        $this->assertSame(['resuelto', 'identidad_aplicada'], [$caso->fresh()->estado, $caso->fresh()->resolucion]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->where('accion', 'identidad_acceso_aplicado')->count());

        $this->svc()->revocar($a, $this->admin()->id, 'Se retira la primera autorización.');
        $this->assertSame('abierto', $caso->fresh()->estado, 'ya no están los dos cubiertos: se reabre');
        $this->svc()->revocar($b, $this->admin()->id, 'Se retira la segunda autorización.');
        $this->assertSame('abierto', $caso->fresh()->estado);
    }

    public function test_sin_los_dos_interruptores_la_autorizacion_se_registra_pero_ni_cierra_ni_concede(): void
    {
        $this->flags(false, false);
        $this->detectar();
        $caso = $this->caso('cc');
        $this->autorizar($caso, 'LAURA RIOS G', 'x90@example.test');

        $this->assertSame('abierto', $caso->fresh()->estado, 'sin los interruptores no se cierra por efecto funcional');
        $this->sinOtp($this->docs['cc']['clave'], 'x90@example.test');
        // ON/OFF: el OTP se prepara pero no hay sesión multi-grupo.
        $this->flags(true, false);
        $this->ingresar($this->docs['cc']['clave'], 'x90@example.test')->assertRedirect(route('portal.codigo'));
        $this->assertNull(session('cf_portal.contexto'));
        $this->assertSame('abierto', $caso->fresh()->estado);
    }

    public function test_el_hermano_con_un_correo_exclusivo_sigue_entrando_aunque_exista_la_autorizacion(): void
    {
        $this->flags(true, true);
        $this->detectar();
        $this->autorizar($this->caso('cc'), 'LAURA RIOS G', 'x90@example.test');
        $r = app(ScopeIdentidadAprobado::class)->resolver($this->docs['cc']['clave'], 'c90@example.test');
        $this->assertSame(['alcance_historico_normal', $this->g('LAURA RIOS S')], [$r->tipo, $r->grupoHistorico], '3C-0: lo histórico no se pierde');
    }

    /** @return list<string> */
    private function tipos(TestResponse $r): array
    {
        preg_match_all('/data-certificado="([a-z_]+)"/', $r->getContent(), $m);

        return $m[1];
    }
}
