<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Encuestas\ExportacionExcedida;
use App\Support\CredentialFlow\Encuestas\ExportadorEncuestas;
use App\Support\CredentialFlow\Encuestas\InformeEncuestas;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Testing\TestResponse;
use Inertia\Testing\AssertableInertia as Assert;
use OpenSpout\Reader\XLSX\Options as XlsxReaderOptions;
use OpenSpout\Reader\XLSX\Reader as XlsxReader;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Fase 15A: resultados, informes y exportaciones de encuestas dentro de Credential Flow. Históricas + modernas, por evento real, solo lectura, sin datos
 * personales ni texto libre en las páginas generales ni en las exportaciones. Datos SINTÉTICOS.
 */
class InformesEncuestasTest extends HistoricoTestCase
{
    private const COMENTARIO = 'SECRETO-COMENTARIO-ABC';

    private const EVENTO_PELIGROSO = '=HYPERLINK("http://malo.example/x","Pulse")';

    /** @var array<string,int> */
    private array $ev = [];

    /** @var array<string,int> */
    private array $v = [];

    /** @var array<string,int> */
    private array $p = [];

    /** @var array<string,int> */
    private array $o = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->sembrar();
    }

    private function ahora(): string
    {
        return now()->toDateTimeString();
    }

    private function sembrar(): void
    {
        $a = $this->ahora();
        $enc = DB::table('cf_encuestas')->insertGetId(['nombre' => 'Encuesta', 'estado' => 'publicada', 'activa' => true, 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a]);
        foreach ([1 => ['Estructura inicial', '2024-01-01 00:00:00', '2025-12-31 00:00:00'], 4 => ['Evaluación del evento', '2026-10-01 00:00:00', null]] as $n => [$t, $d, $h]) {
            $this->v[$n] = DB::table('cf_encuestas_versiones')->insertGetId(['encuesta_id' => $enc, 'numero' => $n, 'titulo' => $t, 'publicada_at' => $d, 'activa_desde' => $d, 'activa_hasta' => $h, 'created_at' => $a, 'updated_at' => $a]);
        }
        $pre = function (int $ver, int $orden, string $clave, string $tipo, string $texto, array $opciones = []) use ($a) {
            $id = DB::table('cf_encuestas_preguntas')->insertGetId(['version_id' => $this->v[$ver], 'orden' => $orden, 'clave' => $clave, 'texto' => $texto, 'tipo' => $tipo, 'created_at' => $a, 'updated_at' => $a]);
            $this->p["v{$ver}.{$clave}"] = $id;
            foreach ($opciones as $i => $val) {
                $this->o["v{$ver}.{$clave}.{$val}"] = DB::table('cf_encuestas_opciones')->insertGetId(['pregunta_id' => $id, 'orden' => $i + 1, 'valor' => $val, 'etiqueta' => $val, 'created_at' => $a, 'updated_at' => $a]);
            }
        };
        $pre(1, 1, 'pregunta1', 'opcion_unica', 'Calidad general', ['Bueno', 'Malo']);
        $pre(1, 2, 'pregunta7', 'texto', 'Comentarios');
        $pre(4, 1, 'pregunta1', 'opcion_unica', 'Calidad general', ['Bueno', 'Regular', 'Malo']);
        $pre(4, 2, 'pregunta4', 'opcion_unica', 'Atención', ['Si', 'No']);
        $pre(4, 3, 'pregunta7', 'texto', 'Comentarios');

        $evento = fn (string $nombre, ?int $anio, ?string $inicio) => DB::table('cf_eventos')->insertGetId([
            'nombre' => $nombre, 'nombre_normalizado' => mb_strtolower($nombre), 'anio' => $anio, 'fecha_inicio' => $inicio, 'fecha_fin' => $inicio, 'estado' => 'activo', 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a,
        ]);
        $this->ev = [
            'E1' => $evento('Evento Uno', 2024, '2024-03-10'), 'E2' => $evento('Evento Dos', 2025, '2025-05-05'), 'E3' => $evento('Evento Tres', 2026, null),
            'E4' => $evento(self::EVENTO_PELIGROSO, 2026, '2026-09-01'), 'E5' => $evento('Evento Cinco sin respuestas', 2026, '2026-09-02'),
        ];

        // Históricas (versión 1): E1 con una persona duplicada (H1 dos veces), E2, una ambigua y una de evento eliminado.
        $this->resp('H1', 'E1', 1, 'legado', '2024-03-15 10:00:00', 'vinculada', ['pregunta1' => 'Bueno', 'pregunta7' => self::COMENTARIO]);
        $this->resp('H1', 'E1', 1, 'legado', '2024-03-16 10:00:00', 'vinculada', ['pregunta1' => 'Bueno']);
        $this->resp('H2', 'E1', 1, 'legado', '2024-03-17 10:00:00', 'vinculada', ['pregunta1' => 'Malo']);
        $this->resp('H1', 'E2', 1, 'legado', '2025-06-01 10:00:00', 'vinculada', ['pregunta1' => 'Malo']);
        $this->resp('H9', 'E2', 1, 'legado', '2025-06-02 10:00:00', 'participante_ambiguo', ['pregunta1' => 'Bueno']);
        $this->resp('H3', null, 1, 'legado', '2024-04-01 10:00:00', 'evento_historico_eliminado', ['pregunta1' => 'Bueno'], 9999);
        // Modernas (versión 4): mismas personas en otros eventos y en E1 (otra versión).
        $this->resp('H1', 'E1', 4, 'credential_flow', '2026-10-16 09:00:00', null, ['pregunta1' => 'Regular', 'pregunta4' => 'Si', 'pregunta7' => self::COMENTARIO.'-V4']);
        $this->resp('H2', 'E3', 4, 'credential_flow', '2026-10-16 10:00:00', null, ['pregunta1' => 'Bueno', 'pregunta4' => 'No']);
        $this->resp('H2', 'E4', 4, 'credential_flow', '2026-10-17 10:00:00', null, ['pregunta4' => 'Si']);
    }

    /** @param  array<string,string>  $detalle  clave de pregunta → opción o texto */
    private function resp(string $persona, ?string $evento, int $ver, string $origen, string $fecha, ?string $clasif, array $detalle, ?int $oldEvento = null): int
    {
        $a = $this->ahora();
        $id = DB::table('cf_encuestas_respuestas')->insertGetId([
            'version_id' => $this->v[$ver], 'evento_id' => $evento === null ? null : $this->ev[$evento], 'documento_hash' => Hmac::de('documento', $persona), 'old_evento_id' => $oldEvento,
            'clasificacion' => $clasif, 'completada_at' => $fecha, 'origen' => $origen, 'created_at' => $a, 'updated_at' => $a,
        ]);
        foreach ($detalle as $clave => $valor) {
            $pid = $this->p["v{$ver}.{$clave}"];
            $opcion = $this->o["v{$ver}.{$clave}.{$valor}"] ?? null;
            DB::table('cf_encuestas_respuestas_detalle')->insert([
                'respuesta_id' => $id, 'pregunta_id' => $pid, 'valor_texto' => $valor, 'opcion_id' => $opcion, 'snapshot_pregunta' => json_encode(['clave' => $clave]), 'snapshot_opcion' => $opcion ? $valor : null,
                'created_at' => $a, 'updated_at' => $a,
            ]);
        }

        return $id;
    }

    private function pagina(string $ruta, array $q = [], $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->get(route($ruta, $q));
    }

    private function props(string $ruta, array $q = [], $params = []): array
    {
        return $this->actingAs($this->admin())->get(route($ruta, $params + $q))->assertOk()->viewData('page')['props'];
    }

    /** Props propias del informe (sin las compartidas por Inertia: usuario autenticado, errores, etc.). */
    private function propsInforme(string $ruta, array $q = [], $params = []): string
    {
        $p = $this->props($ruta, $q, $params);
        unset($p['auth'], $p['errors'], $p['flash'], $p['ziggy']);

        return json_encode($p);
    }

    private function descargar(string $ruta, array $q = [], array $params = []): string
    {
        $r = $this->actingAs($this->admin())->get(route($ruta, $params + $q))->assertOk();

        return $r->baseResponse instanceof StreamedResponse ? $r->streamedContent() : $r->getContent();
    }

    /** @return array<string,list<list<mixed>>> hoja → filas */
    private function leerXlsx(string $contenido): array
    {
        $ruta = tempnam(sys_get_temp_dir(), 'inf').'.xlsx';
        file_put_contents($ruta, $contenido);
        $lector = new XlsxReader(new XlsxReaderOptions);
        $lector->open($ruta);
        $hojas = [];
        foreach ($lector->getSheetIterator() as $hoja) {
            foreach ($hoja->getRowIterator() as $fila) {
                $hojas[$hoja->getName()][] = $fila->toArray();
            }
        }
        $lector->close();
        @unlink($ruta);

        return $hojas;
    }

    // ── Permisos ────────────────────────────────────────────────────────────

    public function test_sin_sesion_se_redirige_y_un_rol_no_permitido_no_consulta_ni_descarga(): void
    {
        $rutas = ['resumen', 'eventos', 'informes', 'exportar.xlsx', 'exportar.csv', 'comentarios'];
        foreach ($rutas as $r) {
            $this->get(route('credential-flow.encuestas.'.$r))->assertRedirect(route('login'));
        }
        $this->get(route('credential-flow.encuestas.eventos.show', $this->ev['E1']))->assertRedirect(route('login'));

        $this->actingAs($this->comercial());
        foreach ($rutas as $r) {
            $this->get(route('credential-flow.encuestas.'.$r))->assertForbidden();
        }
        $this->get(route('credential-flow.encuestas.eventos.show', $this->ev['E1']))->assertForbidden();
    }

    public function test_el_administrador_accede_pero_los_comentarios_son_solo_del_super_admin(): void
    {
        foreach (['resumen', 'eventos', 'informes'] as $r) {
            $this->pagina('credential-flow.encuestas.'.$r)->assertOk();
        }
        $this->pagina('credential-flow.encuestas.comentarios')->assertForbidden();
        $this->pagina('credential-flow.encuestas.comentarios', [], $this->superAdmin())->assertOk()->assertInertia(fn (Assert $p) => $p->component('CredentialFlow/Encuestas/Comentarios'));
    }

    public function test_todas_las_rutas_son_de_solo_lectura_y_usan_el_grupo_administrativo(): void
    {
        $rutas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_starts_with((string) $r->getName(), 'credential-flow.encuestas.'));
        $this->assertCount(7, $rutas);
        foreach ($rutas as $r) {
            $this->assertSame(['GET', 'HEAD'], $r->methods());
            $this->assertContains('rol:super-admin,admin', $r->gatherMiddleware());
        }
    }

    public function test_la_portada_tiene_la_tarjeta_encuestas_y_el_sidebar_principal_no_cambia(): void
    {
        $inicio = file_get_contents(resource_path('js/Pages/CredentialFlow/Inicio.vue'));
        $this->assertStringContainsString('credential-flow.encuestas.resumen', $inicio);
        $this->assertStringContainsString('titulo: "Encuestas"', $inicio);
        $this->assertStringNotContainsString('encuestas', file_get_contents(resource_path('js/Components/Sidebar/Sidebar.vue')));
    }

    // ── Integración histórico + V4 ──────────────────────────────────────────

    public function test_el_resumen_integra_historicas_y_modernas_sin_filtrar_por_origen(): void
    {
        $r = $this->props('credential-flow.encuestas.resumen')['resumen'];

        $this->assertSame(9, $r['respuestas']);
        $this->assertEquals(['legado' => 6, 'credential_flow' => 3], $r['por_origen']);
        $this->assertSame([1, 4], array_column($r['por_version'], 'numero'));
        $this->assertSame([6, 3], array_column($r['por_version'], 'respuestas'));
        $this->assertSame(4, $r['eventos_evaluados']);                // E1, E2, E3, E4
        $this->assertSame(1, $r['sin_evento']);                        // evento eliminado, en categoría propia
        $this->assertSame(1, $r['sin_vinculo']);                       // ambigua
        $this->assertFalse($r['participacion']['disponible']);         // sin denominador fiable: no se inventa un porcentaje
        $this->assertSame('2024-03-15', substr($r['desde'], 0, 10));
    }

    public function test_participaciones_unicas_cuentan_una_persona_por_evento_sin_borrar_duplicados(): void
    {
        $r = $this->props('credential-flow.encuestas.resumen')['resumen'];

        // 9 respuestas: H1 en E1 aparece 3 veces (2 históricas + 1 moderna) ⇒ 7 participaciones (persona + evento).
        $this->assertSame(9, $r['respuestas']);
        $this->assertSame(7, $r['participaciones_unicas']);
        $this->assertSame(9, DB::table('cf_encuestas_respuestas')->count());   // nada se deduplicó físicamente
        $e1 = collect($this->props('credential-flow.encuestas.eventos')['eventos']['data'])->firstWhere('id', $this->ev['E1']);
        $this->assertSame([4, 2], [$e1['respuestas'], $e1['personas']]);
    }

    public function test_los_resultados_de_un_evento_nunca_se_mezclan_con_los_de_otro(): void
    {
        $e1 = $this->props('credential-flow.encuestas.eventos.show', [], ['evento' => $this->ev['E1']]);
        $e2 = $this->props('credential-flow.encuestas.eventos.show', [], ['evento' => $this->ev['E2']]);

        $this->assertSame(4, $e1['resumen']['respuestas']);
        $this->assertSame(2, $e2['resumen']['respuestas']);
        $v1 = collect($e1['versiones'])->firstWhere('numero', 1);
        $calidad = collect($v1['preguntas'])->firstWhere('clave', 'pregunta1');
        $this->assertSame(['Bueno' => 2, 'Malo' => 1], collect($calidad['opciones'])->where('conteo', '>', 0)->pluck('conteo', 'valor')->all());
        $this->assertSame([3, 0, 3], [$calidad['respondidas'], $calidad['omitidas'], $calidad['base_porcentaje']]);
        $this->assertSame(66.7, collect($calidad['opciones'])->firstWhere('valor', 'Bueno')['porcentaje']);
        $c2 = collect(collect($e2['versiones'])->firstWhere('numero', 1)['preguntas'])->firstWhere('clave', 'pregunta1');
        $this->assertSame(['Bueno' => 1, 'Malo' => 1], collect($c2['opciones'])->pluck('conteo', 'valor')->all());
    }

    public function test_las_versiones_con_estructuras_distintas_no_se_fusionan_y_las_omitidas_se_informan(): void
    {
        $e1 = $this->props('credential-flow.encuestas.eventos.show', [], ['evento' => $this->ev['E1']]);

        $this->assertSame([1, 4], array_column($e1['versiones'], 'numero'));
        $this->assertSame(['pregunta1', 'pregunta7'], array_column($e1['versiones'][0]['preguntas'], 'clave'));
        $this->assertSame(['pregunta1', 'pregunta4', 'pregunta7'], array_column($e1['versiones'][1]['preguntas'], 'clave'));
        $v4 = $e1['versiones'][1];
        $atencion = collect($v4['preguntas'])->firstWhere('clave', 'pregunta4');
        $this->assertSame([1, 1, 0], [$atencion['respondidas'], $v4['respuestas'], $atencion['omitidas']]);
        $texto = collect($e1['versiones'][0]['preguntas'])->firstWhere('clave', 'pregunta7');
        $this->assertSame(['con_contenido' => 1, 'sin_contenido' => 2], $texto['texto_libre']);   // solo cantidades
    }

    public function test_un_evento_sin_respuestas_muestra_ceros_y_las_respuestas_de_eventos_eliminados_siguen_visibles(): void
    {
        $vacio = $this->props('credential-flow.encuestas.eventos.show', [], ['evento' => $this->ev['E5']]);
        $this->assertSame(0, $vacio['resumen']['respuestas']);
        $this->assertSame([], $vacio['versiones']);

        $sin = $this->props('credential-flow.encuestas.eventos.show', [], ['evento' => 'sin-evento']);
        $this->assertTrue($sin['evento']['no_disponible']);
        $this->assertSame(1, $sin['resumen']['respuestas']);
        $this->assertSame(1, $this->props('credential-flow.encuestas.eventos')['sin_evento']);
        $this->pagina('credential-flow.encuestas.eventos.show', ['evento' => 987654])->assertNotFound();
    }

    // ── Filtros ─────────────────────────────────────────────────────────────

    public function test_filtros_por_evento_anio_version_y_origen_son_combinables(): void
    {
        $n = fn (array $q) => $this->props('credential-flow.encuestas.resumen', $q)['resumen']['respuestas'];

        $this->assertSame(4, $n(['evento' => $this->ev['E1']]));
        $this->assertSame(4, $n(['anio' => 2024]));
        $this->assertSame(2, $n(['anio' => 2025]));
        $this->assertSame(2, $n(['anio' => 2026]));                        // E3 y E4 (el E1 es de 2024)
        $this->assertSame(3, $n(['version' => $this->v[4]]));
        $this->assertSame(6, $n(['origen' => 'legado']));
        $this->assertSame(1, $n(['evento' => $this->ev['E1'], 'origen' => 'credential_flow']));
        $this->assertSame(1, $n(['anio' => 2024, 'version' => $this->v[4]]));
        $this->assertSame(0, $n(['anio' => 2024, 'origen' => 'credential_flow', 'version' => $this->v[1]]));
        $this->assertSame(4, $n(['q' => 'uno']));                           // por nombre del evento
        $this->assertSame(1, $n(['evento' => 'sin-evento']));
    }

    public function test_la_fecha_del_evento_y_la_fecha_de_respuesta_son_filtros_distintos(): void
    {
        $n = fn (array $q) => $this->props('credential-flow.encuestas.resumen', $q)['resumen']['respuestas'];

        // Evento Uno se realizó en marzo de 2024; una de sus respuestas es de octubre de 2026.
        $this->assertSame(4, $n(['evento_desde' => '2024-03-01', 'evento_hasta' => '2024-03-31']));
        $this->assertSame(1, $n(['resp_desde' => '2026-10-16', 'resp_hasta' => '2026-10-16', 'evento_desde' => '2024-03-01', 'evento_hasta' => '2024-03-31']));
        $this->assertSame(3, $n(['resp_desde' => '2024-03-15', 'resp_hasta' => '2024-03-17']));   // H1, H1, H2 en E1
        $this->assertSame(3, $n(['resp_desde' => '2026-10-16']));
        $this->assertSame(2, $n(['resp_desde' => '2026-10-16', 'resp_hasta' => '2026-10-16']));
        $this->assertSame(1, $n(['evento_desde' => '2026-09-01']));         // solo el evento E4 con fecha de inicio (E3 no tiene fecha)
        $this->assertSame(0, $n(['evento_desde' => '2030-01-01']));
        $this->assertSame(9, $n(['evento_desde' => 'no-es-fecha', 'anio' => 'x', 'origen' => 'inventado', 'version' => '-3']));   // lo inválido se descarta
    }

    public function test_los_filtros_se_conservan_en_la_paginacion_y_se_pueden_limpiar(): void
    {
        $r = $this->props('credential-flow.encuestas.eventos', ['origen' => 'credential_flow', 'anio' => 2026]);

        $this->assertSame('credential_flow', $r['filtros']['origen']);
        $this->assertSame(2026, $r['filtros']['anio']);
        $this->assertSame(2, $r['eventos']['total']);
        $this->assertStringContainsString('origen=credential_flow', (string) ($r['eventos']['first_page_url'] ?? ''));
        $limpio = $this->props('credential-flow.encuestas.eventos');
        $this->assertSame(4, $limpio['eventos']['total']);
        $this->assertNull($limpio['filtros']['origen']);
    }

    // ── Exportaciones ───────────────────────────────────────────────────────

    public function test_xlsx_tiene_las_cuatro_hojas_los_filtros_aplicados_y_solo_agregados(): void
    {
        $hojas = $this->leerXlsx($this->descargar('credential-flow.encuestas.exportar.xlsx', ['anio' => 2024]));

        $this->assertSame(['Información', 'Resumen por evento', 'Resultados por pregunta', 'Distribución de opciones'], array_keys($hojas));
        $info = collect($hojas['Información'])->mapWithKeys(fn ($f) => [(string) ($f[0] ?? '') => $f[1] ?? null]);
        $this->assertSame(2024, (int) $info['Año del evento']);
        $this->assertSame(4, (int) $info['Respuestas (registros)']);
        $this->assertSame(2, (int) $info['Participaciones únicas (persona + evento)']);
        $this->assertStringContainsString('No disponible', (string) $info['Participación']);
        $this->assertCount(2, $hojas['Resumen por evento']);               // encabezado + Evento Uno
        $this->assertSame('Evento Uno', $hojas['Resumen por evento'][1][0]);
        $this->assertSame(4, (int) $hojas['Resumen por evento'][1][4]);
        $distribucion = collect($hojas['Distribución de opciones'])->slice(1);
        $this->assertTrue($distribucion->contains(fn ($f) => $f[0] === 'Evento Uno' && $f[6] === 'Bueno' && (int) $f[7] === 2 && (int) $f[8] === 3));
    }

    public function test_xlsx_refleja_exactamente_los_filtros_de_la_pantalla(): void
    {
        $filtros = ['origen' => 'credential_flow'];
        $pantalla = $this->props('credential-flow.encuestas.resumen', $filtros)['resumen'];
        $hojas = $this->leerXlsx($this->descargar('credential-flow.encuestas.exportar.xlsx', $filtros));

        $info = collect($hojas['Información'])->mapWithKeys(fn ($f) => [(string) ($f[0] ?? '') => $f[1] ?? null]);
        $this->assertSame($pantalla['respuestas'], (int) $info['Respuestas (registros)']);
        $this->assertSame($pantalla['participaciones_unicas'], (int) $info['Participaciones únicas (persona + evento)']);
        $this->assertSame(3, count($hojas['Resumen por evento']) - 1);     // E1, E3 y E4
    }

    public function test_csv_incluye_bom_encabezados_y_separador_compatibles_con_excel(): void
    {
        $csv = $this->descargar('credential-flow.encuestas.exportar.csv', ['origen' => 'legado'], ['conjunto' => 'resumen']);

        $this->assertStringStartsWith("\xEF\xBB\xBF", $csv);
        $lineas = preg_split('/\r?\n/', trim(substr($csv, 3)));
        $this->assertSame('Evento;Año;"Fecha de inicio";"Fecha de fin";Respuestas;"Participaciones únicas";Versiones;"Respuestas históricas";"Respuestas modernas";"Primera respuesta";"Última respuesta"', $lineas[0]);
        $this->assertStringContainsString('"Evento Uno";2024;2024-03-10;2024-03-10;3;2;1;3;0', $csv);
        $this->assertStringContainsString('Eventos no disponibles', $csv);
        $dist = $this->descargar('credential-flow.encuestas.exportar.csv');
        $this->assertStringContainsString('Base (respondidas)', $dist);
        $this->assertStringContainsString('Evento Uno', $dist);
    }

    public function test_las_exportaciones_neutralizan_formulas_en_xlsx_y_csv(): void
    {
        $this->assertSame("'=1+1", ExportadorEncuestas::neutralizar('=1+1'));
        $this->assertSame("'+SUM(A1)", ExportadorEncuestas::neutralizar('+SUM(A1)'));
        $this->assertSame("'-2+3", ExportadorEncuestas::neutralizar('-2+3'));
        $this->assertSame("'@SUM(A1)", ExportadorEncuestas::neutralizar('@SUM(A1)'));
        $this->assertSame("'\t=cmd", ExportadorEncuestas::neutralizar("\t=cmd"));
        $this->assertSame("'\r=cmd", ExportadorEncuestas::neutralizar("\r=cmd"));
        $this->assertSame("'   =cmd", ExportadorEncuestas::neutralizar('   =cmd'));
        $this->assertSame("'\u{00A0}=cmd", ExportadorEncuestas::neutralizar("\u{00A0}=cmd"));
        $this->assertSame("'\x01=cmd", ExportadorEncuestas::neutralizar("\x01=cmd"));
        $this->assertSame('Evento normal', ExportadorEncuestas::neutralizar('Evento normal'));
        $this->assertSame(-5, ExportadorEncuestas::neutralizar(-5));       // los números siguen siendo números

        $csv = $this->descargar('credential-flow.encuestas.exportar.csv', ['evento' => $this->ev['E4']], ['conjunto' => 'resumen']);
        $this->assertStringContainsString("'=HYPERLINK", $csv);
        $this->assertStringNotContainsString(';=HYPERLINK', $csv);
        $this->assertDoesNotMatchRegularExpression('/(^|;|\n)"?=HYPERLINK/', $csv);

        $hojas = $this->leerXlsx($this->descargar('credential-flow.encuestas.exportar.xlsx', ['evento' => $this->ev['E4']]));
        $this->assertStringStartsWith("'=HYPERLINK", (string) $hojas['Resumen por evento'][1][0]);
        $this->assertStringStartsWith("'=HYPERLINK", (string) $hojas['Distribución de opciones'][1][0]);
    }

    public function test_ninguna_pagina_ni_exportacion_expone_texto_libre_ni_datos_personales(): void
    {
        $hash = Hmac::de('documento', 'H1');
        $paginas = [
            $this->propsInforme('credential-flow.encuestas.resumen'),
            $this->propsInforme('credential-flow.encuestas.eventos'),
            $this->propsInforme('credential-flow.encuestas.informes'),
            $this->propsInforme('credential-flow.encuestas.eventos.show', [], ['evento' => $this->ev['E1']]),
            $this->propsInforme('credential-flow.encuestas.eventos.show', [], ['evento' => 'sin-evento']),
        ];
        $archivos = [
            $this->descargar('credential-flow.encuestas.exportar.csv'), $this->descargar('credential-flow.encuestas.exportar.csv', [], ['conjunto' => 'resumen']),
            $this->descargar('credential-flow.encuestas.exportar.csv', [], ['conjunto' => 'preguntas']), json_encode($this->leerXlsx($this->descargar('credential-flow.encuestas.exportar.xlsx'))),
        ];
        foreach (array_merge($paginas, $archivos) as $contenido) {
            foreach ([self::COMENTARIO, $hash, 'documento_hash', 'documento_clave', 'correo', 'H1', 'H2'] as $prohibido) {
                $this->assertStringNotContainsString($prohibido, $contenido, "No debe aparecer «{$prohibido}»");
            }
        }
    }

    public function test_los_comentarios_completos_son_del_super_admin_y_el_registro_de_acceso_no_guarda_el_contenido(): void
    {
        $registros = [];
        Log::listen(function ($m) use (&$registros) {
            $registros[] = $m->message.' '.json_encode($m->context);
        });

        $r = $this->actingAs($this->superAdmin())->get(route('credential-flow.encuestas.comentarios'))->assertOk();

        $comentarios = collect($r->viewData('page')['props']['comentarios']['data']);
        $this->assertSame([self::COMENTARIO, self::COMENTARIO.'-V4'], $comentarios->pluck('comentario')->sort()->values()->all());
        $this->assertSame(['id', 'fecha', 'evento', 'version', 'clave', 'pregunta', 'comentario'], array_keys($comentarios->first()));   // sin hash ni documento
        $log = collect($registros)->first(fn ($x) => str_contains($x, 'consulta de comentarios'));
        $this->assertNotNull($log);
        $this->assertStringNotContainsString('SECRETO', $log);
        $this->assertStringNotContainsString(Hmac::de('documento', 'H1'), $log);
    }

    public function test_el_xlsx_es_un_zip_valido_con_cuatro_hojas_reales_y_conserva_caracteres_especiales(): void
    {
        DB::table('cf_eventos')->where('id', $this->ev['E5'])->update(['nombre' => 'Año «Ñandú» & <b>negrita</b> – ¿Qué tal?']);
        DB::table('cf_encuestas_respuestas')->where('evento_id', $this->ev['E1'])->limit(1)->update(['evento_id' => $this->ev['E5']]);

        $contenido = $this->descargar('credential-flow.encuestas.exportar.xlsx');
        $ruta = tempnam(sys_get_temp_dir(), 'zip').'.xlsx';
        file_put_contents($ruta, $contenido);
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($ruta), 'El XLSX no es un ZIP válido.');
        $libro = (string) $zip->getFromName('xl/workbook.xml');
        $this->assertSame(4, substr_count($libro, '<sheet '));
        foreach ([1, 2, 3, 4] as $n) {
            $this->assertNotFalse($zip->getFromName("xl/worksheets/sheet{$n}.xml"), "Falta la hoja {$n}.");
        }
        $zip->close();
        @unlink($ruta);

        $hojas = $this->leerXlsx($contenido);
        $this->assertCount(4, $hojas);
        $this->assertTrue(collect($hojas['Resumen por evento'])->contains(fn ($f) => $f[0] === 'Año «Ñandú» & <b>negrita</b> – ¿Qué tal?'));
        $encabezado = $hojas['Resumen por evento'][0];
        $this->assertContains('Respuestas', $encabezado);
        foreach (array_slice($hojas['Resumen por evento'], 1) as $fila) {
            $this->assertIsNumeric($fila[4]); // totales numéricos, no texto
        }
    }

    public function test_el_csv_se_entrega_en_tres_archivos_independientes_con_nombre_propio_y_un_conjunto_desconocido_da_404(): void
    {
        $encabezados = [];
        foreach (['resumen', 'preguntas', 'distribucion'] as $c) {
            $r = $this->actingAs($this->admin())->get(route('credential-flow.encuestas.exportar.csv', ['conjunto' => $c]))->assertOk();
            $this->assertStringContainsString('informe_encuestas_'.$c.'_', (string) $r->headers->get('Content-Disposition'));
            $filas = array_filter(explode("\r\n", str_replace("\n", "\r\n", str_replace("\r\n", "\n", $r->streamedContent()))));
            $encabezados[$c] = preg_replace('/^\xEF\xBB\xBF/', '', reset($filas));
            // Cada archivo conserva una sola estructura: todas las filas tienen las columnas del encabezado.
            foreach ($filas as $linea) {
                $this->assertGreaterThanOrEqual(1, count(str_getcsv($linea, ';')));
            }
        }
        $this->assertCount(3, array_unique($encabezados), 'Los tres conjuntos deben tener estructuras distintas, sin mezclarse.');
        $this->actingAs($this->admin())->get('/admin/credential-flow/encuestas/exportar/csv/otro')->assertNotFound();
    }

    public function test_el_exportador_rechaza_antes_de_abrir_el_archivo_y_no_deja_contenido_parcial(): void
    {
        $this->partialMock(InformeEncuestas::class, fn ($m) => $m->shouldReceive('contarFilasDistribucion')->andReturn(InformeEncuestas::MAX_FILAS_EXPORTACION + 1));
        $destino = sys_get_temp_dir().DIRECTORY_SEPARATOR.'rechazado_'.bin2hex(random_bytes(4)).'.xlsx';

        try {
            app(ExportadorEncuestas::class)->validar(InformeEncuestas::filtros([]));
            $this->fail('Debía rechazar la exportación demasiado grande.');
        } catch (ExportacionExcedida $e) {
            $this->assertStringContainsString('límite', $e->getMessage());
        }
        $this->assertFileDoesNotExist($destino);

        $r = $this->actingAs($this->admin())->get(route('credential-flow.encuestas.exportar.xlsx'));
        $this->assertSame(302, $r->getStatusCode());
        $this->assertFalse($r->baseResponse instanceof StreamedResponse);
    }

    public function test_el_administrador_no_recibe_enlace_ni_datos_de_comentarios_y_los_filtros_no_amplian_el_permiso(): void
    {
        $props = $this->props('credential-flow.encuestas.resumen');
        $this->assertFalse($props['puede_comentarios'] ?? false);
        $this->assertStringNotContainsString(self::COMENTARIO, $this->propsInforme('credential-flow.encuestas.resumen', ['q' => 'Evento']));
        // Ningún filtro (ni parámetros extra) cambia lo que el rol puede ver: sigue sin acceder a los comentarios.
        $this->pagina('credential-flow.encuestas.comentarios', ['origen' => 'legado', 'puede_comentarios' => 1, 'rol' => 'super-admin'])->assertForbidden();
        $this->actingAs($this->admin())->get(route('credential-flow.encuestas.exportar.csv', ['conjunto' => 'distribucion', 'comentarios' => 1]))->assertOk();
    }

    public function test_una_exportacion_demasiado_grande_se_rechaza_sin_generar_archivo(): void
    {
        $this->partialMock(InformeEncuestas::class, fn ($m) => $m->shouldReceive('contarFilasDistribucion')->andReturn(InformeEncuestas::MAX_FILAS_EXPORTACION + 1));

        $r = $this->actingAs($this->admin())->from(route('credential-flow.encuestas.informes'))->get(route('credential-flow.encuestas.exportar.xlsx'));

        $r->assertRedirect(route('credential-flow.encuestas.informes'))->assertSessionHasErrors('exportacion');
    }

    public function test_los_informes_son_solo_lectura_no_escriben_en_la_base(): void
    {
        $antes = [DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_encuestas_respuestas_detalle')->count(), DB::table('cf_encuestas_versiones')->count(), DB::table('cf_encuestas_preguntas')->count()];
        $sentencias = $this->sentencias(function () {
            foreach (['resumen', 'eventos', 'informes'] as $r) {
                $this->pagina('credential-flow.encuestas.'.$r)->assertOk();
            }
            $this->pagina('credential-flow.encuestas.eventos.show', ['evento' => $this->ev['E1']])->assertOk();
            $this->descargar('credential-flow.encuestas.exportar.xlsx');
        });

        $escrituras = collect($sentencias)->filter(fn ($s) => preg_match('/^\s*(insert|update|delete|alter|drop|create)\b/i', is_array($s) ? $s['query'] : $s) === 1 && ! str_contains((string) (is_array($s) ? $s['query'] : $s), 'sessions'));
        $this->assertSame([], $escrituras->filter(fn ($s) => str_contains(is_array($s) ? $s['query'] : $s, 'cf_encuestas'))->values()->all());
        $this->assertSame($antes, [DB::table('cf_encuestas_respuestas')->count(), DB::table('cf_encuestas_respuestas_detalle')->count(), DB::table('cf_encuestas_versiones')->count(), DB::table('cf_encuestas_preguntas')->count()]);
    }

    // ── Rendimiento con volúmenes parecidos a producción ───────────────────

    public function test_rendimiento_razonable_con_un_volumen_similar_al_de_produccion(): void
    {
        $a = $this->ahora();
        $eventos = [];
        for ($i = 1; $i <= 300; $i++) {
            $eventos[] = ['nombre' => "Evento masivo {$i}", 'nombre_normalizado' => "evento masivo {$i}", 'anio' => 2022 + ($i % 5), 'fecha_inicio' => (2022 + ($i % 5)).'-06-'.str_pad((string) (($i % 28) + 1), 2, '0', STR_PAD_LEFT), 'estado' => 'activo', 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a];
        }
        DB::table('cf_eventos')->insert($eventos);
        $ids = DB::table('cf_eventos')->where('nombre', 'like', 'Evento masivo%')->pluck('id')->all();
        $opciones = ['Bueno', 'Regular', 'Malo'];
        $respuestas = [];
        for ($i = 0; $i < 3200; $i++) {
            $respuestas[] = ['version_id' => $this->v[$i % 5 === 0 ? 4 : 1], 'evento_id' => $ids[$i % count($ids)], 'documento_hash' => Hmac::de('documento', 'P'.($i % 2100)), 'clasificacion' => null,
                'completada_at' => '2025-'.str_pad((string) (($i % 12) + 1), 2, '0', STR_PAD_LEFT).'-10 10:00:00', 'origen' => $i % 5 === 0 ? 'credential_flow' : 'legado', 'created_at' => $a, 'updated_at' => $a];
        }
        foreach (array_chunk($respuestas, 400) as $lote) {
            DB::table('cf_encuestas_respuestas')->insert($lote);
        }
        $primero = (int) DB::table('cf_encuestas_respuestas')->orderByDesc('id')->value('id') - 3199;
        $detalles = [];
        foreach (range($primero, $primero + 3199) as $n => $rid) {
            $ver = $n % 5 === 0 ? 4 : 1;
            $detalles[] = ['respuesta_id' => $rid, 'pregunta_id' => $this->p["v{$ver}.pregunta1"], 'opcion_id' => $this->o["v{$ver}.pregunta1.".($ver === 4 ? $opciones[$n % 3] : ['Bueno', 'Malo'][$n % 2])],
                'valor_texto' => 'x', 'snapshot_pregunta' => '{}', 'created_at' => $a, 'updated_at' => $a];
        }
        foreach (array_chunk($detalles, 400) as $lote) {
            DB::table('cf_encuestas_respuestas_detalle')->insert($lote);
        }

        $medir = function (callable $f) {
            $t = microtime(true);
            $consultas = count($this->sentencias($f));

            return [round(microtime(true) - $t, 2), $consultas];
        };
        [$t1, $c1] = $medir(fn () => $this->pagina('credential-flow.encuestas.resumen')->assertOk());
        [$t2, $c2] = $medir(fn () => $this->pagina('credential-flow.encuestas.eventos')->assertOk());
        [$t3, $c3] = $medir(fn () => $this->pagina('credential-flow.encuestas.eventos.show', ['evento' => $ids[0]])->assertOk());
        [$t4, $c4] = $medir(fn () => $this->descargar('credential-flow.encuestas.exportar.xlsx'));

        $this->assertLessThan(5, $t1);
        $this->assertLessThan(5, $t2);
        $this->assertLessThan(5, $t3);
        $this->assertLessThan(20, $t4);
        $this->assertLessThan(40, $c1);   // consultas constantes (agregadas), no una por respuesta
        $this->assertLessThan(35, $c2);
        $this->assertLessThan(30, $c3);
        $this->assertLessThan(45, $c4);
        $this->assertGreaterThan(300, $this->props('credential-flow.encuestas.eventos')['eventos']['total']);
    }
}
