<?php

namespace Tests\Feature\CredentialFlow\Historico;

use Illuminate\Support\Facades\DB;

/** Detalle de certificado histórico, duplicados, conflictivos, revisión de documento, plantilla pendiente, correos, descargas, búsqueda y plantillas. */
class HistoricoCertificadosTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->migrarSintetico();
    }

    /** @return array<string,mixed> */
    private function cert(int $old): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.certificados.show', $this->idCert($old)))->assertOk()
            ->assertInertia(fn ($p) => $p->component('CredentialFlow/Historico/Certificado'))->viewData('page')['props']['certificado'];
    }

    private function aviso(array $c, string $tipo): ?array
    {
        return collect($c['avisos'])->firstWhere('tipo', $tipo);
    }

    // ── Detalle ───────────────────────────────────────────────────────────────

    public function test_el_detalle_muestra_toda_la_informacion_al_administrador(): void
    {
        $c = $this->cert(1);

        $this->assertSame(self::NOMBRE, $c['nombre']);
        $this->assertSame('CC', $c['tipo_documento']);
        $this->assertSame(self::DOCUMENTO, $c['documento'], 'El detalle sí muestra el documento completo');
        $this->assertSame(self::CORREO, $c['correo_original']);
        $this->assertSame('Válido', $c['correo_estado']);
        $this->assertSame('100', $c['codigo_legado']);
        $this->assertSame('1', $c['old_id'], 'old_id según el mapa de migración');
        $this->assertSame('Vigente', $c['estado']);
        $this->assertSame('Sin novedad', $c['conciliacion']['etiqueta']);
        $this->assertFalse($c['visible_portal']);
        $this->assertSame('Curso Alfa 2024', $c['evento']['nombre']);
        $this->assertTrue($c['plantilla_usable']);
        $this->assertSame('Disponible', $c['plantilla']['estado_info']['etiqueta']);
        $this->assertSame('image/png', $c['plantilla']['mime']);
        $this->assertSame('Aún no generado', $c['pdf']['estado']);
        $this->assertFalse($c['pdf']['congelado']);
        $this->assertNull($c['reemplazado_por']);
        $this->assertSame('completada', $c['corrida']['estado']);
        $this->assertSame([], $c['avisos']);
        $this->assertNull($c['duplicado']);
    }

    public function test_las_descargas_historicas_se_listan_de_la_mas_reciente_y_sin_ip(): void
    {
        $d = $this->cert(1)['descargas'];

        $this->assertSame(2, $d['total']);
        $this->assertSame(['2026-01-05 10:00:00', '2026-01-01 10:00:00'], array_column($d['items'], 'fecha'));
        $this->assertSame(['Portal', 'Portal'], array_column($d['items'], 'via'));
        $this->assertSame(['Histórico (sistema anterior)', 'Histórico (sistema anterior)'], array_column($d['items'], 'origen'));
        $this->assertStringNotContainsString('ip', strtolower(implode(',', array_keys($d['items'][0]))), 'No se muestra IP: el legado no la tiene de forma fiable');
        $this->assertSame(0, $this->cert(2)['descargas']['total']);
    }

    public function test_las_descargas_se_limitan_y_se_dice_cuantas_hay(): void
    {
        $id = $this->idCert(1);
        $ahora = now()->toDateTimeString();
        DB::table('cf_descargas')->insert(array_map(fn ($i) => ['certificado_legado_id' => $id, 'via' => 'portal', 'descargado_at' => '2025-01-01 00:00:00', 'created_at' => $ahora, 'updated_at' => $ahora], range(1, 60)));

        $d = $this->cert(1)['descargas'];

        $this->assertSame([62, 50, 50], [$d['total'], $d['mostradas'], count($d['items'])]);
    }

    public function test_un_certificado_inexistente_o_un_evento_no_historico_dan_404(): void
    {
        $this->actingAs($this->admin())->get(route('credential-flow.historico.certificados.show', 999999))->assertNotFound();
        $this->actingAs($this->admin())->get('/admin/credential-flow/historico/certificados/abc')->assertNotFound();
        $moderno = DB::table('cf_eventos')->insertGetId(['nombre' => 'Moderno', 'nombre_normalizado' => 'moderno', 'estado' => 'activo', 'origen' => 'credential_flow', 'created_at' => now(), 'updated_at' => now()]);
        $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', $moderno))->assertNotFound();
        $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', 999999))->assertNotFound();
    }

    // ── Duplicados y conflictivos ─────────────────────────────────────────────

    public function test_duplicado_identico_indica_canonico_y_permite_navegar_entre_variantes(): void
    {
        $canonico = $this->cert(7);
        $copia = $this->cert(8);

        $this->assertSame('identico', $canonico['duplicado']['clasificacion']);
        $this->assertTrue($canonico['duplicado']['canonico']);
        $this->assertFalse($copia['duplicado']['canonico']);
        $this->assertSame(2, $copia['duplicado']['total']);
        $this->assertSame('Duplicado histórico', $copia['conciliacion']['etiqueta']);

        $ids = array_column($copia['duplicado']['variantes'], 'id');
        $this->assertSame([$this->idCert(7), $this->idCert(8)], $ids, 'Las dos variantes, para navegar entre ellas');
        $this->assertSame([false, true], array_column($copia['duplicado']['variantes'], 'actual'));
        $this->assertSame([true, false], array_column($copia['duplicado']['variantes'], 'canonico'));
        $this->assertSame(['7', '8'], array_column($copia['duplicado']['variantes'], 'old_id'));
        $this->assertSame('Registro duplicado', $this->aviso($copia, 'duplicado')['titulo']);
        $this->assertSame('Este registro pertenece a un grupo duplicado histórico con datos idénticos.', $this->aviso($copia, 'duplicado')['texto']);
        $this->assertSame('101', $copia['codigo_legado']);
    }

    public function test_conflictivo_muestra_el_mensaje_humano_y_en_que_difiere_solo_para_el_administrador(): void
    {
        $c = $this->cert(9);

        $this->assertSame('conflictivo', $c['duplicado']['clasificacion']);
        $this->assertNull($c['duplicado']['canonico']);
        $this->assertSame(['el nombre'], $c['duplicado']['diferencias']);
        $a = $this->aviso($c, 'conflicto');
        $this->assertSame('Encontramos diferencias entre registros históricos de esta persona. Este certificado necesita revisión antes de habilitarse.', $a['texto']);
        $this->assertSame('Difiere: el nombre.', $a['tecnico']);
        $this->assertSame('Necesita conciliación', $c['conciliacion']['etiqueta']);
        // Ninguna variante se muestra como «elegida»: se conservan todas.
        $this->assertSame([false, false], array_column($c['duplicado']['variantes'], 'canonico'));
        $this->assertSame(2, $c['duplicado']['total']);
    }

    // ── Revisión de documento ─────────────────────────────────────────────────

    public function test_documento_en_revision_muestra_el_mensaje_y_el_motivo_tecnico_sin_corregirlo(): void
    {
        $c = $this->cert(4);

        $this->assertSame('ABC123', $c['documento'], 'No se corrige');
        $a = $this->aviso($c, 'documento');
        $this->assertSame('El documento histórico necesita revisión antes de habilitar este certificado.', $a['texto']);
        $this->assertSame('Motivo: contiene letras.', $a['tecnico']);
        $this->assertSame('Documento en revisión', $c['conciliacion']['etiqueta']);
        $this->assertSame('El registro histórico no tenía tipo de documento: el certificado original se imprimía sin él.', $this->aviso($c, 'advertencia')['texto']);

        $this->assertSame('Motivo: el documento está vacío.', $this->aviso($this->cert(5), 'documento')['tecnico']);
        $this->assertSame('Motivo: tiene puntos o comas como separadores.', $this->aviso($this->cert(6), 'documento')['tecnico']);
    }

    // ── Plantilla pendiente ───────────────────────────────────────────────────

    public function test_plantilla_pendiente_se_explica_con_mensaje_humano_y_motivo_interno(): void
    {
        $c = $this->cert(3);

        $this->assertFalse($c['plantilla_usable']);
        $a = $this->aviso($c, 'plantilla');
        $this->assertSame('No contamos todavía con una plantilla utilizable para generar este certificado.', $a['texto']);
        $this->assertSame('La imagen del evento no existe en el sistema histórico.', $a['tecnico']);
        $this->assertSame('Plantilla pendiente', $c['conciliacion']['etiqueta']);
        $this->assertSame('faltante', $c['plantilla']['estado']);
    }

    public function test_un_certificado_de_un_evento_con_candidata_ve_la_evidencia_pero_la_candidata_no_se_enlaza(): void
    {
        $c = $this->cert(13);   // evento Gamma: imagen faltante con una candidata por nombre
        $ev = $this->aviso($c, 'plantilla')['evidencia'];

        $this->assertNotNull($ev);
        $this->assertSame(12, strlen($ev['sha']));
        $this->assertSame('image/png', $ev['mime']);
        $this->assertSame('100 %', $ev['similitud']);
        $this->assertFalse($c['plantilla_usable']);
        $this->assertNull(DB::table('cf_certificados_legado')->where('id', $this->idCert(13))->value('plantilla_legado_id'));
    }

    // ── Búsqueda ──────────────────────────────────────────────────────────────

    /** @return array<string,mixed>|null */
    private function buscar(string $campo, string $q): ?array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.buscar', ['campo' => $campo, 'q' => $q]))->assertOk()
            ->assertInertia(fn ($p) => $p->component('CredentialFlow/Historico/Buscar'))->viewData('page')['props']['resultados'];
    }

    public function test_busqueda_por_documento_exacto_normalizado_en_el_servidor(): void
    {
        foreach ([self::DOCUMENTO, '1.000.001', ' 1000001 '] as $q) {
            $r = $this->buscar('documento', $q);
            $this->assertSame(1, $r['total'], "Documento «{$q}»");
            $this->assertSame(self::NOMBRE, $r['data'][0]['nombre']);
            $this->assertSame('****001', $r['data'][0]['documento'], 'El resultado también va enmascarado');
        }
        $this->assertSame(0, $this->buscar('documento', '100000')['total'], 'Exacto: un prefijo no coincide');
        $this->assertSame(0, $this->buscar('documento', '!!!')['total']);
        $this->assertSame('ABC123', DB::table('cf_certificados_legado')->where('id', $this->buscar('documento', 'abc 123')['data'][0]['id'])->value('documento'));
    }

    public function test_busqueda_por_codigo_devuelve_todas_las_variantes_porque_no_es_unico(): void
    {
        $r = $this->buscar('codigo', '101');

        $this->assertSame(2, $r['total'], 'Las dos filas del duplicado con el código 101');
        $this->assertSame(['Duplicado histórico', 'Sin novedad'], collect($r['data'])->pluck('conciliacion.etiqueta')->sort()->values()->all());
        $this->assertSame(1, $this->buscar('codigo', '100')['total']);
        $this->assertSame(1, $this->buscar('codigo', ' 205 ')['total']);
        $this->assertSame(0, $this->buscar('codigo', '999')['total']);
        $this->assertSame(0, $this->buscar('codigo', 'abc')['total']);
    }

    public function test_busqueda_por_nombre_parcial_con_minimo_de_letras_y_sin_resultados(): void
    {
        $this->assertSame(2, $this->buscar('nombre', 'gina')['total']);
        $this->assertSame(2, $this->buscar('nombre', 'HUGO')['total']);
        $this->assertSame(0, $this->buscar('nombre', 'gi')['total'], 'Menos de 3 letras no busca');
        $this->assertSame(0, $this->buscar('nombre', 'persona inexistente')['total']);
        $this->assertSame(0, $this->buscar('nombre', '%%%')['total']);
        $this->assertNull($this->actingAs($this->admin())->get(route('credential-flow.historico.buscar'))->viewData('page')['props']['resultados']);
    }

    public function test_los_resultados_de_la_busqueda_no_incluyen_correos_ni_documentos_completos(): void
    {
        $json = json_encode($this->buscar('nombre', 'ana uno'));

        foreach ([self::CORREO, 'example.test', '1000001'] as $secreto) {
            $this->assertStringNotContainsString($secreto, $json);
        }
        $r = $this->buscar('nombre', 'ana uno');
        $this->assertSame('Curso Alfa 2024', $r['data'][0]['evento']['nombre']);
    }

    public function test_la_busqueda_pagina_en_el_servidor(): void
    {
        $evento = $this->idEvento('Curso Alfa 2024');
        $ahora = now()->toDateTimeString();
        DB::table('cf_certificados_legado')->insert(array_map(fn ($i) => ['evento_id' => $evento, 'documento_clave' => (string) (7000 + $i), 'documento' => (string) (7000 + $i), 'nombre_completo' => 'PRUEBA MASIVA '.$i, 'snapshot_legado' => '{}', 'created_at' => $ahora, 'updated_at' => $ahora], range(1, 60)));

        $p = $this->actingAs($this->admin())->get(route('credential-flow.historico.buscar', ['campo' => 'nombre', 'q' => 'prueba masiva']))->viewData('page')['props']['resultados'];

        $this->assertSame([60, 25, 3], [$p['total'], count($p['data']), $p['last_page']]);
        $this->assertStringContainsString('q=prueba%20masiva', $p['next_page_url'], 'La búsqueda viaja en los enlaces de paginación');
    }

    public function test_valores_invalidos_en_la_busqueda_se_rechazan(): void
    {
        $this->actingAs($this->admin())->get(route('credential-flow.historico.buscar', ['campo' => 'otro', 'q' => 'x']))->assertSessionHasErrors('campo');
        $this->actingAs($this->admin())->get(route('credential-flow.historico.buscar', ['q' => str_repeat('a', 100)]))->assertSessionHasErrors('q');
    }

    // ── Plantillas históricas ─────────────────────────────────────────────────

    /** @return array<string,mixed> */
    private function plantillas(array $q = []): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.plantillas.index', $q))->assertOk()
            ->assertInertia(fn ($p) => $p->component('CredentialFlow/Historico/Plantillas'))->viewData('page')['props'];
    }

    public function test_las_plantillas_historicas_se_listan_con_sus_conteos_y_filtros(): void
    {
        $p = $this->plantillas();

        $this->assertSame(['total' => 7, 'ok' => 1, 'faltante' => 3, 'extension_invalida' => 1, 'huerfana' => 1, 'candidata_revision' => 1, 'sin_evento' => 2], $p['conteos']);
        $this->assertSame(7, $p['plantillas']['total']);
        $filas = collect($p['plantillas']['data']);

        $ok = $filas->firstWhere('estado', 'ok');
        $this->assertSame('image/png', $ok['mime']);
        $this->assertSame('2 × 2 px', $ok['dimensiones']);
        $this->assertTrue($ok['renderizable']);
        $this->assertSame(12, strlen($ok['sha']));
        $this->assertSame('Curso Alfa 2024', $ok['eventos'][0]['nombre']);

        $faltante = $filas->firstWhere('estado', 'faltante');
        $this->assertFalse($faltante['contenido']);
        $this->assertNull($faltante['sha']);
        $this->assertSame('Imagen faltante', $faltante['estado_info']['etiqueta']);

        $this->assertSame(['Curso Delta 2023'], collect($filas->firstWhere('estado', 'extension_invalida')['eventos'])->pluck('nombre')->all());
        $this->assertSame(3, $this->plantillas(['estado' => 'faltante'])['plantillas']['total']);
        $this->assertSame(1, $this->plantillas(['estado' => 'ok'])['plantillas']['total']);
        $this->assertSame(4, $this->plantillas(['renderizable' => 'no'])['plantillas']['total'], '3 faltantes + 1 extensión inválida');
        $this->assertSame(3, $this->plantillas(['renderizable' => 'si'])['plantillas']['total'], 'ok + huérfana + candidata (PNG válidos)');
    }

    public function test_las_imagenes_sin_evento_son_las_huerfanas_y_las_candidatas_y_la_candidata_no_se_enlaza(): void
    {
        $p = $this->plantillas(['estado' => 'sin_evento']);
        $filas = collect($p['plantillas']['data']);

        $this->assertSame(2, $p['plantillas']['total'], 'En los datos reales: 10 huérfanas + 5 candidatas');
        $this->assertSame(['candidata_revision', 'huerfana'], $filas->pluck('estado')->sort()->values()->all());
        $this->assertSame([true, true], $filas->pluck('sin_evento')->all());
        $this->assertSame(0, $filas->sum(fn ($f) => count($f['eventos'])), 'Ninguna tiene evento');

        $candidata = $filas->firstWhere('estado', 'candidata_revision');
        $this->assertSame('Curso Gamma', $candidata['candidata_para']['evento']['nombre']);
        $this->assertSame('100 %', $candidata['candidata_para']['similitud']);
        $this->assertSame('Solo evidencia técnica: no se enlaza automáticamente.', $candidata['candidata_para']['nota']);
        $this->assertNull($filas->firstWhere('estado', 'huerfana')['candidata_para']);
        $this->assertSame(0, DB::table('cf_eventos')->where('plantilla_legado_id', $candidata['id'])->count());
    }

    public function test_los_nombres_de_archivo_problematicos_no_rompen_la_pantalla(): void
    {
        $contenido = DB::table('cf_plantillas_legado_contenidos')->value('id');
        DB::table('cf_plantillas_legado')->insert(['contenido_id' => $contenido, 'ruta_original' => 'document/certImages/'.str_repeat('X', 200), 'nombre_original' => "  AGO\n\t".str_repeat('largo ', 40)."\x00fin  ", 'nombre_normalizado' => 'x', 'estado' => 'ok', 'renderizable' => true, 'created_at' => now(), 'updated_at' => now()]);

        $nombre = collect($this->plantillas(['q' => 'ago'])['plantillas']['data'])->firstWhere('estado', 'ok')['nombre'];

        $this->assertLessThanOrEqual(60, mb_strlen($nombre));
        $this->assertDoesNotMatchRegularExpression('/[\x00-\x1F]/', $nombre);
        $this->assertStringEndsWith('…', $nombre);
    }

    public function test_buscar_plantillas_por_huella_y_por_nombre(): void
    {
        $sha = DB::table('cf_plantillas_legado_contenidos')->value('sha256');

        $this->assertGreaterThanOrEqual(1, $this->plantillas(['q' => substr($sha, 0, 8)])['plantillas']['total']);
        $this->assertSame(1, $this->plantillas(['q' => 'orfana'])['plantillas']['total']);
        $this->assertSame(0, $this->plantillas(['q' => 'zzzz-no-existe'])['plantillas']['total']);
    }
}
