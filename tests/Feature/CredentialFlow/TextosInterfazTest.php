<?php

namespace Tests\Feature\CredentialFlow;

use Illuminate\Support\Facades\Route;

/**
 * Contratos de texto de la interfaz de Credential Flow (no hay pruebas de componentes Vue): la portada ya no es un módulo
 * «en construcción», cada tarjeta apunta a una pantalla real y la gente ve «bases de participantes», no «lotes».
 * Los nombres internos (rutas, modelos, tablas) siguen usando «lote».
 */
class TextosInterfazTest extends CredentialFlowTestCase
{
    private function fuente(string $ruta): string
    {
        return file_get_contents(resource_path('js/'.$ruta));
    }

    public function test_la_portada_ya_no_dice_en_construccion_ni_proximamente(): void
    {
        $vue = $this->fuente('Pages/CredentialFlow/Inicio.vue');

        foreach (['En construcción', 'Módulo en construcción', 'Próximamente', 'Construction'] as $texto) {
            $this->assertStringNotContainsString($texto, $vue);
        }
        $this->assertStringContainsString('titulo: "Bases de participantes"', $vue);
        $this->assertStringContainsString('titulo: "Generación"', $vue);
        $this->assertStringContainsString('titulo: "Historial"', $vue);
    }

    public function test_cada_tarjeta_de_la_portada_apunta_a_una_ruta_que_existe(): void
    {
        preg_match_all('/ruta: "([a-z.\-]+)"/', $this->fuente('Pages/CredentialFlow/Inicio.vue'), $m);

        $this->assertCount(6, $m[1], 'Plantillas, Bases, Generación, Historial, Histórico y Envíos (solo lectura)');
        foreach ($m[1] as $nombre) {
            $this->assertTrue(Route::has($nombre), "La ruta $nombre no existe");
        }
    }

    public function test_la_portada_se_muestra_a_un_admin(): void
    {
        $this->actingAs($this->admin())->get(route('credential-flow.index'))->assertOk();
    }

    public function test_el_listado_y_el_alta_hablan_de_bases_de_participantes(): void
    {
        $index = $this->fuente('Pages/CredentialFlow/Lotes/Index.vue');
        $nuevo = $this->fuente('Pages/CredentialFlow/Lotes/Nuevo.vue');

        $this->assertStringContainsString('title="Bases de participantes"', $index);
        $this->assertStringContainsString('Nueva base', $index);
        $this->assertStringContainsString('Crear primera base', $index);
        $this->assertStringContainsString('title="Nueva base"', $nuevo);

        foreach (['Lotes de participantes', 'Nuevo lote', 'Crear primer lote', 'Aún no hay lotes'] as $viejo) {
            $this->assertStringNotContainsString($viejo, $index.$nuevo);
        }
    }

    public function test_las_rutas_internas_siguen_llamandose_lotes(): void
    {
        foreach (['credential-flow.lotes.index', 'credential-flow.lotes.nuevo', 'credential-flow.lotes.show', 'credential-flow.lotes.emitir', 'credential-flow.lotes.zip'] as $nombre) {
            $this->assertTrue(Route::has($nombre), "No se debe renombrar la ruta $nombre");
        }
    }
}
