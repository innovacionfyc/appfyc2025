<?php

namespace Tests\Feature\CredentialFlow\Rehearsal;

use App\Http\Middleware\CabecerasAdminSensible;
use Illuminate\Support\Facades\Route;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/** Fase 11A: cubetas de throttle independientes en las rutas mutadoras y cabeceras `no-store` en las pantallas históricas de administración. */
class ThrottlesYCabecerasTest extends HistoricoTestCase
{
    private const CUBETAS = [
        'credential-flow.plantillas.store' => ['cf-plantilla-crear', 10],
        'credential-flow.plantillas.destroy' => ['cf-plantilla-borrar', 20],
        'credential-flow.plantillas.diseno.update' => ['cf-plantilla-diseno', 60],
        'credential-flow.lotes.update' => ['cf-lote-editar', 30],
        'credential-flow.lotes.destroy' => ['cf-lote-borrar', 20],
        'credential-flow.participantes.store' => ['cf-participante-crear', 60],
        'credential-flow.participantes.update' => ['cf-participante-editar', 60],
        'credential-flow.participantes.destroy' => ['cf-participante-borrar', 60],
    ];

    public function test_las_ocho_rutas_declaran_su_propia_cubeta_con_el_limite_esperado(): void
    {
        $usadas = [];
        foreach (self::CUBETAS as $ruta => [$cubeta, $max]) {
            $mw = Route::getRoutes()->getByName($ruta)->gatherMiddleware();
            $this->assertContains("throttle:{$max},1,{$cubeta}", $mw, $ruta);
            $usadas[] = $cubeta;
        }
        $this->assertCount(8, array_unique($usadas), 'ninguna cubeta se comparte');
    }

    public function test_el_limite_se_aplica_con_429_y_no_afecta_a_las_otras_cubetas(): void
    {
        $admin = $this->admin();
        for ($i = 0; $i < 10; $i++) {
            $this->actingAs($admin)->post(route('credential-flow.plantillas.store'), [])->assertStatus(302);
        }
        $this->actingAs($admin)->post(route('credential-flow.plantillas.store'), [])->assertStatus(429);

        // Otra cubeta (borrar lote) sigue disponible: responde 404 por el lote inexistente, no 429.
        $this->actingAs($admin)->delete(route('credential-flow.lotes.destroy', 999999))->assertNotFound();
        $this->actingAs($admin)->delete(route('credential-flow.plantillas.destroy', 999999))->assertNotFound();
    }

    public function test_las_rutas_sin_sesion_no_llegan_a_la_cubeta(): void
    {
        $this->post(route('credential-flow.plantillas.store'), [])->assertRedirect();
    }

    public function test_las_pantallas_historicas_llevan_no_store_y_siguen_siendo_inertia(): void
    {
        $admin = $this->admin();
        foreach (['credential-flow.historico.casos.index', 'credential-flow.historico.index'] as $nombre) {
            if (! Route::has($nombre)) {
                continue;
            }
            $r = $this->actingAs($admin)->get(route($nombre))->assertOk();
            $cc = (string) $r->headers->get('Cache-Control');
            $this->assertStringContainsString('no-store', $cc, $nombre);
            $this->assertStringContainsString('private', $cc, $nombre);
            $this->assertSame('nosniff', $r->headers->get('X-Content-Type-Options'));
            $this->assertIsArray($r->viewData('page'), 'Inertia intacto');
        }
    }

    public function test_todas_las_rutas_del_grupo_historico_pasan_por_el_middleware(): void
    {
        $n = 0;
        foreach (Route::getRoutes() as $ruta) {
            if (str_starts_with((string) $ruta->getName(), 'credential-flow.historico.')) {
                $n++;
                $this->assertContains(CabecerasAdminSensible::class, $ruta->gatherMiddleware(), (string) $ruta->getName());
            }
        }
        $this->assertGreaterThan(5, $n);
    }
}
