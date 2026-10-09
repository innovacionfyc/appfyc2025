<?php

namespace Tests\Feature\CredentialFlow\Envios;

use App\Models\CredentialFlow\Envio;
use App\Support\CredentialFlow\Envios\ServicioEnvios;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** Credential Flow → Envíos: administración de SOLO LECTURA (permisos, filtros, privacidad, N+1 y ninguna mutación). */
class EnviosAdminTest extends EnviosTestCase
{
    private int $contador = 0;

    private function envio(string $estado = Envio::ACEPTADO, array $extra = []): int
    {
        $this->contador++;
        $fecha = $extra['solicitado_at'] ?? now()->subMinutes($this->contador);

        return DB::table('cf_envios')->insertGetId($extra + [
            'tipo' => 'otp_acceso', 'categoria' => 'seguridad', 'plantilla' => 'otp_acceso', 'plantilla_version' => 1, 'origen_tipo' => 'otp', 'origen_id' => $this->contador,
            'destinatario_hash' => ServicioEnvios::hashDestinatario("persona{$this->contador}@example.test"), 'destinatario_mascara' => 'p***@example.test',
            'clave_idempotencia' => 'otp_acceso:otp:'.(1000 + $this->contador), 'estado' => $estado, 'intentos' => $estado === Envio::PENDIENTE ? 0 : 1, 'max_intentos' => 2,
            'solicitado_at' => $fecha, 'aceptado_at' => $estado === Envio::ACEPTADO ? $fecha : null, 'created_at' => $fecha, 'updated_at' => $fecha,
        ]);
    }

    private function pagina(array $q = []): TestResponse
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.envios.index', $q));
    }

    private function props(array $q = []): array
    {
        return $this->pagina($q)->assertOk()->viewData('page')['props'];
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_el_administrador_accede_y_se_renderiza_el_componente(): void
    {
        $this->envio();

        $this->pagina()->assertOk()->assertInertia(fn (Assert $p) => $p->component('CredentialFlow/Envios/Index')->has('envios.data', 1)->has('resumen')->has('opciones.estados', 5)->where('filtros.estado', ''));
    }

    public function test_sin_sesion_va_al_login_y_un_rol_no_permitido_recibe_403(): void
    {
        $this->get(route('credential-flow.envios.index'))->assertRedirect(route('login'));
        $this->actingAs($this->comercial())->get(route('credential-flow.envios.index'))->assertForbidden();
        $this->actingAs($this->superAdmin())->get(route('credential-flow.envios.index'))->assertOk();
    }

    public function test_usa_el_mismo_grupo_de_permisos_que_el_resto_de_credential_flow(): void
    {
        $ruta = Route::getRoutes()->getByName('credential-flow.envios.index');

        $this->assertSame('admin/credential-flow/envios', $ruta->uri());
        $this->assertSame(Route::getRoutes()->getByName('credential-flow.index')->gatherMiddleware(), $ruta->gatherMiddleware());
        $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
    }

    // ── Contenido y privacidad ────────────────────────────────────────────────

    public function test_cada_fila_muestra_solo_los_campos_permitidos_con_textos_en_espanol(): void
    {
        $this->envio(Envio::FALLIDO_PERMANENTE, ['error_clase' => 'permanente', 'error_codigo' => 'SMTP_550', 'intentos' => 1]);

        $fila = $this->props()['envios']['data'][0];

        $this->assertEqualsCanonicalizing(['id', 'fecha', 'tipo', 'tipo_etiqueta', 'estado', 'estado_info', 'destinatario', 'intentos', 'max_intentos', 'error_codigo', 'error_texto', 'aceptado_at'], array_keys($fila));
        $this->assertSame('Código de acceso al portal', $fila['tipo_etiqueta']);
        $this->assertSame('No se envió', $fila['estado_info']['etiqueta']);
        $this->assertSame('El servidor de correo rechazó el mensaje (respuesta 550).', $fila['error_texto']);
        $this->assertSame('p***@example.test', $fila['destinatario']);
    }

    public function test_los_estados_y_errores_tienen_texto_claro_sin_jerga(): void
    {
        $etiquetas = collect($this->props()['opciones']['estados'])->pluck('etiqueta', 'valor')->all();

        $this->assertSame([
            'pendiente' => 'Pendiente', 'procesando' => 'Procesando', 'aceptado_por_transporte' => 'Aceptado por el servidor de correo',
            'fallido_temporal' => 'Falló (error temporal)', 'fallido_permanente' => 'No se envió',
        ], $etiquetas);
        $ayuda = collect($this->props()['opciones']['estados'])->firstWhere('valor', 'aceptado_por_transporte')['ayuda'];
        $this->assertStringContainsString('no confirma', $ayuda);
        $this->assertStringContainsString('bandeja de entrada', $ayuda);
    }

    public function test_nunca_se_expone_el_hash_la_direccion_el_codigo_el_documento_ni_la_ip(): void
    {
        $this->usarTransporte();
        $this->solicitar('1.000.001', self::CORREO);
        $this->envio(Envio::FALLIDO_TEMPORAL, ['error_clase' => 'temporal', 'error_codigo' => 'CONEXION']);

        $r = $this->pagina();
        $html = $r->getContent();
        $props = json_encode(array_diff_key($r->viewData('page')['props'], array_flip(['auth', 'flash', 'errors', 'ziggy'])));

        foreach ([ServicioEnvios::hashDestinatario(self::CORREO), ServicioEnvios::hashDestinatario('persona1@example.test'), self::CORREO, 'ana.uno', self::DOCUMENTO, self::NOMBRE, '127.0.0.1', 'ip_hash', 'documento_hash', 'otp_hash', 'destinatario_hash', 'clave_idempotencia', 'proveedor_referencia'] as $prohibido) {
            $this->assertStringNotContainsString($prohibido, $props, "Las props filtran «{$prohibido}»");
            $this->assertStringNotContainsString($prohibido, $html, "La página filtra «{$prohibido}»");
        }
        $this->assertStringContainsString('a***@example.test', $props);
    }

    public function test_sin_envios_se_muestra_el_estado_vacio(): void
    {
        $this->pagina()->assertOk()->assertInertia(fn (Assert $p) => $p->has('envios.data', 0)->where('resumen.total', 0));
    }

    // ── Filtros, resumen y paginación ─────────────────────────────────────────

    public function test_resumen_y_filtro_por_estado(): void
    {
        foreach ([Envio::ACEPTADO, Envio::ACEPTADO, Envio::ACEPTADO, Envio::FALLIDO_TEMPORAL, Envio::FALLIDO_PERMANENTE, Envio::PENDIENTE] as $estado) {
            $this->envio($estado);
        }

        $p = $this->props();
        $this->assertSame(['pendiente' => 1, 'procesando' => 0, 'aceptado_por_transporte' => 3, 'fallido_temporal' => 1, 'fallido_permanente' => 1], $p['resumen']['por_estado']);
        $this->assertSame(6, $p['resumen']['total']);
        $filtrado = $this->props(['estado' => 'aceptado_por_transporte']);
        $this->assertCount(3, $filtrado['envios']['data']);
        $this->assertSame(['aceptado_por_transporte'], array_unique(array_column($filtrado['envios']['data'], 'estado')));
        $this->assertSame(6, $filtrado['resumen']['total'], 'El resumen no depende del filtro de estado');
    }

    public function test_filtro_por_tipo_y_por_fechas(): void
    {
        $this->envio(Envio::ACEPTADO, ['solicitado_at' => '2026-03-10 09:00:00']);
        $this->envio(Envio::ACEPTADO, ['solicitado_at' => '2026-03-20 18:30:00']);
        $this->envio(Envio::ACEPTADO, ['solicitado_at' => '2026-04-02 08:00:00']);

        $this->assertCount(3, $this->props(['tipo' => 'otp_acceso'])['envios']['data']);
        $this->assertCount(1, $this->props(['desde' => '2026-03-15', 'hasta' => '2026-03-20'])['envios']['data'], 'El día final se incluye completo');
        $this->assertCount(2, $this->props(['desde' => '2026-03-20'])['envios']['data']);
        $this->assertCount(2, $this->props(['hasta' => '2026-03-31'])['envios']['data']);
        $this->assertCount(0, $this->props(['desde' => '2026-05-01'])['envios']['data']);
    }

    public function test_los_filtros_invalidos_se_rechazan_con_mensajes_en_espanol(): void
    {
        $this->pagina(['estado' => 'enviado'])->assertSessionHasErrors(['estado' => 'Elige un estado de la lista.']);
        $this->pagina(['tipo' => 'otro'])->assertSessionHasErrors(['tipo' => 'Elige un tipo de correo de la lista.']);
        $this->pagina(['desde' => '31/12/2026'])->assertSessionHasErrors(['desde' => 'La fecha inicial no es válida.']);
        $this->pagina(['desde' => '2026-05-10', 'hasta' => '2026-05-01'])->assertSessionHasErrors(['hasta' => 'La fecha final no puede ser anterior a la inicial.']);
        $this->pagina(['estado' => "x' OR 1=1"])->assertSessionHasErrors('estado');
    }

    public function test_paginacion_de_25_en_25_del_servidor_con_los_filtros_conservados(): void
    {
        foreach (range(1, 30) as $_) {
            $this->envio();
        }

        $p1 = $this->props(['estado' => 'aceptado_por_transporte'])['envios'];
        $p2 = $this->props(['estado' => 'aceptado_por_transporte', 'page' => 2])['envios'];

        $this->assertSame([25, 5, 30, 2], [count($p1['data']), count($p2['data']), $p1['total'], $p1['last_page']]);
        $this->assertStringContainsString('estado=aceptado_por_transporte', $p1['next_page_url']);
        $this->assertSame([], array_intersect(array_column($p1['data'], 'id'), array_column($p2['data'], 'id')));
    }

    public function test_el_orden_es_del_mas_reciente_al_mas_antiguo(): void
    {
        $viejo = $this->envio(Envio::ACEPTADO, ['solicitado_at' => '2026-01-01 10:00:00']);
        $nuevo = $this->envio(Envio::ACEPTADO, ['solicitado_at' => '2026-06-01 10:00:00']);

        $this->assertSame([$nuevo, $viejo], array_column($this->props()['envios']['data'], 'id'));
    }

    public function test_no_hay_n_mas_1_las_consultas_no_dependen_de_la_cantidad_de_envios(): void
    {
        $this->envio(Envio::FALLIDO_TEMPORAL, ['error_clase' => 'temporal', 'error_codigo' => 'CONEXION']);
        $this->actingAs($this->admin());
        $pocas = count(array_filter($this->sentencias(fn () => $this->get(route('credential-flow.envios.index'))), fn ($s) => str_contains($s, 'cf_envios')));
        foreach (range(1, 24) as $_) {
            $this->envio(Envio::FALLIDO_TEMPORAL, ['error_clase' => 'temporal', 'error_codigo' => 'CONEXION']);
        }

        $muchas = count(array_filter($this->sentencias(fn () => $this->get(route('credential-flow.envios.index'))), fn ($s) => str_contains($s, 'cf_envios')));

        $this->assertSame($pocas, $muchas);
        $this->assertLessThanOrEqual(3, $muchas, 'Una consulta de lista, una de conteo y una de resumen');
    }

    // ── Solo lectura ──────────────────────────────────────────────────────────

    public function test_la_unica_ruta_de_envios_es_get_y_los_demas_metodos_dan_405(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'credential-flow/envios'));

        $this->assertCount(1, $rutas);
        $this->assertSame(['GET', 'HEAD'], $rutas->first()->methods());
        $admin = $this->admin();
        foreach (['post', 'put', 'patch', 'delete'] as $metodo) {
            $this->actingAs($admin)->{$metodo}(route('credential-flow.envios.index'))->assertStatus(405);
        }
    }

    public function test_la_interfaz_no_tiene_botones_de_reenvio_ni_acciones_de_escritura(): void
    {
        $vue = file_get_contents(base_path('resources/js/Pages/CredentialFlow/Envios/Index.vue'));

        $this->assertDoesNotMatchRegularExpression('/router\.(post|put|patch|delete)|axios|fetch\(|method=["\'](post|put|patch|delete)/i', $vue);
        $this->assertDoesNotMatchRegularExpression('/Reenviar|Eliminar|Borrar|Editar|Enviar de nuevo/i', explode('</script>', $vue)[1]);
        $this->assertStringContainsString('credential-flow.envios.index', file_get_contents(base_path('resources/js/Pages/CredentialFlow/Inicio.vue')));
    }

    public function test_consultar_no_modifica_envios_intentos_ni_desafios(): void
    {
        $this->envio(Envio::FALLIDO_TEMPORAL, ['error_clase' => 'temporal', 'error_codigo' => 'CONEXION']);
        $this->envio();
        $this->crearDesafio();
        $firma = fn () => collect(['cf_envios', 'cf_envios_intentos', 'cf_accesos_otp'])->mapWithKeys(fn ($t) => [$t => [DB::table($t)->count(), md5(json_encode(DB::table($t)->orderBy('id')->get()->all()))]])->all();
        $antes = $firma();

        foreach ([[], ['estado' => 'aceptado_por_transporte'], ['desde' => '2026-01-01'], ['estado' => 'x']] as $q) {
            $this->pagina($q);
        }
        foreach (['post', 'put', 'patch', 'delete'] as $m) {
            $this->actingAs($this->admin())->{$m}(route('credential-flow.envios.index'));
        }

        $this->assertSame($antes, $firma());
    }
}
