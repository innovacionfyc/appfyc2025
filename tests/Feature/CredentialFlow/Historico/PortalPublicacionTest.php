<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 4: el portal muestra primero lo PUBLICADO más recientemente (fecha real de la emisión), no lo del evento más reciente. La fecha de realización y la de
 * publicación se ven por separado; los históricos (sin fecha de publicación registrada: su `created_at` es la fecha de la migración) van aparte, en un orden
 * estable, y nunca reciben una fecha inventada. Filtros por año/mes y días de publicación, nombre y orden. Datos 100 % sintéticos.
 */
class PortalPublicacionTest extends PortalTestCase
{
    /** Emite a Ana (la persona de prueba con históricos) en una base nueva y fija la fecha de publicación de esa emisión. Devuelve el id de la emisión. */
    private function publicar(string $evento, string $fechaEvento, string $publicadoAt, string $documento = '1.000.001', string $nombre = self::NOMBRE, string $correo = self::CORREO): int
    {
        $lote = app(ImportadorParticipantes::class)->importar(
            UploadedFile::fake()->createWithContent('base.csv', "nombre_completo,documento,correo\n{$nombre},{$documento},{$correo}\n"),
            $this->plantillaLista(), 'Base '.$evento, null, ['evento' => $evento, 'fecha' => $fechaEvento, 'intensidad_horaria' => '4 horas']
        );
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();
        $id = (int) DB::table('cf_emisiones')->where('participante_id', $p->id)->value('id');
        DB::table('cf_emisiones')->where('id', $id)->update(['emitido_at' => $publicadoAt]);

        return $id;
    }

    private function clave(): string
    {
        return AccesoPortal::claveDocumento(self::DOCUMENTO);
    }

    /** @return list<string> eventos de las tarjetas, en el orden en que salen */
    private function eventos(array $listado): array
    {
        return array_column($listado['items'], 'evento');
    }

    private function panel(array $query = []): string
    {
        $this->entrar()->assertRedirect(route('portal.panel'));

        return $this->verPanel($query);
    }

    /** El panel con la sesión ya abierta (sin volver a pedir un código: el portal limita los intentos). */
    private function verPanel(array $query = []): string
    {
        return $this->get(route('portal.panel', $query))->assertOk()->getContent();
    }

    // ── Orden por fecha real de publicación ──────────────────────────────────

    public function test_un_certificado_publicado_hoy_de_un_evento_antiguo_aparece_antes_que_uno_publicado_hace_meses(): void
    {
        $this->publicar('Seminario reciente 2026', '1 de junio de 2026', '2026-06-02 10:00:00');
        $this->publicar('Curso de 2019 publicado hoy', '15 de marzo de 2019', now()->format('Y-m-d H:i:s'));

        $l = app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null);
        $this->assertSame(['Curso de 2019 publicado hoy', 'Seminario reciente 2026'], array_slice($this->eventos($l), 0, 2), 'manda la publicación, no el año del evento');
        $this->assertSame([2019, 2026], [$l['items'][0]['anio'], $l['items'][1]['anio']]);

        // En el panel se ven las dos fechas por separado.
        $html = $this->panel();
        $this->assertStringContainsString('Publicado el '.now()->day.' de ', $html);
        $this->assertStringContainsString('Fecha del evento: 15 de marzo de 2019', $html);
        $this->assertStringContainsString('Publicado el 2 de junio de 2026', $html);
        $this->assertLessThan(strpos($html, 'Seminario reciente 2026'), strpos($html, 'Curso de 2019 publicado hoy'));
    }

    public function test_varios_publicados_el_mismo_dia_salen_por_hora_y_en_un_orden_estable(): void
    {
        $a = $this->publicar('Taller mañana', '10 de octubre de 2026', '2026-10-10 08:00:00');
        $b = $this->publicar('Taller tarde', '10 de octubre de 2026', '2026-10-10 16:30:00');
        $c = $this->publicar('Taller tarde gemelo', '10 de octubre de 2026', '2026-10-10 16:30:00');   // misma hora exacta

        $l = app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null);
        $this->assertSame(['Taller tarde gemelo', 'Taller tarde', 'Taller mañana'], array_slice($this->eventos($l), 0, 3), 'a igual hora, el más nuevo (id mayor) primero');
        $this->assertSame([$c, $b, $a], array_slice(array_column($l['items'], 'id'), 0, 3));
        $this->assertSame($this->eventos($l), $this->eventos(app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null)), 'mismo orden en cada consulta');
    }

    public function test_los_historicos_sin_fecha_van_aparte_sin_fecha_inventada_ni_la_de_la_migracion(): void
    {
        // Aunque la migración hubiera dejado un created_at «de mañana», un histórico no pasa por delante de lo publicado.
        DB::table('cf_certificados_legado')->where('documento_clave', $this->clave())->update(['created_at' => now()->addDay(), 'updated_at' => now()->addDay()]);
        $this->publicar('Diplomado publicado', '5 de mayo de 2026', '2026-05-06 09:00:00');

        $l = app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null);
        $this->assertSame('Diplomado publicado', $l['items'][0]['evento']);
        $historicos = array_slice($l['items'], 1);
        $this->assertNotEmpty($historicos);
        foreach ($historicos as $t) {
            $this->assertNull($t['publicado_at'], 'un histórico nunca recibe una fecha de publicación');
        }
        // Orden estable de los históricos: año del evento (más reciente primero), nombre e id.
        $orden = array_map(fn ($t) => [$t['anio'] === null ? 1 : 0, -($t['anio'] ?? 0), $t['evento'], $t['id']], $historicos);
        $esperado = $orden;
        sort($esperado);
        $this->assertSame($esperado, $orden);

        $html = $this->panel();
        $this->assertStringContainsString('data-separador="historicos"', $html);
        $this->assertStringContainsString('Certificados históricos · sin fecha de publicación registrada', $html);
        $this->assertSame(1, substr_count($html, 'data-fecha-publicacion'), 'solo el moderno muestra fecha de publicación');
        $this->assertSame(count($historicos), substr_count($html, 'data-sin-fecha'));
    }

    // ── Filtros, búsqueda, orden y paginación ────────────────────────────────

    /** 14 publicaciones de Ana repartidas en tres meses (más sus históricos): suficientes para que el panel tenga controles. */
    private function sembrarPublicaciones(): void
    {
        foreach (range(1, 6) as $i) {
            $this->publicar(sprintf('Curso de contratación %02d', $i), '1 de agosto de 2026', sprintf('2026-08-%02d 09:00:00', $i));
        }
        foreach (range(1, 5) as $i) {
            $this->publicar(sprintf('Seminario tributario %02d', $i), '3 de septiembre de 2026', sprintf('2026-09-%02d 11:00:00', 10 + $i));
        }
        foreach (range(1, 3) as $i) {
            $this->publicar(sprintf('Jornada contable %02d', $i), '2 de octubre de 2026', sprintf('2026-10-%02d 15:00:00', 20 + $i));
        }
    }

    public function test_filtros_de_publicacion_busqueda_y_anio_del_evento_combinados(): void
    {
        $this->sembrarPublicaciones();
        $acceso = app(AccesoPortal::class);
        $todo = $acceso->tarjetasPaginadas($this->clave(), null);
        $this->assertTrue($todo['con_controles']);
        $this->assertSame(['2026' => ['2026-10', '2026-09', '2026-08']], $todo['periodos'], 'solo se ofrecen los periodos que existen');
        $this->assertTrue($todo['hay_sin_fecha']);

        $sept = $acceso->tarjetasPaginadas($this->clave(), null, ['publicado' => '2026-09']);
        $this->assertSame(5, $sept['total']);
        $this->assertGreaterThan(0, $sept['sin_fecha_ocultos'], 'los históricos no aparecen en un filtro de fechas, y se informa');
        $this->assertSame('2026-09', $sept['publicado']);

        $combinado = $acceso->tarjetasPaginadas($this->clave(), null, ['publicado' => '2026', 'q' => 'TRIBUTARIO 0', 'desde' => '2026-09-12', 'hasta' => '2026-09-14']);
        $this->assertSame(['Seminario tributario 04', 'Seminario tributario 03', 'Seminario tributario 02'], $this->eventos($combinado));

        $unDia = $acceso->tarjetasPaginadas($this->clave(), null, ['desde' => '2026-08-03', 'hasta' => '2026-08-03']);
        $this->assertSame(['Curso de contratación 03'], $this->eventos($unDia));
        $rango = $acceso->tarjetasPaginadas($this->clave(), null, ['desde' => '2026-08-01', 'hasta' => '2026-08-03']);
        $this->assertSame(['Curso de contratación 03', 'Curso de contratación 02', 'Curso de contratación 01'], $this->eventos($rango));

        // El año del EVENTO se conserva como filtro aparte.
        $evento2026 = $acceso->tarjetasPaginadas($this->clave(), null, ['anio' => '2026']);
        foreach ($evento2026['items'] as $t) {
            $this->assertSame(2026, $t['anio']);
        }

        // Un orden desconocido tampoco se ignora en silencio: aviso claro y listado vacío.
        $raro = $acceso->tarjetasPaginadas($this->clave(), null, ['orden' => 'raro']);
        $this->assertSame([0, 'El orden indicado no es válido.'], [$raro['total'], $raro['filtro_invalido']]);
    }

    public function test_un_filtro_invalido_nunca_amplia_el_resultado_y_lo_explica(): void
    {
        $this->sembrarPublicaciones();
        $acceso = app(AccesoPortal::class);

        // Formato inválido: listado vacío y un aviso claro (nunca «se ignora y se muestra todo»).
        foreach ([
            ['publicado' => '2026-13'], ['publicado' => 'x'], ['publicado' => '2026-1'], ['publicado' => '26'], ['anio' => 'abc'], ['anio' => '2026; DROP'],
            ['desde' => '2026-02-31'], ['hasta' => '<script>'], ['desde' => '2026/08/01'], ['desde' => '2026-08-05', 'hasta' => '2026-08-01'],
            ['publicado' => '2026-08', 'desde' => 'mañana'],
        ] as $invalido) {
            $r = $acceso->tarjetasPaginadas($this->clave(), null, $invalido);
            $this->assertSame(0, $r['total'], json_encode($invalido));
            $this->assertSame([], $r['items']);
            $this->assertNotNull($r['filtro_invalido'], json_encode($invalido));
        }

        // Valor válido sin certificados: vacío, sin aviso de error.
        foreach ([['publicado' => '2019-01'], ['publicado' => '2019'], ['anio' => '1999'], ['desde' => '2030-01-01']] as $sinDatos) {
            $r = $acceso->tarjetasPaginadas($this->clave(), null, $sinDatos);
            $this->assertSame([0, null], [$r['total'], $r['filtro_invalido']], json_encode($sinDatos));
        }

        // En el panel el aviso se ve y no aparece ninguna tarjeta.
        $html = $this->panel(['publicado' => '2026-13']);
        $this->assertStringContainsString('data-filtro-invalido', $html);
        $this->assertStringContainsString('El año o mes de publicación no es válido.', $html);
        $this->assertSame(0, substr_count($html, 'data-certificado='));
    }

    public function test_parametros_malformados_o_de_tipo_inesperado_nunca_se_ignoran_ni_amplian(): void
    {
        $this->sembrarPublicaciones();
        $this->publicar('Curso solo de Beto', '1 de enero de 2025', '2025-01-15 09:00:00', '9.300.001', 'BETO PRUEBA SINTETICO', 'beto@example.test');
        $acceso = app(AccesoPortal::class);
        $todo = $acceso->tarjetasPaginadas($this->clave(), null)['total'];

        // Directo: cualquier tipo que no sea texto o entero, en cualquier parámetro, es un filtro inválido (no un filtro ausente).
        foreach (['q', 'anio', 'orden', 'publicado', 'desde', 'hasta', 'page'] as $k) {
            foreach ([['2026'], ['a' => 'b'], [], true, 1.5, new \stdClass] as $raro) {
                $r = $acceso->tarjetasPaginadas($this->clave(), null, [$k => $raro]);
                $this->assertSame([0, []], [$r['total'], $r['items']], $k.' '.get_debug_type($raro));
                $this->assertSame('Los filtros indicados no son válidos.', $r['filtro_invalido'], $k);
            }
        }
        // Página mal formada: aviso. Página válida fuera de rango: la última (compatibilidad con el paginador).
        foreach (['abc', '-4', '0', '1.5', '2e3', ' 1 x'] as $p) {
            $this->assertSame([0, 'La página indicada no es válida.'], array_values(array_intersect_key($acceso->tarjetasPaginadas($this->clave(), null, ['page' => $p]), ['total' => 0, 'filtro_invalido' => 0])), $p);
        }
        $ultima = $acceso->tarjetasPaginadas($this->clave(), null, ['page' => '999']);
        $this->assertSame([$ultima['paginas'], null], [$ultima['pagina'], $ultima['filtro_invalido']]);
        $this->assertNotEmpty($ultima['items']);
        // Enteros (uso interno) siguen valiendo.
        $this->assertSame(null, $acceso->tarjetasPaginadas($this->clave(), null, ['anio' => 2026, 'page' => 1])['filtro_invalido']);

        // Por la URL real, con la sesión OTP de Ana: arreglos y valores raros en cada parámetro -> aviso, ninguna tarjeta y nada de Beto.
        $this->entrar()->assertRedirect(route('portal.panel'));
        foreach ([
            'anio[]=2026', 'anio[]=2025&anio[]=2026', 'q[]=beto', 'q[a]=x', 'orden[]=antiguos', 'orden=zzz', 'publicado[]=2025', 'publicado[2025]=01',
            'desde[]=2025-01-01', 'hasta[]=2030-01-01', 'desde[]=2025-01-01&hasta[]=2025-12-31', 'page[]=2', 'page=abc', 'page=-4', 'page=0',
            'q=beto&anio[]=2025', 'publicado=2025&orden[]=nombre',
        ] as $qs) {
            $html = $this->get(route('portal.panel').'?'.$qs)->assertOk()->getContent();
            $this->assertSame(0, substr_count($html, 'data-certificado='), $qs);
            $this->assertStringContainsString('data-filtro-invalido', $html, $qs);
            preg_match_all('/<h2>(.*?)<\/h2>/s', $html, $titulos);
            $this->assertNotContains('Curso solo de Beto', array_map(fn ($t) => html_entity_decode(trim($t)), $titulos[1]), $qs);
        }

        // Los filtros válidos y la paginación siguen igual.
        $this->assertSame(min(20, $todo), substr_count($this->verPanel(), 'data-certificado='));
        $this->assertSame(5, substr_count($this->verPanel(['publicado' => '2026-09']), 'data-certificado='));
        $this->assertSame(count($acceso->tarjetasPaginadas($this->clave(), null, ['page' => '2'])['items']), substr_count($this->verPanel(['page' => 2]), 'data-certificado='));
        $this->assertStringNotContainsString('data-filtro-invalido', $this->verPanel(['q' => str_repeat('a', 500), 'orden' => 'nombre', 'anio' => '1999']));
    }

    public function test_con_un_solo_certificado_la_vista_es_simple_y_con_dos_ya_hay_buscador(): void
    {
        $this->publicar('Curso único de Beto', '1 de enero de 2025', '2025-01-15 09:00:00', '9.300.001', 'BETO PRUEBA SINTETICO', 'beto@example.test');
        $this->entrar('9.300.001', 'beto@example.test')->assertRedirect(route('portal.panel'));
        $uno = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertStringNotContainsString('data-controles', $uno, 'con un solo certificado no hay controles');
        $this->assertSame(1, substr_count($uno, 'data-certificado='));
        // Sin controles, un parámetro escrito a mano tampoco se ignora en silencio: aviso claro y ninguna tarjeta.
        $invalido = $this->get(route('portal.panel', ['publicado' => '2026-13']))->assertOk()->getContent();
        $this->assertStringContainsString('data-filtro-invalido', $invalido);
        $this->assertSame(0, substr_count($invalido, 'data-certificado='));
        $this->assertStringNotContainsString('data-controles', $invalido);

        $this->publicar('Segundo curso de Beto', '2 de febrero de 2025', '2025-02-20 09:00:00', '9.300.001', 'BETO PRUEBA SINTETICO', 'beto@example.test');
        $dos = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertStringContainsString('data-controles', $dos, 'con dos ya puede buscar y filtrar');
        $this->assertStringNotContainsString('data-paginador', $dos);
        $this->assertSame(['2025' => ['2025-02', '2025-01']], app(AccesoPortal::class)->tarjetasPaginadas(AccesoPortal::claveDocumento('9300001'), null)['periodos']);
    }

    public function test_orden_mas_antiguos_y_por_nombre(): void
    {
        $this->sembrarPublicaciones();
        $acceso = app(AccesoPortal::class);
        $recientes = $acceso->tarjetasPaginadas($this->clave(), null, ['page' => 1]);
        $this->assertSame('Jornada contable 03', $recientes['items'][0]['evento']);

        // Más antiguos: lo publicado, del más antiguo al más reciente.
        $antiguos = $acceso->tarjetasPaginadas($this->clave(), null, ['orden' => 'antiguos', 'q' => 'curso de contratacion']);
        $this->assertSame(array_map(fn ($i) => sprintf('Curso de contratación %02d', $i), range(1, 6)), $this->eventos($antiguos));

        $this->entrar()->assertRedirect(route('portal.panel'));
        // Sea cual sea el orden, los históricos quedan al final, en su propio bloque y SIEMPRE en el mismo orden estable (no se ordenan por una fecha que no tienen).
        $historicos = null;
        foreach (['recientes', 'antiguos', 'nombre'] as $orden) {
            $items = $acceso->tarjetasPaginadas($this->clave(), null, ['orden' => $orden])['items'];
            $sinFecha = array_map(fn ($t) => $t['publicado_at'] === null, $items);
            $primero = array_search(true, $sinFecha, true);
            $this->assertNotFalse($primero, $orden);
            $this->assertSame(array_fill(0, count($items) - $primero, true), array_slice($sinFecha, $primero), "{$orden}: los históricos van todos juntos al final");
            $bloque = array_column(array_slice($items, $primero), 'id');
            $historicos ??= $bloque;
            if ($orden !== 'nombre') {
                $this->assertSame($historicos, $bloque, "{$orden}: mismo orden estable de históricos");
            }
            $html = $this->verPanel(['orden' => $orden]);
            $this->assertSame(1, substr_count($html, 'data-separador="historicos"'), $orden);
            $this->assertLessThan(strpos($html, 'data-separador="historicos"'), strrpos($html, 'data-fecha-publicacion'), "{$orden}: lo publicado va antes del bloque");
        }

        // Por nombre de la capacitación, sin tildes ni mayúsculas (dentro de lo publicado).
        $nombre = $acceso->tarjetasPaginadas($this->clave(), null, ['orden' => 'nombre', 'q' => 'o 0']);
        $nombres = $this->eventos($nombre);
        $ordenados = $nombres;
        usort($ordenados, fn ($a, $b) => strcmp(mb_strtolower(Str::ascii($a)), mb_strtolower(Str::ascii($b))));
        $this->assertSame($ordenados, $nombres);
    }

    public function test_la_paginacion_recorre_el_mismo_listado_y_los_enlaces_conservan_los_filtros(): void
    {
        foreach (range(1, 24) as $i) {
            $this->publicar(sprintf('Módulo virtual %02d', $i), '1 de julio de 2026', sprintf('2026-07-%02d 10:00:00', $i));
        }
        $acceso = app(AccesoPortal::class);
        $completo = $this->eventos($acceso->tarjetasPaginadas($this->clave(), null, ['publicado' => '2026-07', 'page' => 1]));
        $completo = [...$completo, ...$this->eventos($acceso->tarjetasPaginadas($this->clave(), null, ['publicado' => '2026-07', 'page' => 2]))];
        $this->assertSame(array_map(fn ($i) => sprintf('Módulo virtual %02d', $i), range(24, 1)), $completo);

        $html = $this->panel(['publicado' => '2026-07', 'desde' => '2026-07-01', 'hasta' => '2026-07-31']);
        $this->assertSame(1, preg_match('/href="[^"]*\?([^"]*)"[^>]*rel="next"/', $html, $m), 'Debe haber un enlace «Siguiente»');
        parse_str(html_entity_decode($m[1]), $q);
        $this->assertSame(['publicado' => '2026-07', 'desde' => '2026-07-01', 'hasta' => '2026-07-31', 'page' => '2'], array_intersect_key($q, array_flip(['publicado', 'desde', 'hasta', 'page'])));
        $this->assertSame(20, substr_count($html, 'data-certificado='));
    }

    // ── Seguridad y rendimiento ──────────────────────────────────────────────

    public function test_otra_persona_no_ve_ni_filtra_las_publicaciones_de_ana(): void
    {
        $this->publicar('Curso solo de Ana', '1 de agosto de 2026', '2026-08-01 09:00:00');
        $this->publicar('Curso solo de Beto', '1 de enero de 2025', '2025-01-15 09:00:00', '9.300.001', 'BETO PRUEBA SINTETICO', 'beto@example.test');

        $ana = app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null);
        $this->assertContains('Curso solo de Ana', $this->eventos($ana));
        $this->assertNotContains('Curso solo de Beto', $this->eventos($ana));
        $this->assertArrayNotHasKey('2025', $ana['periodos'], 'el periodo de otra persona ni siquiera se ofrece');

        $this->entrar()->assertRedirect(route('portal.panel'));
        // Ningún filtro (válido, inválido o apuntando al periodo, año o nombre de Beto) le muestra a Ana algo de Beto.
        foreach ([
            ['publicado' => '2025-01'], ['publicado' => '2025'], ['anio' => '2025'], ['q' => 'beto'], ['desde' => '2025-01-15', 'hasta' => '2025-01-15'],
            ['publicado' => '2025-13'], ['anio' => 'x'], ['desde' => '2025-02-30'], ['q' => 'Curso solo de Beto', 'orden' => 'antiguos', 'page' => 99],
        ] as $f) {
            $l = app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null, $f);
            $this->assertNotContains('Curso solo de Beto', $this->eventos($l), json_encode($f));
            // Se miran los TÍTULOS de las tarjetas: el buscador devuelve lo que la persona escribió, y eso no es un dato de nadie.
            preg_match_all('/<h2>(.*?)<\/h2>/s', $this->verPanel($f), $titulos);
            $this->assertNotContains('Curso solo de Beto', array_map(fn ($t) => html_entity_decode(trim($t)), $titulos[1]), json_encode($f));
        }
        $this->entrar('9.300.001', 'beto@example.test')->assertRedirect(route('portal.panel'));
        $beto = $this->get(route('portal.panel'))->assertOk()->getContent();
        $this->assertStringContainsString('Curso solo de Beto', $beto);
        $this->assertStringNotContainsString('Curso solo de Ana', $beto);
    }

    public function test_miles_de_historicos_de_otras_personas_no_cambian_las_consultas_del_panel(): void
    {
        $this->publicar('Curso con volumen', '1 de agosto de 2026', '2026-08-01 09:00:00');
        $contar = function () {
            DB::flushQueryLog();
            DB::enableQueryLog();
            $l = app(AccesoPortal::class)->tarjetasPaginadas($this->clave(), null, ['publicado' => '2026']);
            $n = count(DB::getQueryLog());
            DB::disableQueryLog();

            return [$n, $l['total']];
        };
        [$antes, $totalAntes] = $contar();

        // 3.000 históricos SINTÉTICOS de otros documentos (como el volumen real de 25.946, repartido entre miles de personas).
        $base = (array) DB::table('cf_certificados_legado')->where('documento_clave', $this->clave())->orderBy('id')->first();
        unset($base['id']);
        $filas = [];
        for ($i = 1; $i <= 3000; $i++) {
            $filas[] = array_merge($base, ['documento' => (string) (80000000 + $i), 'documento_clave' => (string) (80000000 + $i), 'codigo_legado' => null, 'grupo_duplicado' => null]
                + (array_key_exists('old_id', $base) ? ['old_id' => 700000 + $i] : []));
            if (count($filas) === 500) {
                DB::table('cf_certificados_legado')->insert($filas);
                $filas = [];
            }
        }
        $inicio = microtime(true);
        [$despues, $totalDespues] = $contar();
        $segundos = microtime(true) - $inicio;

        $this->assertSame($antes, $despues, 'el número de consultas no depende de cuántos históricos haya de otras personas');
        $this->assertSame($totalAntes, $totalDespues);
        $this->assertLessThan(2.0, $segundos);
    }
}
