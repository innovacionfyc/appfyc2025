<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

/**
 * Fase 12E: el panel pagina, busca, filtra por año y ordena EN EL SERVIDOR, con las mismas tarjetas (y la misma autorización) que el listado completo.
 * Desde 2 certificados hay buscador y filtros; con uno solo, la vista sigue siendo simple. Datos sintéticos.
 */
class PortalPanelPaginadoTest extends PortalTestCase
{
    /** Clona N certificados del documento de prueba con eventos nuevos (nombre y año controlados). Devuelve los ids creados. */
    private function agregar(int $n, string $prefijo = 'Taller Sintético'): void
    {
        $base = DB::table('cf_certificados_legado')->where('documento_clave', AccesoPortal::claveDocumento(self::DOCUMENTO))->where('conciliacion_estado', 'ok')
            ->where('estado', 'vigente')->whereNotNull('plantilla_legado_id')->whereNull('reemplazado_por_emision_id')->orderBy('id')->first();
        $this->assertNotNull($base, 'Hace falta un certificado base conciliado');
        $evento = (array) DB::table('cf_eventos')->where('id', $base->evento_id)->first();
        for ($i = 1; $i <= $n; $i++) {
            $e = $evento;
            unset($e['id']);
            $e['nombre'] = sprintf('%s %02d', $prefijo, $i);
            $e['anio'] = 2020 + ($i % 5);
            $e['nombre_normalizado'] = mb_strtolower(Str::ascii($e['nombre']));
            $idEvento = DB::table('cf_eventos')->insertGetId($e);
            $c = (array) $base;
            unset($c['id']);
            $c['evento_id'] = $idEvento;
            $c['codigo_legado'] = null;
            $c['grupo_duplicado'] = null;
            if (array_key_exists('old_id', $c)) {
                $c['old_id'] = 900000 + $i;
            }
            DB::table('cf_certificados_legado')->insert($c);
        }
    }

    private function panel(array $query = [])
    {
        $this->entrar()->assertRedirect(route('portal.panel'));

        return $this->get(route('portal.panel', $query))->assertOk();
    }

    private function eventosEn(string $html): array
    {
        preg_match_all('/<h2>(.*?)<\/h2>/s', $html, $m);

        return array_map(fn ($t) => html_entity_decode(trim($t)), $m[1]);
    }

    public function test_con_pocos_certificados_hay_buscador_y_filtros_pero_no_paginador(): void
    {
        // Ana tiene pocos certificados (entre 2 y 12): ya puede buscar y filtrar; no hace falta paginar.
        $r = $this->panel();
        $r->assertSee('data-controles', false)->assertDontSee('data-paginador', false)->assertSee('data-resumen', false);
        $total = substr_count($r->getContent(), 'data-certificado=');
        $this->assertGreaterThanOrEqual(2, $total);
        $this->assertLessThanOrEqual(12, $total);

        $filtrado = $this->get(route('portal.panel', ['q' => 'alfa']))->assertOk()->getContent();
        $this->assertSame(['Curso Alfa 2024'], $this->eventosEn($filtrado));
    }

    public function test_con_mas_de_12_aparecen_controles_y_solo_20_por_pagina(): void
    {
        $this->agregar(30);
        $r = $this->panel();

        $r->assertSee('data-controles', false)->assertSee('data-paginador', false)->assertSee('Mostrando 1–20 de', false);
        $this->assertSame(20, substr_count($r->getContent(), 'data-certificado='));
        $this->assertLessThan(60_000, strlen($r->getContent()), 'La página no debe llevar cientos de tarjetas');
    }

    public function test_las_paginas_juntas_son_el_mismo_listado_completo_y_ordenado(): void
    {
        $this->agregar(30);
        $acceso = app(AccesoPortal::class);
        $clave = AccesoPortal::claveDocumento(self::DOCUMENTO);
        $completo = array_column($acceso->tarjetas($clave, null), 'evento');

        $this->entrar()->assertRedirect(route('portal.panel'));
        $juntos = [];
        for ($p = 1; $p <= (int) ceil(count($completo) / 20); $p++) {
            $juntos = array_merge($juntos, $this->eventosEn($this->get(route('portal.panel', ['page' => $p]))->getContent()));
        }

        $this->assertSame($completo, $juntos);
        $this->assertCount(count($completo), array_unique($juntos));
    }

    public function test_busqueda_filtra_en_servidor_sin_tildes_ni_mayusculas(): void
    {
        $this->agregar(30, 'Taller Evaluación');
        $r = $this->panel(['q' => 'EVALUACION 07']);

        $this->assertSame(['Taller Evaluación 07'], $this->eventosEn($r->getContent()));
        $r->assertSee('1 de 1', false)->assertSee('Limpiar');
    }

    public function test_filtro_de_anio_y_orden(): void
    {
        $this->agregar(30);
        $acceso = app(AccesoPortal::class);
        $clave = AccesoPortal::claveDocumento(self::DOCUMENTO);

        $solo2022 = $acceso->tarjetasPaginadas($clave, null, ['anio' => '2022']);
        $this->assertGreaterThan(0, $solo2022['total']);
        foreach ($solo2022['items'] as $t) {
            $this->assertSame(2022, $t['anio']);
        }

        // Todos son históricos (sin fecha de publicación registrada): su orden estable (año del evento desc, nombre) no cambia con «más recientes» ni
        // «más antiguos», porque no hay ninguna fecha de publicación con la que ordenarlos.
        $nuevos = array_column($acceso->tarjetasPaginadas($clave, null, ['orden' => 'recientes'])['items'], 'id');
        $viejos = array_column($acceso->tarjetasPaginadas($clave, null, ['orden' => 'antiguos'])['items'], 'id');
        $this->assertSame($nuevos, $viejos);
        $anios = array_column($acceso->tarjetasPaginadas($clave, null, ['orden' => 'antiguos'])['items'], 'anio');
        $desc = $anios;
        usort($desc, fn ($a, $b) => [$a === null ? 1 : 0, -($a ?? 0)] <=> [$b === null ? 1 : 0, -($b ?? 0)]);
        $this->assertSame($desc, $anios, 'año del evento, del más reciente al más antiguo');
    }

    public function test_los_enlaces_del_paginador_conservan_los_filtros(): void
    {
        $this->agregar(60, 'Seminario Aplicado');
        $r = $this->panel(['q' => 'seminario', 'orden' => 'antiguos']);
        $html = html_entity_decode($r->getContent());

        $this->assertStringContainsString('data-paginador', $html);
        $this->assertSame(1, preg_match('/href="[^"]*\?([^"]*)"[^>]*rel="next"/', $html, $m), 'Debe haber un enlace «Siguiente»');
        parse_str($m[1], $q);
        $this->assertSame('seminario', $q['q']);
        $this->assertSame('antiguos', $q['orden']);
        $this->assertSame('2', $q['page']);
        $this->assertArrayNotHasKey('anio', $q);
    }

    public function test_parametros_invalidos_no_rompen_ni_amplian_nada(): void
    {
        $this->agregar(30);
        $this->entrar();

        foreach (['?q[]=x&anio[]=1&orden[]=y&page[]=2', '?anio=abc&orden=zzz&page=-4', '?page=999', '?q='.str_repeat('a', 500), '?anio=1900'] as $qs) {
            $r = $this->get(route('portal.panel').$qs);
            $r->assertOk();
            $this->assertLessThanOrEqual(20, substr_count($r->getContent(), 'data-certificado='), $qs);
        }
    }

    public function test_no_aparecen_certificados_de_otro_documento_ni_cantidades_fuera_del_alcance(): void
    {
        $this->agregar(30);
        $otroDoc = '2.000.001';
        $acceso = app(AccesoPortal::class);
        $propios = $acceso->tarjetasPaginadas(AccesoPortal::claveDocumento(self::DOCUMENTO), null, [])['total_sin_filtros'];
        $ajenos = $acceso->tarjetasPaginadas(AccesoPortal::claveDocumento($otroDoc), null, [])['total_sin_filtros'];

        $this->assertGreaterThan(30, $propios);
        $this->assertNotSame($propios, $ajenos);
        $this->assertNotNull(Hmac::class);
    }

    public function test_la_descarga_sigue_pidiendo_pertenencia_al_documento(): void
    {
        $this->agregar(30);
        $this->entrar();
        $idAjeno = (int) DB::table('cf_certificados_legado')->where('documento_clave', '!=', AccesoPortal::claveDocumento(self::DOCUMENTO))->value('id');

        $this->get(route('portal.descargar', $idAjeno))->assertNotFound();
    }
}
