<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/** F&C Credential Flow · Fase 2: editor visual (página, PDF privado y guardado del diseño). */
class PlantillaEditorTest extends CredentialFlowTestCase
{
    // ── Utilidades ────────────────────────────────────────────────────────────

    private function elemento(array $cambios = []): array
    {
        return array_merge([
            'id' => (string) Str::uuid(),
            'type' => 'text',
            'field' => null,
            'text' => 'JUAN PÉREZ',
            'x' => 246.0,
            'y' => 223.38,
            'width' => 300,
            'height' => 40,
            'fontFamily' => 'Figtree',
            'fontSize' => 22,
            'fontWeight' => 700,
            'color' => '#000000',
            'align' => 'center',
        ], $cambios);
    }

    private function diseno(?array $elementos = null, array $pagina = ['width' => 792, 'height' => 612]): array
    {
        return ['page' => $pagina, 'elements' => $elementos ?? [$this->elemento()]];
    }

    private function urlDiseno(Plantilla $p): string
    {
        return route('credential-flow.plantillas.diseno.update', $p);
    }

    private function guardar(Plantilla $p, array $diseno)
    {
        return $this->actingAs($this->admin())->putJson($this->urlDiseno($p), ['diseno' => $diseno]);
    }

    /** Un guardado correcto redirige de vuelta al editor de la misma plantilla. */
    private function assertGuardado($respuesta, Plantilla $p): void
    {
        $respuesta->assertRedirect(route('credential-flow.plantillas.editor', $p));
    }

    // ── Acceso al editor ──────────────────────────────────────────────────────

    public function test_admin_puede_abrir_el_editor(): void
    {
        $p = $this->crearPlantilla(['nombre' => 'Asistencia', 'pdf' => $this->pdf('Diseño final.pdf')]);

        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CredentialFlow/Editor')
                ->where('plantilla.id', $p->id)
                ->where('plantilla.nombre', 'Asistencia')
                ->where('plantilla.nombre_archivo_original', 'Diseño final.pdf')
                ->where('diseno', null)
                ->where('pdfUrl', "/admin/credential-flow/plantillas/{$p->id}/pdf")
                ->where('schema.version', 1)
                ->where('schema.fuentes', ['Figtree', 'Arial', 'sans-serif'])
                ->missing('plantilla.archivo_pdf')
                ->missing('plantilla.hash_sha256'));
    }

    public function test_super_admin_puede_abrir_el_editor(): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->superAdmin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertOk();
    }

    public function test_comercial_no_puede_usar_el_editor_ni_el_pdf_ni_guardar(): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->comercial())->get(route('credential-flow.plantillas.editor', $p))->assertForbidden();
        $this->actingAs($this->comercial())->get(route('credential-flow.plantillas.pdf', $p))->assertForbidden();
        $this->actingAs($this->comercial())->putJson($this->urlDiseno($p), ['diseno' => $this->diseno()])->assertForbidden();

        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public function test_invitado_es_redirigido_al_login(): void
    {
        $p = $this->crearPlantilla();
        auth()->logout();

        $this->get(route('credential-flow.plantillas.editor', $p))->assertRedirect(route('login'));
        $this->get(route('credential-flow.plantillas.pdf', $p))->assertRedirect(route('login'));
        $this->put($this->urlDiseno($p), ['diseno' => $this->diseno()])->assertRedirect(route('login'));
        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public function test_una_plantilla_eliminada_no_se_puede_editar_ni_ver(): void
    {
        $p = $this->crearPlantilla();
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $p));

        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $p->id))->assertNotFound();
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $p->id))->assertNotFound();
        $this->guardar($p, $this->diseno())->assertNotFound();
    }

    // ── PDF privado ───────────────────────────────────────────────────────────

    public function test_el_pdf_privado_se_sirve_solo_al_admin(): void
    {
        $p = $this->crearPlantilla(['pdf' => $this->pdf('base.pdf', 'contenido único')]);

        $respuesta = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $p));

        $respuesta->assertOk();
        $this->assertSame('application/pdf', $respuesta->headers->get('Content-Type'));
        $this->assertSame('nosniff', $respuesta->headers->get('X-Content-Type-Options'));
        $this->assertStringContainsString('inline', (string) $respuesta->headers->get('Content-Disposition'));
        $this->assertStringContainsString('no-store', (string) $respuesta->headers->get('Cache-Control'));
        $this->assertStringContainsString('sandbox', (string) $respuesta->headers->get('Content-Security-Policy'));
        $this->assertSame($this->pdfContenido('contenido único'), $respuesta->streamedContent());
    }

    public function test_el_pdf_no_se_sirve_si_la_ruta_registrada_no_corresponde(): void
    {
        $victima = $this->crearPlantilla(['nombre' => 'Víctima', 'pdf' => $this->pdf('v.pdf', 'V')]);
        $manipulada = $this->crearPlantilla(['nombre' => 'Manipulada', 'pdf' => $this->pdf('m.pdf', 'M')]);

        DB::table('cf_plantillas')->where('id', $manipulada->id)->update(['archivo_pdf' => $victima->archivo_pdf]);
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $manipulada))->assertNotFound();

        DB::table('cf_plantillas')->where('id', $manipulada->id)->update(['archivo_pdf' => '../../../.env']);
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $manipulada))->assertNotFound();
    }

    public function test_el_pdf_devuelve_404_si_el_archivo_no_existe(): void
    {
        $p = $this->crearPlantilla();
        Storage::disk('local')->delete($p->archivo_pdf);

        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $p))->assertNotFound();
    }

    public function test_no_existe_ruta_publica_al_pdf(): void
    {
        $p = $this->crearPlantilla();

        foreach ([
            "/credential-flow/plantillas/{$p->id}/pdf",
            "/credential-flow/plantillas/{$p->id}/editor",
            "/{$p->archivo_pdf}",
            "/storage/{$p->archivo_pdf}",
        ] as $url) {
            $this->assertContains($this->get($url)->getStatusCode(), [403, 404], "URL pública inesperada: {$url}");
        }

        $publicas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'credential-flow') && ! str_starts_with($r->uri(), 'admin/'));
        $this->assertCount(0, $publicas);
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    // ── Guardar el diseño ─────────────────────────────────────────────────────

    public function test_guarda_un_diseno_valido(): void
    {
        $p = $this->crearPlantilla();
        $diseno = $this->diseno([
            $this->elemento(['text' => 'JUAN PÉREZ']),
            $this->elemento(['text' => 'C.C. 1.234', 'y' => 260, 'fontSize' => 19, 'fontWeight' => 500, 'color' => '#932833', 'align' => 'left']),
        ]);

        $this->assertGuardado($this->guardar($p, $diseno), $p);

        $guardado = Plantilla::findOrFail($p->id);
        $this->assertSame(1, $guardado->schema_version);
        $this->assertEquals(['width' => 792, 'height' => 612], $guardado->diseno['page']);
        $this->assertCount(2, $guardado->diseno['elements']);
        $this->assertSame('JUAN PÉREZ', $guardado->diseno['elements'][0]['text']);
        $this->assertSame('#932833', $guardado->diseno['elements'][1]['color']);
        $this->assertSame($diseno['elements'][0]['id'], $guardado->diseno['elements'][0]['id']);
    }

    public function test_guardar_por_el_flujo_web_redirige_con_aviso_de_exito_y_deja_auditoria(): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->admin())
            ->put($this->urlDiseno($p), ['diseno' => $this->diseno()])
            ->assertRedirect(route('credential-flow.plantillas.editor', $p))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $movimiento = Movimiento::where('modulo', 'credential-flow')->where('tipo', 'actualizacion')->first();
        $this->assertNotNull($movimiento);
        $this->assertSame($this->admin()->id, $movimiento->user_id);
    }

    public function test_un_diseno_sin_elementos_es_valido(): void
    {
        $p = $this->crearPlantilla();

        $this->assertGuardado($this->guardar($p, $this->diseno([])), $p);

        $this->assertSame([], Plantilla::findOrFail($p->id)->diseno['elements']);
    }

    public function test_el_diseno_persiste_al_volver_a_cargar_el_editor(): void
    {
        $p = $this->crearPlantilla();
        $e = $this->elemento(['text' => 'PERSISTE', 'x' => 123.45, 'y' => 67.89, 'fontSize' => 30, 'color' => '#123abc']);
        $this->assertGuardado($this->guardar($p, $this->diseno([$e])), $p);

        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.editor', $p))
            ->assertInertia(fn (Assert $page) => $page
                ->where('diseno.page.width', 792)
                ->where('diseno.page.height', 612)
                ->has('diseno.elements', 1)
                ->where('diseno.elements.0.id', $e['id'])
                ->where('diseno.elements.0.text', 'PERSISTE')
                ->where('diseno.elements.0.x', 123.45)
                ->where('diseno.elements.0.y', 67.89)
                ->where('diseno.elements.0.fontSize', 30)
                ->where('diseno.elements.0.color', '#123abc'));
    }

    public function test_guardar_dos_veces_reemplaza_el_diseno(): void
    {
        $p = $this->crearPlantilla();
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['text' => 'PRIMERO'])])), $p);
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['text' => 'SEGUNDO'])])), $p);

        $elementos = Plantilla::findOrFail($p->id)->diseno['elements'];
        $this->assertCount(1, $elementos);
        $this->assertSame('SEGUNDO', $elementos[0]['text']);
    }

    public function test_normaliza_numeros_texto_vacio_y_mayusculas(): void
    {
        $p = $this->crearPlantilla();
        $id = strtoupper((string) Str::uuid());

        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento([
            'id' => $id, 'text' => '', 'x' => 10.123456, 'color' => '#ABCDEF', 'fontSize' => 22.456,
        ])])), $p);

        $e = Plantilla::findOrFail($p->id)->diseno['elements'][0];
        $this->assertSame('', $e['text']);
        $this->assertSame(10.12, $e['x']);
        $this->assertSame('#abcdef', $e['color']);
        $this->assertSame(22.46, $e['fontSize']);
        $this->assertSame(strtolower($id), $e['id']);
    }

    // ── Rechazos ──────────────────────────────────────────────────────────────

    public function test_rechaza_json_mal_formado(): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->admin())
            ->call('PUT', $this->urlDiseno($p), [], [], [], ['CONTENT_TYPE' => 'application/json', 'HTTP_ACCEPT' => 'application/json'], '{"diseno": {"page": ')
            ->assertStatus(422)
            ->assertJsonValidationErrors('diseno');

        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public static function disenosConEstructuraInvalida(): array
    {
        return [
            'sin diseno' => [[]],
            'diseno como texto' => [['diseno' => 'no soy un objeto']],
            'diseno como numero' => [['diseno' => 42]],
            'sin page' => [['diseno' => ['elements' => []]]],
            'sin elements' => [['diseno' => ['page' => ['width' => 792, 'height' => 612]]]],
            'elements como texto' => [['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => 'x']]],
            'clave desconocida en diseno' => [['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => [], 'extra' => 1]]],
            'clave desconocida en page' => [['diseno' => ['page' => ['width' => 792, 'height' => 612, 'dpi' => 300], 'elements' => []]]],
        ];
    }

    #[DataProvider('disenosConEstructuraInvalida')]
    public function test_rechaza_estructuras_invalidas(array $cuerpo): void
    {
        $p = $this->crearPlantilla();

        $this->actingAs($this->admin())->putJson($this->urlDiseno($p), $cuerpo)->assertStatus(422);

        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public static function elementosFueraDeLimites(): array
    {
        return [
            'x negativa' => [['x' => -1], 'diseno.elements.0.x'],
            'y negativa' => [['y' => -0.6], 'diseno.elements.0.y'],
            'x enorme' => [['x' => 99999], 'diseno.elements.0.x'],
            'ancho cero' => [['width' => 0], 'diseno.elements.0.width'],
            'alto negativo' => [['height' => -5], 'diseno.elements.0.height'],
            'se sale por la derecha' => [['x' => 700, 'width' => 300], 'diseno.elements.0'],
            'se sale por abajo' => [['y' => 600, 'height' => 40], 'diseno.elements.0'],
            'mas ancho que la pagina' => [['x' => 0, 'width' => 900], 'diseno.elements.0'],
            'x no numerica' => [['x' => 'abc'], 'diseno.elements.0.x'],
            'tamaño de fuente enorme' => [['fontSize' => 500], 'diseno.elements.0.fontSize'],
            'tamaño de fuente diminuto' => [['fontSize' => 2], 'diseno.elements.0.fontSize'],
            'fuente no permitida' => [['fontFamily' => 'Comic Sans MS'], 'diseno.elements.0.fontFamily'],
            'grosor no permitido' => [['fontWeight' => 999], 'diseno.elements.0.fontWeight'],
            'color con nombre' => [['color' => 'red'], 'diseno.elements.0.color'],
            'color corto' => [['color' => '#123'], 'diseno.elements.0.color'],
            'color con inyeccion' => [['color' => '#000000;background:url(x)'], 'diseno.elements.0.color'],
            'alineacion no permitida' => [['align' => 'justify'], 'diseno.elements.0.align'],
            'tipo no permitido' => [['type' => 'image'], 'diseno.elements.0.type'],
            'id que no es uuid' => [['id' => 'no-es-un-uuid'], 'diseno.elements.0.id'],
            'texto demasiado largo' => [['text' => str_repeat('a', 501)], 'diseno.elements.0.text'],
            'field con formato invalido' => [['field' => 'Nombre Completo!'], 'diseno.elements.0.field'],
            'clave desconocida en el elemento' => [['script' => '<script>alert(1)</script>'], 'diseno.elements.0'],
        ];
    }

    #[DataProvider('elementosFueraDeLimites')]
    public function test_rechaza_elementos_invalidos_o_fuera_de_limites(array $cambios, string $campoConError): void
    {
        $p = $this->crearPlantilla();

        $this->guardar($p, $this->diseno([$this->elemento($cambios)]))
            ->assertStatus(422)
            ->assertJsonValidationErrors($campoConError);

        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public static function paginasInvalidas(): array
    {
        return [
            'ancho diminuto' => [['width' => 50, 'height' => 612], 'diseno.page.width'],
            'ancho gigante' => [['width' => 5000, 'height' => 612], 'diseno.page.width'],
            'alto negativo' => [['width' => 792, 'height' => -1], 'diseno.page.height'],
            'ancho no numerico' => [['width' => 'x', 'height' => 612], 'diseno.page.width'],
            'clave desconocida' => [['width' => 792, 'height' => 612, 'dpi' => 300], 'diseno.page'],
        ];
    }

    #[DataProvider('paginasInvalidas')]
    public function test_rechaza_dimensiones_de_pagina_no_razonables(array $pagina, string $campoConError): void
    {
        $p = $this->crearPlantilla();

        $this->guardar($p, $this->diseno([], $pagina))->assertStatus(422)->assertJsonValidationErrors($campoConError);

        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public function test_rechaza_mas_elementos_del_maximo_y_ids_repetidos(): void
    {
        $p = $this->crearPlantilla();

        $demasiados = array_map(fn () => $this->elemento(), range(1, 101));
        $this->guardar($p, $this->diseno($demasiados))->assertStatus(422)->assertJsonValidationErrors('diseno.elements');

        $repetido = $this->elemento();
        $this->guardar($p, $this->diseno([$repetido, $repetido]))->assertStatus(422);
        $this->guardar($p, $this->diseno([$repetido, $this->elemento(['id' => strtoupper($repetido['id'])])]))->assertStatus(422);

        $this->assertNull(Plantilla::findOrFail($p->id)->diseno);
    }

    public function test_acepta_exactamente_el_maximo_y_un_elemento_pegado_al_borde(): void
    {
        $p = $this->crearPlantilla();

        $maximo = array_map(fn () => $this->elemento(), range(1, 100));
        $this->assertGuardado($this->guardar($p, $this->diseno($maximo)), $p);

        $alBorde = $this->elemento(['x' => 492, 'width' => 300, 'y' => 572, 'height' => 40]);
        $this->assertGuardado($this->guardar($p, $this->diseno([$alBorde])), $p);
    }

    public function test_un_diseno_rechazado_no_pisa_el_diseno_guardado(): void
    {
        $p = $this->crearPlantilla();
        $this->assertGuardado($this->guardar($p, $this->diseno([$this->elemento(['text' => 'BUENO'])])), $p);

        $this->guardar($p, $this->diseno([$this->elemento(['text' => 'MALO', 'x' => -50])]))->assertStatus(422);

        $this->assertSame('BUENO', Plantilla::findOrFail($p->id)->diseno['elements'][0]['text']);
    }

    // ── Aislamiento ───────────────────────────────────────────────────────────

    public function test_academia_certificados_sigue_intacto(): void
    {
        $this->assertSame('/admin/certificadosWeb', route('eventos.certificados', [], false));

        $this->actingAs($this->admin())
            ->get('/admin/certificadosWeb')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Certificados/CertificadosWeb'));

        $rutas = collect(app('router')->getRoutes()->getRoutes())->map(fn ($r) => $r->uri());
        $this->assertSame(1, $rutas->filter(fn ($u) => $u === 'admin/certificadosWeb')->count());
        $this->assertFalse($rutas->contains(fn ($u) => str_contains($u, 'certificadosWeb') && str_contains($u, 'credential-flow')));
    }
}
