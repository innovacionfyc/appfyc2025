<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Historico\PresentadorHistorico;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/** Listado y detalle de eventos históricos, filtros, orden, paginación, privacidad y permisos. */
class HistoricoEventosTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrarSintetico();
    }

    private function listado(array $query = []): Assert
    {
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.index', $query))->assertOk();
        $capturado = null;
        $r->assertInertia(function (Assert $page) use (&$capturado) {
            $capturado = $page;
            $page->component('CredentialFlow/Historico/Eventos');
        });

        return $capturado;
    }

    /** @return list<string> nombres de los eventos de la página, en orden */
    private function nombres(array $query = []): array
    {
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.index', $query))->assertOk();

        return collect($r->viewData('page')['props']['eventos']['data'])->pluck('nombre')->all();
    }

    // ── Permisos ──────────────────────────────────────────────────────────────

    public function test_sin_sesion_redirige_y_otro_rol_recibe_403_en_todas_las_pantallas_del_historico(): void
    {
        $cert = $this->idCert(1);
        $evento = $this->idEvento('Curso Alfa 2024');
        $rutas = [
            route('credential-flow.historico.index'), route('credential-flow.historico.eventos.show', $evento), route('credential-flow.historico.certificados.show', $cert),
            route('credential-flow.historico.plantillas.index'), route('credential-flow.historico.buscar'),
        ];

        foreach ($rutas as $url) {
            $this->get($url)->assertRedirect(route('login'));
            $this->actingAs($this->comercial())->get($url)->assertForbidden();
            $this->actingAs($this->admin())->get($url)->assertOk();
            $this->actingAs($this->superAdmin())->get($url)->assertOk();
            auth()->logout();
        }
    }

    /** Única excepción deliberada (Fase 10B-1): las tres acciones de resolución de PLANTILLAS de los casos de conciliación (POST, con motivo y confirmación). */
    private const ACCIONES_DE_CONCILIACION = [
        'credential-flow.historico.casos.identidad.crear', 'credential-flow.historico.casos.identidad.revocar', 'credential-flow.historico.casos.identidad.aprobar-masiva', 'credential-flow.historico.casos.identidad.revocar-aprobacion', 'credential-flow.historico.casos.especial.evidencia', 'credential-flow.historico.casos.especial.invalidar-evidencia', 'credential-flow.historico.casos.especial.reabrir', 'credential-flow.historico.casos.aprobar-candidata', 'credential-flow.historico.casos.confirmar-renderizable', 'credential-flow.historico.casos.aportar-plantilla', 'credential-flow.historico.casos.consolidar-codigo', 'credential-flow.historico.casos.consolidar-variantes', 'credential-flow.historico.casos.consolidar-nombre', 'credential-flow.historico.casos.requiere-soporte', 'credential-flow.historico.casos.descartar', 'credential-flow.historico.casos.reemplazo.clon', 'credential-flow.historico.casos.reemplazo.diseno', 'credential-flow.historico.casos.reemplazo.preview', 'credential-flow.historico.casos.reemplazo.emitir',
    ];

    public function test_todas_las_rutas_del_historico_son_de_solo_lectura(): void
    {
        $historicas = collect(Route::getRoutes()->getRoutes())->filter(fn ($r) => str_contains($r->uri(), 'credential-flow/historico') && ! in_array($r->getName(), self::ACCIONES_DE_CONCILIACION, true));

        $this->assertGreaterThanOrEqual(5, $historicas->count());
        foreach ($historicas as $ruta) {
            $this->assertSame(['GET', 'HEAD'], $ruta->methods(), 'Solo GET: '.$ruta->uri());
        }
        // Y ninguna otra ruta de escritura menciona el histórico: solo las 3 acciones de conciliación de plantillas, y solo POST.
        $escritura = [];
        foreach (Route::getRoutes()->getRoutes() as $ruta) {
            if (array_intersect(['POST', 'PUT', 'PATCH', 'DELETE'], $ruta->methods()) && str_contains($ruta->uri(), 'historico')) {
                $escritura[] = $ruta->getName();
                $this->assertSame(['POST'], $ruta->methods());
            }
        }
        $this->assertEqualsCanonicalizing(self::ACCIONES_DE_CONCILIACION, $escritura);
    }

    public function test_las_pantallas_nunca_escriben_en_la_base_de_datos_ni_cambian_ningun_dato(): void
    {
        $firma = fn () => md5(json_encode(array_map(fn ($t) => DB::table($t)->orderBy('id')->get(), [
            'cf_eventos', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_certificados_legado', 'cf_correos', 'cf_descargas', 'cf_migraciones_corridas', 'cf_migraciones_map',
        ])));
        $antes = $firma();
        $cert = $this->idCert(1);
        $sql = $this->sentencias(function () use ($cert) {
            $admin = $this->admin();
            foreach ([
                route('credential-flow.historico.index'), route('credential-flow.historico.index', ['anio' => 'sin', 'plantilla' => 'faltante']),
                route('credential-flow.historico.eventos.show', $this->idEvento('Curso Alfa 2024')), route('credential-flow.historico.certificados.show', $cert),
                route('credential-flow.historico.plantillas.index'), route('credential-flow.historico.buscar', ['campo' => 'codigo', 'q' => '101']),
            ] as $url) {
                $this->actingAs($admin)->get($url)->assertOk();
            }
        });

        $this->assertNotEmpty($sql);
        foreach ($sql as $s) {
            $this->assertDoesNotMatchRegularExpression('/^\s*(insert|update|delete|replace|alter|drop|create|truncate)\b/i', $s, 'Escritura detectada: '.substr($s, 0, 80));
        }
        $this->assertSame($antes, $firma());
    }

    // ── Listado ───────────────────────────────────────────────────────────────

    public function test_el_listado_muestra_los_eventos_con_sus_cifras_y_el_orden_inicial(): void
    {
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.index'))->assertOk();
        $p = $r->viewData('page')['props'];
        $eventos = collect($p['eventos']['data'])->keyBy('nombre');

        // Año más reciente primero, luego nombre; los eventos sin año, al final.
        $this->assertSame(['Curso Epsilon 2026', 'Curso Beta 2025', 'Curso Alfa 2024', 'Curso Delta 2023', 'Curso Gamma', 'Curso Zeta 2024 y 2025'], $eventos->keys()->all());
        $this->assertSame(PresentadorHistorico::SIN_ANIO, $eventos['Curso Gamma']['anio_etiqueta']);
        $this->assertSame('Sin año identificado', $eventos['Curso Zeta 2024 y 2025']['anio_etiqueta']);
        $this->assertNull($eventos['Curso Gamma']['anio'], 'No se inventa el año');
        $this->assertSame('2024', $eventos['Curso Alfa 2024']['anio_etiqueta']);

        $alfa = $eventos['Curso Alfa 2024'];
        $this->assertSame(6, $alfa['certificados']);
        $this->assertSame(2, $alfa['descargados'], 'Participantes con al menos una descarga');
        $this->assertSame(3, $alfa['descargas']);
        $this->assertSame(3, $alfa['con_codigo']);
        $this->assertSame(2, $alfa['pendiente_conciliacion']);
        $this->assertSame(0, $alfa['revision_documento']);
        $this->assertSame(0, $alfa['pendiente_plantilla']);
        $this->assertSame('Disponible', $alfa['plantilla']['etiqueta']);
        $this->assertSame('Cerrado', $alfa['estado']);

        $beta = $eventos['Curso Beta 2025'];
        $this->assertSame([6, 3, 2, 1], [$beta['certificados'], $beta['revision_documento'], $beta['pendiente_conciliacion'], $beta['pendiente_plantilla']]);
        $this->assertSame('Imagen faltante', $beta['plantilla']['etiqueta']);
        $this->assertSame('Extensión inválida', $eventos['Curso Delta 2023']['plantilla']['etiqueta']);
        $this->assertSame('Sin plantilla', $eventos['Curso Epsilon 2026']['plantilla']['etiqueta']);
        $this->assertTrue($eventos['Curso Gamma']['con_candidata']);
        $this->assertFalse($eventos['Curso Beta 2025']['con_candidata']);

        $this->assertSame(6, $p['resumen']['eventos']);
        $this->assertSame(15, $p['resumen']['certificados']);
        $this->assertSame(4, $p['resumen']['descargas']);
        $this->assertSame(2, $p['resumen']['imagenes_sin_evento']);
        $this->assertSame(['ok' => 3, 'duplicado_consolidado' => 1, 'pendiente_plantilla' => 1, 'pendiente_conciliacion' => 6, 'revision_documento' => 4], $p['resumen']['por_conciliacion']);
        $this->assertSame([2026, 2025, 2024, 2023], $p['opciones']['anios']);
    }

    public function test_el_listado_general_no_expone_datos_personales(): void
    {
        $json = json_encode($this->actingAs($this->admin())->get(route('credential-flow.historico.index'))->viewData('page')['props']);

        foreach ([self::NOMBRE, 'ANA UNO', 'GINA', 'HUGO', self::DOCUMENTO, '2000001', self::CORREO, 'example.test', self::TOKEN] as $dato) {
            $this->assertStringNotContainsString($dato, $json, "El listado de eventos no debe llevar «{$dato}»");
        }
    }

    // ── Filtros ───────────────────────────────────────────────────────────────

    /** @return array<string,array{0:array<string,string>,1:list<string>}> */
    public static function filtros(): array
    {
        return [
            'nombre' => [['q' => 'alfa'], ['Curso Alfa 2024']],
            'nombre sin tildes ni mayúsculas' => [['q' => 'EPSILON'], ['Curso Epsilon 2026']],
            'año' => [['anio' => '2025'], ['Curso Beta 2025']],
            'sin año' => [['anio' => 'sin'], ['Curso Gamma', 'Curso Zeta 2024 y 2025']],
            'plantilla ok' => [['plantilla' => 'ok'], ['Curso Alfa 2024']],
            'plantilla faltante' => [['plantilla' => 'faltante'], ['Curso Beta 2025', 'Curso Gamma', 'Curso Zeta 2024 y 2025']],
            'plantilla extensión inválida' => [['plantilla' => 'extension_invalida'], ['Curso Delta 2023']],
            'sin plantilla' => [['plantilla' => 'sin_plantilla'], ['Curso Epsilon 2026']],
            'plantilla con candidata' => [['plantilla' => 'con_candidata'], ['Curso Gamma']],
            'conciliación revisión de documento' => [['conciliacion' => 'revision_documento'], ['Curso Beta 2025', 'Curso Gamma']],
            'conciliación pendiente de plantilla' => [['conciliacion' => 'pendiente_plantilla'], ['Curso Beta 2025']],
            'conciliación duplicado histórico' => [['conciliacion' => 'duplicado_consolidado'], ['Curso Alfa 2024']],
            'con descargas' => [['descargas' => 'con'], ['Curso Beta 2025', 'Curso Alfa 2024']],
            'sin descargas' => [['descargas' => 'sin'], ['Curso Epsilon 2026', 'Curso Delta 2023', 'Curso Gamma', 'Curso Zeta 2024 y 2025']],
            'con código legado' => [['codigo' => 'con'], ['Curso Beta 2025', 'Curso Alfa 2024']],
            'sin código legado' => [['codigo' => 'sin'], ['Curso Epsilon 2026', 'Curso Delta 2023', 'Curso Gamma', 'Curso Zeta 2024 y 2025']],
            'combinados' => [['anio' => '2025', 'plantilla' => 'faltante', 'conciliacion' => 'revision_documento', 'descargas' => 'con'], ['Curso Beta 2025']],
            'sin resultados' => [['q' => 'no existe este evento'], []],
        ];
    }

    #[DataProvider('filtros')]
    public function test_los_filtros_se_aplican_en_el_servidor(array $query, array $esperado): void
    {
        $this->assertSame($esperado, $this->nombres($query));
    }

    public function test_el_texto_de_busqueda_no_interpreta_comodines_ni_inyecta_sql(): void
    {
        foreach (['%', '_', "'; DROP TABLE cf_eventos; --", '\\'] as $q) {
            $this->actingAs($this->admin())->get(route('credential-flow.historico.index', ['q' => $q]))->assertOk();
        }
        $this->assertSame([], $this->nombres(['q' => 'Curso%2026']), 'El % no es un comodín: se busca el texto literal «Curso 2026»');
        $this->assertSame(6, DB::table('cf_eventos')->count());
    }

    public function test_los_valores_de_filtro_invalidos_se_rechazan(): void
    {
        foreach ([['anio' => 'abc'], ['plantilla' => 'x'], ['conciliacion' => 'x'], ['descargas' => 'x'], ['orden' => 'x'], ['q' => str_repeat('a', 200)]] as $q) {
            $this->actingAs($this->admin())->get(route('credential-flow.historico.index', $q))->assertSessionHasErrors();
        }
    }

    public function test_el_orden_alternativo_por_nombre_y_por_cantidad_de_certificados(): void
    {
        $this->assertSame(['Curso Alfa 2024', 'Curso Beta 2025', 'Curso Delta 2023', 'Curso Epsilon 2026', 'Curso Gamma', 'Curso Zeta 2024 y 2025'], $this->nombres(['orden' => 'nombre']));
        $this->assertSame(['Curso Alfa 2024', 'Curso Beta 2025', 'Curso Gamma'], array_slice($this->nombres(['orden' => 'certificados']), 0, 3));
    }

    // ── Paginación ────────────────────────────────────────────────────────────

    public function test_el_listado_pagina_en_el_servidor_y_conserva_los_filtros(): void
    {
        $ahora = now()->toDateTimeString();
        $filas = [];
        for ($i = 1; $i <= 45; $i++) {
            $filas[] = ['nombre' => sprintf('Evento masivo %02d', $i), 'nombre_normalizado' => sprintf('evento masivo %02d', $i), 'anio' => 2020, 'estado' => 'cerrado', 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        DB::table('cf_eventos')->insert($filas);

        $p1 = $this->actingAs($this->admin())->get(route('credential-flow.historico.index', ['anio' => '2020']))->viewData('page')['props']['eventos'];
        $p3 = $this->actingAs($this->admin())->get(route('credential-flow.historico.index', ['anio' => '2020', 'page' => 3]))->viewData('page')['props']['eventos'];

        $this->assertSame([20, 3, 45], [count($p1['data']), $p1['last_page'], $p1['total']]);
        $this->assertSame(5, count($p3['data']));
        $this->assertStringContainsString('anio=2020', $p1['next_page_url'], 'Los filtros viajan en los enlaces de paginación');
        $this->assertSame('Evento masivo 01', $p1['data'][0]['nombre']);
    }

    public function test_el_numero_de_consultas_del_listado_no_crece_con_la_cantidad_de_eventos(): void
    {
        $contar = fn () => count($this->sentencias(fn () => $this->actingAs($this->admin())->get(route('credential-flow.historico.index'))->assertOk()));
        $antes = $contar();
        $ahora = now()->toDateTimeString();
        DB::table('cf_eventos')->insert(array_map(fn ($i) => ['nombre' => "Extra $i", 'nombre_normalizado' => "extra $i", 'anio' => 2019, 'estado' => 'cerrado', 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora], range(1, 40)));

        $this->assertSame($antes, $contar(), 'Sin N+1: mismas consultas con 20 eventos por página que con 6');
        $this->assertLessThanOrEqual(25, $antes);
    }

    // ── Detalle de evento ─────────────────────────────────────────────────────

    private function detalle(string $nombre, array $query = []): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', ['evento' => $this->idEvento($nombre)] + $query))->assertOk()
            ->assertInertia(fn (Assert $p) => $p->component('CredentialFlow/Historico/Evento'))->viewData('page')['props'];
    }

    public function test_el_detalle_del_evento_resume_y_advierte_en_lenguaje_humano(): void
    {
        $p = $this->detalle('Curso Beta 2025');
        $e = $p['evento'];

        $this->assertSame('Curso Beta 2025', $e['nombre']);
        $this->assertSame('2025', $e['anio_etiqueta']);
        $this->assertSame('2', $e['old_id']);
        $this->assertSame(6, $e['certificados']);
        $this->assertSame(1, $e['con_codigo']);
        $this->assertSame(1, $e['descargas']);
        $this->assertSame('faltante', $e['plantilla_detalle']['estado']);

        $avisos = collect($e['avisos'])->keyBy('tipo');
        $this->assertSame('No contamos todavía con una plantilla utilizable para generar los certificados de este evento.', $avisos['plantilla']['texto']);
        $this->assertSame('La imagen del evento no existe en el sistema histórico.', $avisos['plantilla']['tecnico']);
        $this->assertStringContainsString('3 certificados tienen un documento histórico que necesita revisión', $avisos['documentos']['texto']);
        $this->assertStringContainsString('2 certificados tienen diferencias', $avisos['conflictos']['texto']);
        $this->assertArrayNotHasKey('anio', $avisos->all(), 'Beta sí tiene año');
    }

    public function test_el_detalle_de_un_evento_sin_anio_lo_dice_y_no_lo_inventa(): void
    {
        $p = $this->detalle('Curso Zeta 2024 y 2025');

        $this->assertSame('Sin año identificado', $p['evento']['anio_etiqueta']);
        $anio = collect($p['evento']['avisos'])->firstWhere('tipo', 'anio');
        $this->assertSame('No pudimos determinar con certeza el año de este evento; no se asigna uno.', $anio['texto']);
        $this->assertSame('El nombre menciona más de un año.', $anio['tecnico']);
        $this->assertSame('No pudimos determinar', substr(collect($this->detalle('Curso Gamma')['evento']['avisos'])->firstWhere('tipo', 'anio')['texto'], 0, 21));
    }

    public function test_el_evento_con_candidata_muestra_la_evidencia_tecnica_sin_enlazarla(): void
    {
        $e = $this->detalle('Curso Gamma')['evento'];
        $plantilla = collect($e['avisos'])->firstWhere('tipo', 'plantilla');

        $this->assertSame('faltante', $e['plantilla_detalle']['estado']);
        $ev = $plantilla['evidencia'];
        $this->assertSame(substr(hash('sha256', $this->pngConSufijo('g')), 0, 12), $ev['sha']);
        $this->assertSame('image/png', $ev['mime']);
        $this->assertSame('2 × 2 px', $ev['dimensiones']);
        $this->assertSame('100 %', $ev['similitud']);
        $this->assertSame('Solo evidencia técnica: no se enlaza automáticamente.', $ev['nota']);
        $candidata = DB::table('cf_plantillas_legado')->where('estado', 'candidata_revision')->value('id');
        $this->assertSame(0, DB::table('cf_eventos')->where('plantilla_legado_id', $candidata)->count(), 'La candidata no está enlazada a ningún evento');
    }

    public function test_el_evento_con_extension_invalida_y_el_sin_imagen(): void
    {
        $delta = $this->detalle('Curso Delta 2023')['evento'];
        $this->assertSame('extension_invalida', $delta['plantilla_detalle']['estado']);
        $this->assertStringContainsString('extensión válida', collect($delta['avisos'])->firstWhere('tipo', 'plantilla')['tecnico']);
        $this->assertSame(0, $delta['certificados']);

        $epsilon = $this->detalle('Curso Epsilon 2026')['evento'];
        $this->assertNull($epsilon['plantilla_detalle']);
        $this->assertSame('El evento no tenía imagen de certificado.', collect($epsilon['avisos'])->firstWhere('tipo', 'plantilla')['tecnico']);
    }

    private function pngConSufijo(string $s): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);

        return ob_get_clean().$s;
    }

    public function test_los_certificados_del_evento_se_paginan_y_filtran_sin_exponer_documentos_ni_correos(): void
    {
        $evento = $this->idEvento('Curso Alfa 2024');
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', $evento))->assertOk();
        $c = $r->viewData('page')['props']['certificados'];

        $this->assertSame([6, 25], [$c['total'], $c['per_page']]);
        $fila = collect($c['data'])->firstWhere('nombre', self::NOMBRE);
        $this->assertSame('CC', $fila['tipo_documento']);
        $this->assertSame('****001', $fila['documento'], 'Documento enmascarado (7 dígitos: se ven 3)');
        $this->assertSame('1 correo válido', $fila['correo']);
        $this->assertSame('100', $fila['codigo_legado']);
        $this->assertSame(2, $fila['descargas']);
        $this->assertSame('Sin novedad', $fila['conciliacion']['etiqueta']);
        $this->assertSame('Vigente', $fila['estado']);

        $json = json_encode($c);
        foreach ([self::DOCUMENTO, '1000001', self::CORREO, 'example.test', '2000001'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $json, "La tabla no debe llevar «{$secreto}»");
        }

        $this->assertSame(2, $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', [$evento, 'conciliacion' => 'pendiente_conciliacion']))->viewData('page')['props']['certificados']['total']);
        $this->assertSame(2, $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', [$evento, 'q' => 'gina']))->viewData('page')['props']['certificados']['total'], 'GINA SIETE: las dos filas del duplicado');
        $this->assertSame(2, $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', [$evento, 'q' => '101']))->viewData('page')['props']['certificados']['total'], 'Código 101: las dos filas del duplicado');
    }

    public function test_los_certificados_de_un_evento_grande_se_paginan_de_25_en_25(): void
    {
        $evento = $this->idEvento('Curso Alfa 2024');
        $ahora = now()->toDateTimeString();
        $filas = [];
        for ($i = 1; $i <= 70; $i++) {
            $filas[] = ['evento_id' => $evento, 'documento_clave' => (string) (5000 + $i), 'documento' => (string) (5000 + $i), 'nombre_completo' => sprintf('MASIVO %03d', $i), 'snapshot_legado' => '{}', 'created_at' => $ahora, 'updated_at' => $ahora];
        }
        DB::table('cf_certificados_legado')->insert($filas);

        $sql = $this->sentencias(function () use ($evento, &$p) {
            $p = $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', [$evento, 'page' => 2]))->viewData('page')['props']['certificados'];
        });

        $this->assertSame([76, 25, 4], [$p['total'], count($p['data']), $p['last_page']]);
        $this->assertLessThanOrEqual(25, count($sql), 'Sin N+1 en la página de certificados');
    }
}
