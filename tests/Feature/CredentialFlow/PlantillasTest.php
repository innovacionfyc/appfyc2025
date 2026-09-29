<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Plantilla;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Inertia\Testing\AssertableInertia as Assert;
use PHPUnit\Framework\Attributes\DataProvider;

/** F&C Credential Flow · Fase 1 (plantillas). */
class PlantillasTest extends CredentialFlowTestCase
{
    // ── Acceso ────────────────────────────────────────────────────────────────

    public function test_usuario_no_autenticado_es_redirigido_al_login(): void
    {
        $this->get(route('credential-flow.plantillas.index'))->assertRedirect(route('login'));
        $this->post(route('credential-flow.plantillas.store'), [])->assertRedirect(route('login'));
        $this->delete('/admin/credential-flow/plantillas/1')->assertRedirect(route('login'));
    }

    public function test_rol_sin_permiso_recibe_403(): void
    {
        $this->actingAs($this->comercial())
            ->get(route('credential-flow.plantillas.index'))
            ->assertForbidden();

        $this->actingAs($this->comercial())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => 'X', 'pdf' => $this->pdf()])
            ->assertForbidden();

        $this->assertSame(0, Plantilla::count());
    }

    public function test_super_admin_puede_entrar(): void
    {
        $this->actingAs($this->superAdmin())
            ->get(route('credential-flow.plantillas.index'))
            ->assertOk();
    }

    // ── Listado ───────────────────────────────────────────────────────────────

    public function test_admin_ve_el_listado_vacio(): void
    {
        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.index'))
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page
                ->component('CredentialFlow/Plantillas')
                ->has('plantillas', 0)
                ->where('stats.total', 0));
    }

    public function test_admin_ve_las_plantillas_con_los_datos_de_la_interfaz(): void
    {
        $plantilla = $this->crearPlantilla(['nombre' => 'Asistencia 2026', 'pdf' => $this->pdf('Mi diseño final.pdf')]);

        $this->actingAs($this->admin())
            ->get(route('credential-flow.plantillas.index'))
            ->assertInertia(fn (Assert $page) => $page
                ->has('plantillas', 1)
                ->where('plantillas.0.id', $plantilla->id)
                ->where('plantillas.0.nombre', 'Asistencia 2026')
                ->where('plantillas.0.descripcion', 'Descripción de prueba')
                ->where('plantillas.0.nombre_archivo_original', 'Mi diseño final.pdf')
                ->has('plantillas.0.created_at')
                ->missing('plantillas.0.archivo_pdf')
                ->missing('plantillas.0.hash_sha256')
                ->where('stats.total', 1));
    }

    // ── Creación ──────────────────────────────────────────────────────────────

    public function test_admin_crea_una_plantilla_con_pdf_valido(): void
    {
        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), [
                'nombre' => '  Certificado de asistencia  ',
                'descripcion' => 'Para congresos',
                'pdf' => $this->pdf('Original del diseñador.pdf'),
            ])
            ->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('success')
            ->assertSessionHasNoErrors();

        $plantilla = Plantilla::firstOrFail();
        $this->assertSame('Certificado de asistencia', $plantilla->nombre);
        $this->assertSame('Para congresos', $plantilla->descripcion);
        $this->assertSame('Original del diseñador.pdf', $plantilla->nombre_archivo_original);
        $this->assertSame(1, $plantilla->schema_version);
        $this->assertNull($plantilla->diseno);
        $this->assertSame($this->admin()->id, $plantilla->created_by);
    }

    public function test_descripcion_vacia_se_guarda_como_nula(): void
    {
        $this->crearPlantilla(['descripcion' => '']);

        $this->assertNull(Plantilla::firstOrFail()->descripcion);
    }

    public function test_nombre_y_pdf_son_obligatorios(): void
    {
        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => '   '])
            ->assertSessionHasErrors(['nombre', 'pdf']);

        $this->assertSame(0, Plantilla::count());
    }

    public function test_nombre_demasiado_largo_se_rechaza(): void
    {
        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => str_repeat('a', 201), 'pdf' => $this->pdf()])
            ->assertSessionHasErrors('nombre');
    }

    public static function archivosQueNoSonPdf(): array
    {
        return [
            'texto plano' => ['notas.txt', 'hola mundo'],
            'imagen PNG renombrada a .pdf' => ['falso.pdf', "\x89PNG\r\n\x1a\n".str_repeat("\0", 64)],
            'ejecutable renombrado a .pdf' => ['programa.pdf', "MZ\x90\x00".str_repeat("\0", 64)],
            'SVG' => ['logo.svg', '<svg xmlns="http://www.w3.org/2000/svg"><script>alert(1)</script></svg>'],
            'SVG renombrado a .pdf' => ['logo.pdf', '<svg xmlns="http://www.w3.org/2000/svg"></svg>'],
            'ZIP' => ['paquete.zip', "PK\x03\x04".str_repeat("\0", 64)],
            'ZIP renombrado a .pdf' => ['paquete.pdf', "PK\x03\x04".str_repeat("\0", 64)],
            'PDF verdadero con extensión incorrecta' => ['base.txt', "%PDF-1.4\n%%EOF\n"],
            'PDF con doble extensión' => ['base.pdf.exe', "%PDF-1.4\n%%EOF\n"],
        ];
    }

    #[DataProvider('archivosQueNoSonPdf')]
    public function test_no_acepta_archivos_que_no_sean_pdf(string $nombre, string $contenido): void
    {
        $archivo = UploadedFile::fake()->createWithContent($nombre, $contenido);

        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => 'X', 'pdf' => $archivo])
            ->assertSessionHasErrors('pdf');

        $this->assertSame(0, Plantilla::count());
        $this->assertSame([], Storage::disk('local')->allFiles());
    }

    public function test_no_acepta_archivo_con_extension_y_mime_pdf_pero_sin_contenido_pdf(): void
    {
        // fake()->create() declara application/pdf pero el contenido no es un PDF
        $archivo = UploadedFile::fake()->create('vacio.pdf', 10, 'application/pdf');

        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => 'X', 'pdf' => $archivo])
            ->assertSessionHasErrors('pdf');

        $this->assertSame(0, Plantilla::count());
    }

    public function test_no_acepta_un_pdf_superior_a_20_mb(): void
    {
        $grande = UploadedFile::fake()->create('grande.pdf', 20481, 'application/pdf');

        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => 'X', 'pdf' => $grande])
            ->assertSessionHasErrors('pdf');

        $this->assertSame(0, Plantilla::count());
    }

    public function test_acepta_un_pdf_que_llega_justo_al_limite(): void
    {
        $relleno = str_repeat(' ', (20480 * 1024) - strlen($this->pdfContenido()));
        $limite = UploadedFile::fake()->createWithContent('limite.pdf', $this->pdfContenido($relleno));

        $this->actingAs($this->admin())
            ->post(route('credential-flow.plantillas.store'), ['nombre' => 'Límite', 'pdf' => $limite])
            ->assertSessionHasNoErrors();

        $this->assertSame(1, Plantilla::count());
    }

    // ── Almacenamiento privado ────────────────────────────────────────────────

    public function test_guarda_el_pdf_como_base_pdf_en_el_disco_privado_y_nunca_en_el_publico(): void
    {
        $plantilla = $this->crearPlantilla(['pdf' => $this->pdf('Nombre que no debe usarse.pdf')]);

        $esperada = "credential-flow/plantillas/{$plantilla->id}/base.pdf";
        $this->assertSame($esperada, $plantilla->archivo_pdf);

        Storage::disk('local')->assertExists($esperada);
        $this->assertSame([$esperada], Storage::disk('local')->allFiles());
        $this->assertSame([], Storage::disk('public')->allFiles());
    }

    public function test_el_nombre_original_no_se_usa_como_nombre_fisico(): void
    {
        $plantilla = $this->crearPlantilla(['pdf' => $this->pdf('../../etc/passwd.pdf')]);

        $this->assertSame("credential-flow/plantillas/{$plantilla->id}/base.pdf", $plantilla->archivo_pdf);
        $this->assertSame('passwd.pdf', $plantilla->nombre_archivo_original);
        $this->assertSame([$plantilla->archivo_pdf], Storage::disk('local')->allFiles());
    }

    public function test_guarda_el_hash_sha256_del_pdf_subido(): void
    {
        $plantilla = $this->crearPlantilla(['pdf' => $this->pdf('hash.pdf', 'contenido distintivo')]);

        $hash = hash('sha256', $this->pdfContenido('contenido distintivo'));
        $this->assertSame($hash, $plantilla->hash_sha256);
        $this->assertSame($hash, hash('sha256', Storage::disk('local')->get($plantilla->archivo_pdf)));
    }

    public function test_cada_plantilla_usa_su_propia_carpeta(): void
    {
        $a = $this->crearPlantilla(['nombre' => 'A', 'pdf' => $this->pdf('a.pdf', 'A')]);
        $b = $this->crearPlantilla(['nombre' => 'B', 'pdf' => $this->pdf('b.pdf', 'B')]);

        $this->assertNotSame($a->archivo_pdf, $b->archivo_pdf);
        $this->assertNotSame($a->hash_sha256, $b->hash_sha256);
        Storage::disk('local')->assertExists([$a->archivo_pdf, $b->archivo_pdf]);
    }

    // ── Eliminación ───────────────────────────────────────────────────────────

    public function test_elimina_la_plantilla_con_soft_delete_y_borra_su_carpeta_privada(): void
    {
        $plantilla = $this->crearPlantilla();
        Storage::disk('local')->assertExists($plantilla->archivo_pdf);

        $this->actingAs($this->admin())
            ->delete(route('credential-flow.plantillas.destroy', $plantilla))
            ->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('success')
            ->assertSessionMissing('error');

        $this->assertSame(0, Plantilla::count());
        $this->assertSame(1, Plantilla::onlyTrashed()->count());
        Storage::disk('local')->assertMissing($plantilla->archivo_pdf);
        $this->assertSame([], Storage::disk('local')->directories('credential-flow/plantillas'));
    }

    public function test_al_eliminar_una_plantilla_no_se_tocan_los_archivos_de_otra(): void
    {
        $a = $this->crearPlantilla(['nombre' => 'A', 'pdf' => $this->pdf('a.pdf', 'A')]);
        $b = $this->crearPlantilla(['nombre' => 'B', 'pdf' => $this->pdf('b.pdf', 'B')]);

        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $a));

        Storage::disk('local')->assertMissing($a->archivo_pdf);
        Storage::disk('local')->assertExists($b->archivo_pdf);
        $this->assertSame([$b->id], Plantilla::pluck('id')->all());
    }

    public function test_si_la_ruta_registrada_no_corresponde_no_se_borra_ningun_archivo_y_se_informa(): void
    {
        $victima = $this->crearPlantilla(['nombre' => 'Víctima', 'pdf' => $this->pdf('v.pdf', 'V')]);
        $manipulada = $this->crearPlantilla(['nombre' => 'Manipulada', 'pdf' => $this->pdf('m.pdf', 'M')]);

        // Registro alterado: apunta al archivo de otra plantilla
        DB::table('cf_plantillas')->where('id', $manipulada->id)->update(['archivo_pdf' => $victima->archivo_pdf]);

        $this->actingAs($this->admin())
            ->delete(route('credential-flow.plantillas.destroy', $manipulada))
            ->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('error');

        Storage::disk('local')->assertExists($victima->archivo_pdf);
        $this->assertSame([$manipulada->id], Plantilla::onlyTrashed()->pluck('id')->all());
    }

    public function test_no_se_puede_eliminar_una_plantilla_inexistente_o_ya_eliminada(): void
    {
        $plantilla = $this->crearPlantilla();
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $plantilla));

        $this->actingAs($this->admin())
            ->delete(route('credential-flow.plantillas.destroy', $plantilla->id))
            ->assertNotFound();
        $this->actingAs($this->admin())->delete('/admin/credential-flow/plantillas/999999')->assertNotFound();
    }

    // ── Aislamiento ───────────────────────────────────────────────────────────

    public function test_no_existe_ninguna_ruta_publica_de_credential_flow(): void
    {
        foreach (['/credential-flow', '/credential-flow/plantillas', '/plantillas'] as $ruta) {
            $this->get($ruta)->assertNotFound();
        }
        // Route::fallback del proyecto es solo GET: un POST a una URL inexistente responde 405.
        $this->assertContains($this->post('/credential-flow/plantillas', [])->getStatusCode(), [404, 405]);
        $this->assertContains($this->delete('/credential-flow/plantillas/1')->getStatusCode(), [404, 405]);

        $publicas = collect(app('router')->getRoutes()->getRoutes())
            ->filter(fn ($r) => str_contains($r->uri(), 'credential-flow') && ! str_starts_with($r->uri(), 'admin/'));
        $this->assertCount(0, $publicas);
    }

    public function test_el_pdf_no_se_expone_por_una_url_publica_directa(): void
    {
        $plantilla = $this->crearPlantilla();

        foreach (["/storage/{$plantilla->archivo_pdf}", "/{$plantilla->archivo_pdf}"] as $url) {
            $this->assertContains($this->get($url)->getStatusCode(), [403, 404], "URL pública inesperada: {$url}");
        }
    }

    public function test_no_interfiere_con_admin_certificados_web(): void
    {
        $this->assertSame('/admin/certificadosWeb', route('eventos.certificados', [], false));

        $this->actingAs($this->admin())
            ->get('/admin/certificadosWeb')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('Certificados/CertificadosWeb'));

        $this->actingAs($this->admin())
            ->get('/admin/credential-flow')
            ->assertOk()
            ->assertInertia(fn (Assert $page) => $page->component('CredentialFlow/Inicio'));
    }
}
