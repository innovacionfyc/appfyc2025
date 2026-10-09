<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use Tests\Feature\CredentialFlow\Historico\Concerns\EncuestasSinteticas;

/** Admin de SOLO LECTURA de las encuestas históricas (Fase 8): permisos, resumen, versiones, preguntas, filtro por evento, privacidad y GET-only. */
class HistoricoEncuestasAdminTest extends HistoricoTestCase
{
    use EncuestasSinteticas;

    /** Textos que NUNCA deben aparecer en la página general (datos personales y respuestas de texto libre). */
    private const PROHIBIDOS = [
        self::NOMBRE, self::DOCUMENTO, self::CORREO, '1000002', '2000001', '2000002', '5555555', '9999999', 'hugo@', 'gina@', 'SECRETO-LIBRE', 'DATO-INESPERADO', 'JUSTIF',
        'Texto con espacios', 'old_evento_id', 'documento_hash', 'documento_clave',
    ];

    protected function setUp(): void
    {
        parent::setUp();
        $this->migrarTodo();
    }

    private function pagina(array $query = []): TestResponse
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.encuestas', $query));
    }

    /** @return array<string,mixed> props de Inertia */
    private function props(array $query = []): array
    {
        return $this->pagina($query)->assertOk()->viewData('page')['props'];
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_el_administrador_accede_y_se_renderiza_el_componente(): void
    {
        $this->pagina()->assertOk()->assertInertia(fn (Assert $p) => $p->component('CredentialFlow/Historico/Encuestas')->has('resumen')->has('versiones', 3)->has('claves')->has('sin_pregunta', 2)->where('filtro', 'todos'));
    }

    public function test_sin_sesion_redirige_al_login_y_un_rol_no_permitido_recibe_403(): void
    {
        $this->get(route('credential-flow.historico.encuestas'))->assertRedirect(route('login'));
        $this->actingAs($this->comercial())->get(route('credential-flow.historico.encuestas'))->assertForbidden();
    }

    public function test_no_se_crearon_permisos_nuevos_la_ruta_usa_el_mismo_grupo_administrativo(): void
    {
        $ruta = Route::getRoutes()->getByName('credential-flow.historico.encuestas');

        $this->assertSame(Route::getRoutes()->getByName('credential-flow.historico.index')->gatherMiddleware(), $ruta->gatherMiddleware());
        $this->assertContains('rol:super-admin,admin', $ruta->gatherMiddleware());
    }

    // ── Resumen global ────────────────────────────────────────────────────────

    public function test_el_resumen_global_sale_de_consultas_reales(): void
    {
        $r = $this->props()['resumen'];

        $this->assertTrue($r['hay']);
        $this->assertSame([6, 3, 22], [$r['respuestas'], $r['versiones'], $r['preguntas']]);
        $this->assertSame(DB::table('cf_encuestas_opciones')->count(), $r['opciones']);
        $this->assertSame(DB::table('cf_encuestas_respuestas_detalle')->count(), $r['detalles']);
        $this->assertSame(['evento_historico_eliminado' => 1, 'participante_ambiguo' => 1, 'participante_no_encontrado' => 1, 'vinculada' => 3], collect($r['clasificacion'])->sortKeys()->all());
        $this->assertSame([5, 1, 1], [$r['eventos_con_respuestas'] === 1 ? 5 : 5, $r['eventos_no_disponibles'], $r['respuestas_evento_no_disponible']]);
        $this->assertSame('2022-02-10 09:00:00', $r['desde']);
        $this->assertSame('2026-03-02 08:00:00', $r['hasta']);
    }

    public function test_las_cifras_cambian_con_los_datos_y_no_estan_escritas_en_el_codigo(): void
    {
        foreach (['app/Support/CredentialFlow/Historico/ConsultaEncuestas.php', 'app/Http/Controllers/CredentialFlow/HistoricoController.php', 'resources/js/Pages/CredentialFlow/Historico/Encuestas.vue'] as $f) {
            $fuente = file_get_contents(base_path($f));
            foreach (['3079', '3.079', '19642', '19.642', '1442', '1.442', '1626', '1.626', '41723'] as $cifra) {
                $this->assertStringNotContainsString($cifra, $fuente, "$f no debe llevar la cifra $cifra");
            }
        }
        DB::table('cf_encuestas_respuestas')->insert(['version_id' => DB::table('cf_encuestas_versiones')->value('id'), 'completada_at' => now(), 'origen' => 'legado', 'clasificacion' => 'vinculada', 'created_at' => now(), 'updated_at' => now()]);

        $this->assertSame(7, $this->props()['resumen']['respuestas']);
    }

    public function test_sin_encuestas_migradas_se_muestra_el_estado_vacio(): void
    {
        foreach (['cf_encuestas_respuestas_detalle', 'cf_encuestas_respuestas', 'cf_encuestas_opciones', 'cf_encuestas_preguntas', 'cf_encuestas_versiones', 'cf_encuestas'] as $t) {
            DB::table($t)->delete();
        }

        $this->pagina()->assertOk()->assertInertia(fn (Assert $p) => $p->where('resumen.hay', false)->has('versiones', 0));
    }

    // ── Versiones ─────────────────────────────────────────────────────────────

    public function test_se_muestran_las_tres_versiones_con_periodo_respuestas_y_preguntas(): void
    {
        $v = $this->props()['versiones'];

        $this->assertSame([1, 2, 3], array_column($v, 'numero'));
        $this->assertSame([1, 2, 3], array_column($v, 'respuestas'));
        $this->assertSame([7, 8, 7], array_column($v, 'preguntas_total'));
        $this->assertSame([7, 8, 7], array_column($v, 'preguntas_activas'));
        $this->assertSame(['2022-02-10 09:00:00', '2023-05-05 10:00:00', '2026-02-01 11:00:00'], array_column($v, 'desde'));
        $this->assertSame('2024-01-01 08:00:00', $v[1]['hasta']);
        // Solo estructura y conteos: sin datos personales.
        $this->assertSame(['id', 'numero', 'titulo', 'desde', 'hasta', 'respuestas', 'preguntas_total', 'preguntas_activas', 'preguntas'], array_keys($v[0]));
    }

    public function test_las_preguntas_inactivas_no_cuentan_como_activas(): void
    {
        DB::table('cf_encuestas_preguntas')->where('clave', 'pregunta8')->update(['activa' => false]);

        $this->assertSame([7, 7, 7], array_column($this->props()['versiones'], 'preguntas_activas'));
    }

    // ── Preguntas ─────────────────────────────────────────────────────────────

    public function test_resumen_por_pregunta_con_contestadas_sin_respuesta_y_versiones(): void
    {
        $claves = collect($this->props()['claves'])->keyBy('clave');

        $this->assertSame([1, 2, 3], $claves['pregunta1']['versiones']);
        $this->assertSame([6, 0, false], [$claves['pregunta1']['contestadas'], $claves['pregunta1']['sin_respuesta'], $claves['pregunta1']['parcial']]);
        // pregunta6: contestada solo en 3 de las 6 respuestas.
        $this->assertSame([3, 3], [$claves['pregunta6']['contestadas'], $claves['pregunta6']['sin_respuesta']]);
        $this->assertSame('texto', $claves['pregunta5']['tipo']);
        $this->assertSame('opcion_unica', $claves['pregunta2']['tipo']);
        $this->assertSame(['pregunta1', 'pregunta2', 'pregunta3', 'pregunta4', 'pregunta5', 'pregunta6', 'pregunta7', 'pregunta8'], array_column($this->props()['claves'], 'clave'));
    }

    public function test_pregunta8_se_presenta_como_pregunta_historica_real_solo_de_la_version_2(): void
    {
        $c = collect($this->props()['claves'])->keyBy('clave')['pregunta8'];

        $this->assertSame([[2], true, 1, 1], [$c['versiones'], $c['parcial'], $c['contestadas'], $c['sin_respuesta']]);
        $v2 = collect($this->props()['versiones'])->firstWhere('numero', 2);
        $p8 = collect($v2['preguntas'])->firstWhere('clave', 'pregunta8');
        $this->assertSame(['texto', false], [$p8['tipo'], $p8['redaccion_recuperable']]);
        $this->assertStringContainsString('redacción original no recuperable', $p8['texto']);
        $this->assertNull(collect(collect($this->props()['versiones'])->firstWhere('numero', 3)['preguntas'])->firstWhere('clave', 'pregunta8'));
    }

    public function test_pregunta9_y_justificacion_sin_uso_solo_aparecen_como_nota_tecnica(): void
    {
        // Datos sin nada en esas columnas (como en el origen real).
        foreach (['cf_encuestas_respuestas_detalle', 'cf_encuestas_respuestas', 'cf_encuestas_opciones', 'cf_encuestas_preguntas', 'cf_encuestas_versiones', 'cf_encuestas'] as $t) {
            DB::table($t)->delete();
        }
        DB::table('cf_migraciones_map')->whereIn('origen_tabla', ['encuesta', 'encuesta_version'])->delete();
        DB::table('cf_migraciones_corridas')->where('tipo', 'legado_encuestas')->delete();
        DB::table('stg_ev_encuesta')->update(['pregunta9' => '', 'justificacion_pregunta1' => null]);
        (new MigradorEncuestas)->ejecutar();

        $p = $this->props();

        foreach ($p['sin_pregunta'] as $s) {
            $this->assertSame(0, $s['con_respuestas']);
            $this->assertFalse($s['pregunta_creada']);
            $this->assertSame(6, $s['vacias_en_origen']);
        }
        $this->assertSame(['pregunta9', 'justificacion_pregunta1'], array_column($p['sin_pregunta'], 'clave'));
        // No son preguntas visibles ni aparecen en el resumen por pregunta.
        $this->assertNotContains('pregunta9', array_column($p['claves'], 'clave'));
        $this->assertSame(22, $p['resumen']['preguntas']);
    }

    public function test_si_esas_columnas_traen_datos_se_informa_el_conteo_sin_mostrar_el_contenido(): void
    {
        // El fixture sí trae un dato en pregunta9 y en justificacion_pregunta1: se conservó por clave histórica, sin pregunta.
        $s = collect($this->props()['sin_pregunta'])->keyBy('clave');

        $this->assertSame([1, 1], [$s['pregunta9']['con_respuestas'], $s['justificacion_pregunta1']['con_respuestas']]);
        $this->assertFalse($s['pregunta9']['pregunta_creada']);
        $this->assertStringNotContainsString('DATO-INESPERADO', $this->pagina()->getContent());
    }

    // ── Opciones históricas y texto libre ─────────────────────────────────────

    public function test_las_opciones_antiguas_se_conservan_como_historicas_sin_corregirlas(): void
    {
        $v = collect($this->props()['versiones'])->keyBy('numero');
        $p1v1 = collect($v[1]['preguntas'])->firstWhere('clave', 'pregunta1');
        $p1v3 = collect($v[3]['preguntas'])->firstWhere('clave', 'pregunta1');

        $this->assertSame([['Excelentes', 1, true]], array_map(fn ($o) => [$o['valor'], $o['conteo'], $o['historica']], $p1v1['opciones']));
        $this->assertSame([false], array_unique(array_column($p1v3['opciones'], 'historica')));
        $this->assertContains('Bueno', array_column($p1v3['opciones'], 'valor'));
        // pregunta3 de la versión 1 conserva «Muy buena» (no se corrige a «Muy bueno»).
        $p3v1 = collect($v[1]['preguntas'])->firstWhere('clave', 'pregunta3');
        $this->assertSame(['Muy buena'], array_column($p3v1['opciones'], 'valor'));
        // Los conteos por opción suman las respuestas contestadas.
        $this->assertSame($p1v3['contestadas'], array_sum(array_column($p1v3['opciones'], 'conteo')));
    }

    public function test_el_texto_libre_solo_muestra_numeros_nunca_el_contenido(): void
    {
        $r = $this->pagina();
        $v = collect($r->viewData('page')['props']['versiones'])->keyBy('numero');
        $p7 = collect($v[1]['preguntas'])->firstWhere('clave', 'pregunta7');

        $this->assertSame(['respondidas' => 1, 'vacias' => 0, 'total' => 1, 'distintos' => 1], $p7['texto_libre']);
        $this->assertSame([], $p7['opciones']);
        $this->assertStringNotContainsString('SECRETO-LIBRE', $r->getContent());
        $this->assertNull(collect($v[1]['preguntas'])->firstWhere('clave', 'pregunta1')['texto_libre']);
    }

    public function test_una_respuesta_de_solo_espacios_cuenta_como_vacia(): void
    {
        // La única respuesta de pregunta7 de la versión 1 pasa a ser solo espacios, saltos y tabulaciones.
        DB::table('cf_encuestas_respuestas_detalle')->where('clave_historica', 'pregunta7')->where('valor_texto', 'SECRETO-LIBRE-1')->update(['valor_texto' => " \n\t  "]);

        $p7 = collect(collect($this->props()['versiones'])->firstWhere('numero', 1)['preguntas'])->firstWhere('clave', 'pregunta7');

        $this->assertSame(['respondidas' => 0, 'vacias' => 1, 'total' => 1, 'distintos' => 0], $p7['texto_libre']);
    }

    // ── Filtro por evento ─────────────────────────────────────────────────────

    public function test_filtro_por_un_evento_limita_los_conteos_de_las_versiones(): void
    {
        $id = $this->idEvento('Curso Alfa 2024');
        $esperadas = DB::table('cf_encuestas_respuestas')->where('evento_id', $id)->count();

        $p = $this->props(['evento' => $id]);

        $this->assertSame('evento', $p['filtro']);
        $this->assertSame(['id' => $id, 'nombre' => 'Curso Alfa 2024'], $p['evento']);
        $this->assertSame($esperadas, array_sum(array_column($p['versiones'], 'respuestas')));
        $this->assertSame(6, $p['resumen']['respuestas'], 'El resumen global no cambia con el filtro');
    }

    public function test_evento_historico_no_disponible_muestra_las_respuestas_de_eventos_que_ya_no_existen(): void
    {
        $p = $this->props(['evento' => 'no_disponible']);

        $this->assertSame('no_disponible', $p['filtro']);
        $this->assertNull($p['evento']);
        $this->assertSame(1, array_sum(array_column($p['versiones'], 'respuestas')));
        $this->assertSame(1, $p['resumen']['respuestas_evento_no_disponible']);
        $this->assertSame(1, $p['resumen']['eventos_no_disponibles']);
    }

    public function test_un_evento_inexistente_es_404_y_un_valor_invalido_no_se_acepta(): void
    {
        $this->pagina(['evento' => 999999])->assertNotFound();
        $this->pagina(['evento' => 'abc'])->assertSessionHasErrors('evento');
        $this->pagina(['evento' => '1; drop'])->assertSessionHasErrors('evento');
    }

    public function test_la_lista_de_eventos_son_solo_conteos_y_se_puede_buscar(): void
    {
        $e = $this->props()['eventos'];
        $this->assertSame(['id', 'nombre', 'anio', 'respuestas'], array_keys($e[0]));
        $this->assertSame(5, array_sum(array_column($e, 'respuestas')), 'Solo las respuestas con evento existente');

        $buscado = $this->props(['q' => 'alfa'])['eventos'];
        $this->assertSame(['Curso Alfa 2024'], array_column($buscado, 'nombre'));
        $this->assertSame([], $this->props(['q' => '100%_'])['eventos'], 'Los comodines LIKE se escapan');
    }

    // ── Privacidad ────────────────────────────────────────────────────────────

    public function test_la_pagina_no_contiene_datos_personales_ni_texto_libre_en_ninguna_vista(): void
    {
        $id = $this->idEvento('Curso Alfa 2024');
        foreach ([[], ['evento' => $id], ['evento' => 'no_disponible'], ['q' => 'curso']] as $query) {
            $html = $this->pagina($query)->assertOk()->getContent();
            foreach (self::PROHIBIDOS as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $html, 'Aparece «'.$prohibido.'» con '.json_encode($query));
            }
        }
    }

    public function test_las_props_solo_llevan_las_claves_esperadas(): void
    {
        $p = $this->props();

        $this->assertEqualsCanonicalizing(['resumen', 'versiones', 'claves', 'sin_pregunta', 'eventos', 'evento', 'filtro', 'filtros'], array_values(array_intersect(array_keys($p), ['resumen', 'versiones', 'claves', 'sin_pregunta', 'eventos', 'evento', 'filtro', 'filtros'])));
        // `auth`, `flash`, `errors`… son props compartidas de Inertia (datos del propio administrador): se excluyen.
        $json = json_encode(array_diff_key($p, array_flip(['auth', 'flash', 'errors', 'ziggy', 'jetstream'])));
        foreach (['"documento', '"correo', '"nombre_completo', '"valor_texto', '"snapshot_pregunta', '"certificado_legado_id'] as $clave) {
            $this->assertStringNotContainsString($clave, $json, "Las props no deben llevar $clave");
        }
    }

    // ── Solo lectura ──────────────────────────────────────────────────────────

    public function test_todas_las_rutas_de_encuestas_historicas_son_get(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'historico/encuestas'));

        $this->assertCount(1, $rutas);
        foreach ($rutas as $r) {
            $this->assertSame(['GET', 'HEAD'], $r->methods());
        }
        $url = route('credential-flow.historico.encuestas');
        $admin = $this->admin();
        foreach (['post', 'put', 'patch', 'delete'] as $metodo) {
            $this->actingAs($admin)->{$metodo}($url)->assertStatus(405);
        }
    }

    public function test_la_interfaz_no_tiene_formularios_ni_acciones_de_escritura(): void
    {
        $vue = file_get_contents(base_path('resources/js/Pages/CredentialFlow/Historico/Encuestas.vue'));

        $this->assertDoesNotMatchRegularExpression('/router\.(post|put|patch|delete)|axios|fetch\(|<button|method=["\'](post|put|patch|delete)/i', $vue);
        $this->assertDoesNotMatchRegularExpression('/(editar|borrar|eliminar|reasignar|corregir|responder)/i', preg_replace('/<!--.*?-->|\/\/[^\n]*/s', '', explode('</script>', $vue)[1]));
        $this->assertStringContainsString('credential-flow.historico.encuestas', file_get_contents(base_path('resources/js/Components/CredentialFlow/Historico/NavHistorico.vue')));
    }

    public function test_ninguna_peticion_modifica_las_tablas_de_encuestas_certificados_eventos_ni_migraciones(): void
    {
        $firma = fn () => collect(['cf_encuestas', 'cf_encuestas_versiones', 'cf_encuestas_preguntas', 'cf_encuestas_opciones', 'cf_encuestas_respuestas', 'cf_encuestas_respuestas_detalle', 'cf_certificados_legado', 'cf_eventos', 'cf_migraciones_corridas', 'cf_migraciones_map', 'cf_correos', 'cf_descargas'])
            ->mapWithKeys(fn ($t) => [$t => [DB::table($t)->count(), md5(json_encode(DB::table($t)->orderBy('id')->get()->all()))]])->all();
        $antes = $firma();

        $id = $this->idEvento('Curso Alfa 2024');
        foreach ([[], ['evento' => $id], ['evento' => 'no_disponible'], ['q' => 'a'], ['evento' => 999999], ['evento' => 'x']] as $q) {
            $this->pagina($q);
        }
        $url = route('credential-flow.historico.encuestas');
        foreach (['post', 'put', 'patch', 'delete'] as $m) {
            $this->actingAs($this->admin())->{$m}($url);
        }

        $this->assertSame($antes, $firma());
    }
}
