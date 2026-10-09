<?php

namespace Tests\Feature\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;

/** Histórico → Casos por revisar: permisos, solo lectura, privacidad, filtros, paginación, detalle por tipo y N+1 (Fase 10A). */
class ConciliacionesAdminTest extends ConciliacionesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->detectar();
    }

    private function lista(array $q = []): TestResponse
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.index', $q));
    }

    private function detalle(int $id): TestResponse
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.casos.show', $id));
    }

    private function props(TestResponse $r): array
    {
        return $r->assertOk()->viewData('page')['props'];
    }

    private function idCaso(string $tipo, ?callable $filtro = null): int
    {
        $c = $this->casos($tipo);

        return (int) ($filtro ? $c->first($filtro) : $c->first())->id;
    }

    private function consultas(callable $accion): int
    {
        return count($this->sentencias($accion));
    }

    /** Casos extra (copias con otra clave) para probar la paginación y el número de consultas. */
    private function masCasos(int $n): void
    {
        $molde = (array) DB::table('cf_conciliaciones')->orderBy('id')->first();
        unset($molde['id']);
        for ($i = 1; $i <= $n; $i++) {
            $id = DB::table('cf_conciliaciones')->insertGetId(['clave_idempotencia' => 'extra:'.$i, 'referencia_clave' => 'extra'.$i] + $molde);
            DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $id, 'accion' => 'detectado', 'created_at' => now()]);
        }
    }

    /**
     * Un caso de «solo código» como los 40 que YA existían antes de 10B-1.5 (el detector actual ya no los crea): se inserta a mano igual que
     * lo hacía el detector, para seguir probando su detalle.
     */
    private function casoPorCodigoPreexistente(): int
    {
        $grupo = (string) DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_conciliacion')->whereIn('grupo_duplicado', function ($q) {
            $q->select('grupo_duplicado')->from('cf_certificados_legado')->whereNotNull('codigo_legado')->where('conciliacion_estado', 'pendiente_conciliacion');
        })->value('grupo_duplicado');
        $id = DB::table('cf_conciliaciones')->insertGetId([
            'tipo' => 'conflicto_variantes', 'estado' => 'abierto', 'evento_id' => (int) DB::table('cf_certificados_legado')->where('grupo_duplicado', $grupo)->value('evento_id'), 'referencia_tipo' => 'grupo_duplicado',
            'referencia_clave' => $grupo, 'motivo_origen' => 'DIF_VERIF', 'clave_idempotencia' => 'conflicto_variantes:grupo_duplicado:'.$grupo, 'created_at' => now(), 'updated_at' => now(),
        ]);
        foreach (DB::table('cf_certificados_legado')->where('grupo_duplicado', $grupo)->pluck('id') as $cert) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $id, 'certificado_legado_id' => $cert, 'rol' => 'variante', 'created_at' => now(), 'updated_at' => now()]);
        }
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $id, 'accion' => 'detectado', 'created_at' => now()]);

        return (int) $id;
    }

    // ── Permisos ────────────────────────────────────────────────────────────────────────────────────────

    public function test_sin_sesion_va_al_login_y_un_rol_no_permitido_recibe_403(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_REVISION_DOCUMENTO);
        $urls = [route('credential-flow.historico.casos.index'), route('credential-flow.historico.casos.show', $id)];

        foreach ($urls as $url) {
            $this->get($url)->assertRedirect(route('login'));
        }
        foreach ($urls as $url) {
            $this->actingAs($this->comercial())->get($url)->assertForbidden();
        }
        foreach ($urls as $url) {
            $this->actingAs($this->admin())->get($url)->assertOk();
            $this->actingAs($this->superAdmin())->get($url)->assertOk();
        }
    }

    public function test_usa_el_mismo_grupo_de_permisos_que_el_resto_de_credential_flow(): void
    {
        foreach (['credential-flow.historico.casos.index', 'credential-flow.historico.casos.show'] as $nombre) {
            $ruta = Route::getRoutes()->getByName($nombre);
            $this->assertContains('auth', $ruta->gatherMiddleware());
            $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
        }
    }

    // ── Solo lectura ────────────────────────────────────────────────────────────────────────────────────

    public function test_las_rutas_de_lectura_son_get_y_las_unicas_de_escritura_son_las_tres_acciones_de_plantilla(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'historico/casos'));

        $lectura = $rutas->filter(fn ($r) => in_array('GET', $r->methods(), true));
        $this->assertCount(3, $lectura);   // bandeja, detalle y el asistente de reemplazo (10B-2B-2B)
        foreach ($lectura as $r) {
            $this->assertSame(['GET', 'HEAD'], $r->methods());
        }
        $escritura = $rutas->reject(fn ($r) => in_array('GET', $r->methods(), true));
        $this->assertEqualsCanonicalizing(['credential-flow.historico.casos.identidad.crear', 'credential-flow.historico.casos.identidad.revocar', 'credential-flow.historico.casos.identidad.aprobar-masiva', 'credential-flow.historico.casos.identidad.revocar-aprobacion', 'credential-flow.historico.casos.especial.evidencia', 'credential-flow.historico.casos.especial.invalidar-evidencia', 'credential-flow.historico.casos.especial.reabrir', 'credential-flow.historico.casos.aprobar-candidata', 'credential-flow.historico.casos.confirmar-renderizable', 'credential-flow.historico.casos.aportar-plantilla', 'credential-flow.historico.casos.consolidar-codigo', 'credential-flow.historico.casos.reemplazo.clon', 'credential-flow.historico.casos.reemplazo.diseno', 'credential-flow.historico.casos.reemplazo.preview', 'credential-flow.historico.casos.reemplazo.emitir', 'credential-flow.historico.casos.consolidar-variantes', 'credential-flow.historico.casos.consolidar-nombre', 'credential-flow.historico.casos.requiere-soporte', 'credential-flow.historico.casos.descartar'], $escritura->map->getName()->all());
        foreach ($escritura as $r) {
            $this->assertSame(['POST'], $r->methods());
        }
    }

    public function test_cualquier_otro_metodo_se_rechaza_y_no_cambia_nada(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_CONFLICTO_VARIANTES);
        $antes = $this->firmaConciliaciones().$this->firmaHistorico();
        $admin = $this->admin();

        foreach (['post', 'put', 'patch', 'delete'] as $metodo) {
            foreach (['/admin/credential-flow/historico/casos', "/admin/credential-flow/historico/casos/{$id}"] as $url) {
                $this->actingAs($admin)->{$metodo}($url)->assertStatus(405);
            }
        }

        $this->assertSame($antes, $this->firmaConciliaciones().$this->firmaHistorico());
    }

    public function test_ver_la_bandeja_y_el_detalle_no_escribe_en_ninguna_tabla(): void
    {
        $sentencias = $this->sentencias(function () {
            $this->props($this->lista());
            foreach (DB::table('cf_conciliaciones')->pluck('id') as $id) {
                $this->props($this->detalle((int) $id));
            }
        });

        foreach ($sentencias as $sql) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace)\b/i', $sql);
        }
    }

    // ── Bandeja ─────────────────────────────────────────────────────────────────────────────────────────

    public function test_la_bandeja_lista_los_casos_con_sus_columnas(): void
    {
        $total = DB::table('cf_conciliaciones')->count();

        $this->lista()->assertOk()->assertInertia(fn (Assert $p) => $p->component('CredentialFlow/Historico/Casos')->has('casos.data', $total)->where('casos.total', $total)->has('resumen')->has('opciones.tipos', 6)->has('opciones.estados', 4)->where('filtros.tipo', ''));

        $fila = collect($this->props($this->lista())['casos']['data'])->firstWhere('id', $this->idCaso(Conciliacion::TIPO_PLANTILLA_CANDIDATA));
        $this->assertSame('Plantilla con candidata', $fila['tipo_info']['etiqueta']);
        $this->assertSame('Abierto', $fila['estado_info']['etiqueta']);
        $this->assertSame('Curso Gamma', $fila['evento']['nombre']);
        $this->assertSame(1, $fila['afectados']);
        $this->assertNotNull($fila['detectado_at']);
        $this->assertNotNull($fila['motivo']);
    }

    public function test_el_listado_no_muestra_datos_personales(): void
    {
        $json = json_encode($this->props($this->lista()));

        foreach ([self::NOMBRE, self::DOCUMENTO, self::CORREO, self::TOKEN, self::NOMBRE_P3, self::DOC_P3, self::CORREO_P3, 'HUGO', 'hugo@example.test', 'GINA', 'JULIA', 'IVAN', '2000002', '73.156.827', 'BETO', 'ELENA', 'KARLA', 'TINA', 'NORA', 'OSCAR'] as $privado) {
            $this->assertStringNotContainsString($privado, $json, "El listado expone «{$privado}».");
        }
        // Tampoco los códigos legado (num_verificacion).
        foreach (['9301', '9302', '9303', '9501', '205'] as $codigo) {
            $this->assertStringNotContainsString('"'.$codigo.'"', $json);
        }
    }

    public function test_filtra_por_tipo_estado_y_evento(): void
    {
        $cuenta = fn (array $q) => $this->props($this->lista($q))['casos']['total'];
        $this->assertSame(DB::table('cf_conciliaciones')->where('tipo', 'revision_documento')->count(), $cuenta(['tipo' => 'revision_documento']));

        $id = $this->idCaso(Conciliacion::TIPO_REVISION_DOCUMENTO);
        DB::table('cf_conciliaciones')->where('id', $id)->update(['estado' => 'descartado']);
        $this->assertSame(1, $cuenta(['estado' => 'descartado']));
        $this->assertSame(DB::table('cf_conciliaciones')->count() - 1, $cuenta(['estado' => 'abierto']));
        $this->assertSame(0, $cuenta(['estado' => 'resuelto']));

        $evento = $this->idEvento('Curso Gamma');
        $this->assertSame(DB::table('cf_conciliaciones')->where('evento_id', $evento)->count(), $cuenta(['evento' => $evento]));
        $this->assertSame(1, $cuenta(['evento' => $evento, 'tipo' => 'plantilla_candidata']));
        $this->assertSame(0, $cuenta(['evento' => $evento, 'tipo' => 'revision_documento', 'estado' => 'resuelto']));
    }

    public function test_el_resumen_cuenta_por_tipo_y_por_estado(): void
    {
        $r = $this->props($this->lista())['resumen'];

        $this->assertSame(DB::table('cf_conciliaciones')->count(), $r['total']);
        $this->assertSame(DB::table('cf_conciliaciones')->where('tipo', 'conflicto_variantes')->count(), $r['por_tipo']['conflicto_variantes']);
        $this->assertSame(0, $r['por_estado']['resuelto']);
        $this->assertSame(DB::table('cf_conciliaciones')->count(), $r['por_estado']['abierto']);
    }

    public function test_los_filtros_invalidos_se_rechazan(): void
    {
        foreach ([['tipo' => 'inventado'], ['estado' => 'inventado'], ['evento' => 'abc'], ['evento' => '0']] as $q) {
            $this->lista($q)->assertSessionHasErrors(array_key_first($q));
        }
    }

    public function test_la_paginacion_es_del_servidor(): void
    {
        $this->masCasos(60);
        $total = DB::table('cf_conciliaciones')->count();

        $p1 = $this->props($this->lista())['casos'];
        $p2 = $this->props($this->lista(['page' => 2]))['casos'];

        $this->assertSame($total, $p1['total']);
        $this->assertCount(25, $p1['data']);
        $this->assertCount(25, $p2['data']);
        $this->assertSame(2, $p2['current_page']);
        $this->assertSame([], array_intersect(array_column($p1['data'], 'id'), array_column($p2['data'], 'id')));
    }

    public function test_los_eventos_del_filtro_son_solo_los_que_tienen_casos(): void
    {
        $eventos = $this->props($this->lista())['opciones']['eventos'];

        $this->assertEqualsCanonicalizing(DB::table('cf_conciliaciones')->whereNotNull('evento_id')->distinct()->pluck('evento_id')->map(fn ($i) => (int) $i)->all(), array_column($eventos, 'valor'));
    }

    // ── Detalle por tipo ────────────────────────────────────────────────────────────────────────────────

    public function test_detalle_de_conflicto_muestra_variantes_y_diferencias_con_datos_enmascarados(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_CONFLICTO_VARIANTES, fn ($c) => str_contains((string) $c->motivo_origen, 'DIF_NOMBRE'));
        $caso = $this->props($this->detalle($id))['caso'];
        $e = $caso['evidencia'];

        $this->assertSame('Conflicto entre variantes', $caso['tipo_info']['etiqueta']);
        $this->assertCount(2, $e['variantes']);
        $this->assertContains('Nombre', $e['diferencias']);
        $this->assertFalse($e['nombre_igual_conservador']);
        $this->assertFalse($e['canonico_actual']);
        $this->assertSame(['id', 'nombre', 'tipo_documento', 'documento', 'correos', 'tiene_codigo', 'descargas', 'conciliacion', 'canonico', 'rol'], array_keys($e['variantes'][0]));
        $this->assertSame('H*** N****', $e['variantes'][0]['nombre']);
        $this->assertSame('h***@example.test', $e['variantes'][0]['correos'][0]);
        $this->assertSame('****002', $e['variantes'][0]['documento']);
        // Nombres realmente distintos: nunca consolidar; solo «requiere soporte» (10B-2B-1).
        $this->assertSame('emitir_reemplazo', $caso['accion']['clave']);   // 10B-3C-2: nombre aprobado (el soporte sigue siendo la alternativa)
        $this->assertTrue($caso['acciones_disponibles']);
    }

    public function test_detalle_de_conflicto_por_codigo_senala_el_codigo_y_las_descargas(): void
    {
        $id = $this->casoPorCodigoPreexistente();
        $e = $this->props($this->detalle($id))['caso']['evidencia'];

        $this->assertSame(['Código'], $e['diferencias']);
        $this->assertSame([false, true], collect($e['variantes'])->pluck('tiene_codigo')->sort()->values()->all());
        $this->assertSame(0, $e['descargas_total']);
    }

    public function test_detalle_de_conflicto_cuenta_las_descargas_historicas_de_cada_variante(): void
    {
        $id = $this->casoPorCodigoPreexistente();
        $variante = $this->certsDelCaso($id)[0];
        DB::table('cf_descargas')->insert(['certificado_legado_id' => $variante, 'via' => 'portal', 'origen' => 'legado_importado', 'descargado_at' => now(), 'created_at' => now(), 'updated_at' => now()]);

        $e = $this->props($this->detalle($id))['caso']['evidencia'];

        $this->assertSame(1, $e['descargas_total']);
        $this->assertSame(1, collect($e['variantes'])->firstWhere('id', $variante)['descargas']);
    }

    public function test_detalle_de_documento_en_revision_muestra_motivo_y_documento_enmascarado(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_REVISION_DOCUMENTO, fn ($c) => $c->motivo_origen === 'DOC_SEPARADORES');
        $caso = $this->props($this->detalle($id))['caso'];
        $d = $caso['evidencia']['certificados'][0];

        $this->assertStringContainsString('puntos o comas', $d['motivo_tecnico']);
        $this->assertSame('******.827', $d['documento']);
        $this->assertSame('Documento en revisión', $d['conciliacion']['etiqueta']);
        $this->assertStringNotContainsString('73.156.827', json_encode($caso));
        $this->assertStringNotContainsString('FELIPE', json_encode($caso));
    }

    public function test_detalle_de_identidad_ambigua_no_usa_similitud_y_marca_los_correos_compartidos(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_IDENTIDAD_AMBIGUA, fn ($c) => $c->motivo_origen === 'CORREO_CRUZA_GRUPOS');
        $caso = $this->props($this->detalle($id))['caso'];
        $e = $caso['evidencia'];

        $this->assertSame(2, $e['grupos_conservadores']);
        $this->assertCount(2, $e['grupos']);
        $this->assertSame(1, $e['correos_compartidos']);
        $this->assertTrue($e['grupos'][0]['correos'][0]['compartido']);
        $this->assertSame('h***@example.test', $e['grupos'][0]['correos'][0]['mascara']);
        $this->assertStringContainsString('sin tildes', $e['nota']);
        $this->assertStringContainsString('No se usa similitud', $e['nota']);
        $json = json_encode($caso);
        foreach (['hugo@example.test', 'HUGO NUEVE', '2000002'] as $privado) {
            $this->assertStringNotContainsString($privado, $json);
        }
        $this->assertStringNotContainsString('similar', strtolower(json_encode(array_diff_key($e, ['nota' => 1]))));
    }

    public function test_una_identidad_con_cientos_de_eventos_acota_la_lista_y_conserva_el_total(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_IDENTIDAD_AMBIGUA, fn ($c) => $c->motivo_origen === 'CORREO_CRUZA_GRUPOS');
        $cert = (array) DB::table('cf_certificados_legado')->where('id', $this->certsDelCaso($id)[0])->first();
        unset($cert['id']);
        foreach (range(1, 30) as $i) {
            $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento extra '.$i, 'nombre_normalizado' => 'evento extra '.$i, 'origen' => 'legado', 'estado' => 'cerrado', 'created_at' => now(), 'updated_at' => now()]);
            $nuevo = DB::table('cf_certificados_legado')->insertGetId(['evento_id' => $evento] + $cert);
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $id, 'certificado_legado_id' => $nuevo, 'rol' => 'grupo_1']);
        }

        $e = $this->props($this->detalle($id))['caso']['evidencia'];

        $this->assertSame(31, $e['eventos']['total']);
        $this->assertCount(10, $e['eventos']['items']);
        $this->assertSame(31, $e['grupos'][0]['eventos']['total']);
        $this->assertCount(10, $e['grupos'][0]['eventos']['items']);
    }

    public function test_detalle_de_plantilla_candidata_muestra_la_evidencia_sin_asociar(): void
    {
        $caso = $this->props($this->detalle($this->idCaso(Conciliacion::TIPO_PLANTILLA_CANDIDATA)))['caso'];
        $e = $caso['evidencia'];

        $this->assertStringContainsString('Plantilla histórica no localizada', $e['mensaje']);
        $this->assertSame(1, $e['certificados']);
        $this->assertSame('Curso Gamma', $e['eventos'][0]['nombre']);
        $this->assertSame(12, strlen($e['candidata']['sha']));
        $this->assertSame('image/png', $e['candidata']['mime']);
        $this->assertNotNull($e['candidata']['dimensiones']);
        $this->assertNotNull($e['candidata']['bytes']);
        $this->assertFalse($e['candidata']['asociada']);
        $this->assertSame('Solo evidencia técnica: no se enlaza automáticamente.', $e['candidata']['evidencia_migrador']['nota']);
        $this->assertFalse($e['vista_previa']['disponible']);
        // Sigue sin asociarse: la entrada faltante conserva su estado y el contenido de la candidata no se vinculó a ningún evento.
        $this->assertSame('faltante', DB::table('cf_plantillas_legado')->where('id', (int) $this->caso(Conciliacion::TIPO_PLANTILLA_CANDIDATA)->referencia_clave)->value('estado'));
    }

    public function test_detalle_del_evento_de_extension_invalida_dice_que_el_contenido_existe_y_no_lo_corrige(): void
    {
        $antes = $this->firmaHistorico();
        $caso = $this->props($this->detalle($this->idCaso(Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO)))['caso'];
        $e = $caso['evidencia'];

        $this->assertSame('Contenido existente, tipo histórico no compatible.', $e['mensaje']);
        $this->assertSame('image/png', $e['contenido']['mime_real']);
        $this->assertTrue($e['contenido']['renderizable_potencialmente']);
        $this->assertFalse($e['contenido']['renderizable_actual']);
        $this->assertSame(12, strlen($e['contenido']['sha']));
        $this->assertArrayHasKey('extension_historica', $e['contenido']);
        $this->assertSame('Curso Delta 2023', $e['eventos'][0]['nombre']);
        $this->assertSame($antes, $this->firmaHistorico());
    }

    public function test_detalle_de_plantilla_no_localizada_sin_candidata_ni_subida(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_PLANTILLA_FALTANTE, fn ($c) => (int) $c->evento_id === $this->idEvento('Curso Zeta 2024 y 2025'));
        $caso = $this->props($this->detalle($id))['caso'];
        $e = $caso['evidencia'];

        $this->assertSame('Plantilla histórica no localizada.', $e['mensaje']);
        $this->assertArrayNotHasKey('candidata', $e);
        $this->assertArrayNotHasKey('contenido', $e);
        $this->assertTrue($caso['acciones_disponibles']);
        $this->assertSame('Aportar plantilla', $caso['accion']['etiqueta']);
    }

    public function test_el_detalle_incluye_la_bitacora_con_la_deteccion_automatica(): void
    {
        $caso = $this->props($this->detalle($this->idCaso(Conciliacion::TIPO_REVISION_DOCUMENTO)))['caso'];

        $this->assertCount(1, $caso['bitacora']);
        $this->assertSame('detectado', $caso['bitacora'][0]['accion']);
        $this->assertSame('Detección automática', $caso['bitacora'][0]['actor']);
        $this->assertSame('abierto', $caso['bitacora'][0]['estado_nuevo']);
    }

    public function test_ningun_detalle_expone_datos_personales_sin_enmascarar(): void
    {
        foreach (DB::table('cf_conciliaciones')->pluck('id') as $id) {
            $json = json_encode($this->props($this->detalle((int) $id))['caso']);
            foreach (['ANA UNO PRUEBA', 'ana.uno@example.test', 'hugo@example.test', 'HUGO NUEVE', 'IVAN ONCE', 'ivan@example.test', 'JULIA TRECE', 'julia@example.test', self::NOMBRE_P3, self::CORREO_P3, 'mario31@example.test', 'nora32@example.test', 'oscar33@example.test', 'tina@example.test', 'felipe@example.test', 'diego@example.test', self::TOKEN] as $privado) {
                $this->assertStringNotContainsString($privado, $json, "El caso {$id} expone «{$privado}».");
            }
            foreach (['2000002', '2000003', '2000004', '1000001', self::DOC_P3, self::DOC_P4, '7000001', 'ABC123'] as $documento) {
                $this->assertStringNotContainsString($documento, $json, "El caso {$id} expone el documento {$documento}.");
            }
        }
    }

    public function test_un_caso_inexistente_o_un_id_no_numerico_da_404(): void
    {
        $this->detalle(9999)->assertNotFound();
        $this->actingAs($this->admin())->get('/admin/credential-flow/historico/casos/abc')->assertNotFound();
    }

    // ── Consultas ───────────────────────────────────────────────────────────────────────────────────────

    public function test_la_bandeja_hace_las_mismas_consultas_con_pocos_o_muchos_casos(): void
    {
        $pocas = $this->consultas(fn () => $this->lista()->assertOk());
        $this->masCasos(80);
        $muchas = $this->consultas(fn () => $this->lista()->assertOk());

        $this->assertSame($pocas, $muchas);
        $this->assertLessThanOrEqual(30, $muchas);
    }

    public function test_el_detalle_no_hace_n_mas_1_aunque_el_caso_afecte_a_mas_certificados(): void
    {
        $id = $this->idCaso(Conciliacion::TIPO_PLANTILLA_FALTANTE, fn ($c) => $c->referencia_tipo === 'plantilla_legado');
        $antes = $this->consultas(fn () => $this->detalle($id)->assertOk());

        $ya = $this->certsDelCaso($id);
        $mas = DB::table('cf_certificados_legado')->whereNotIn('id', $ya)->limit(40)->pluck('id');
        foreach ($mas as $cert) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $id, 'certificado_legado_id' => $cert, 'rol' => 'afectado']);
        }
        $despues = $this->consultas(fn () => $this->detalle($id)->assertOk());

        $this->assertSame($antes, $despues);
        $this->assertLessThanOrEqual(30, $despues);
    }

    public function test_el_detalle_de_conflicto_e_identidad_tiene_un_numero_acotado_de_consultas(): void
    {
        foreach ([Conciliacion::TIPO_CONFLICTO_VARIANTES, Conciliacion::TIPO_IDENTIDAD_AMBIGUA, Conciliacion::TIPO_REVISION_DOCUMENTO, Conciliacion::TIPO_PLANTILLA_CANDIDATA, Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO] as $tipo) {
            $this->assertLessThanOrEqual(40, $this->consultas(fn () => $this->detalle($this->idCaso($tipo))->assertOk()), $tipo);
        }
    }
}
