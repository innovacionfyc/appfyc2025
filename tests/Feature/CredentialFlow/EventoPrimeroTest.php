<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;
use Tests\Feature\CredentialFlow\Support\PdfBase;

/**
 * Flujo «evento primero»: se crea (o elige) el evento y luego se importan sus participantes; la base queda asociada sola,
 * no nacen eventos duplicados por modalidad ni por volver a importar, y el mismo archivo no se importa dos veces en un evento.
 */
class EventoPrimeroTest extends CredentialFlowTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => [
            'database/migrations/2026_10_06_100000_cf_eventos_table.php',
            'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
            'database/migrations/2026_10_06_100200_add_correo_columns_to_cf_participantes_table.php',
            'database/migrations/2026_10_06_100300_cf_plantillas_legado_table.php',
            'database/migrations/2026_10_06_100400_cf_certificados_legado_table.php',
            'database/migrations/2026_10_06_100500_cf_descargas_table.php',
            'database/migrations/2026_10_06_100600_cf_correos_table.php',
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
    }

    private function plantilla(): Plantilla
    {
        $p = $this->crearPlantilla();
        Storage::disk('local')->put($p->rutaPdfEsperada(), PdfBase::crear());
        $el = fn (string $c, int $i) => ['id' => sprintf('00000000-0000-4000-8000-%012d', $i + 1), 'type' => 'text', 'field' => $c, 'text' => '', 'x' => 96, 'y' => 60 + $i * 60, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center'];
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $p->update(['diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => array_map($el, $campos, array_keys($campos))]]);

        return $p->fresh();
    }

    private function archivo(int $filas = 2, int $desde = 1)
    {
        $f = [['nombre_completo', 'documento']];
        for ($i = $desde; $i < $desde + $filas; $i++) {
            $f[] = ["Persona $i", 'C.C. '.number_format(10_000_000 + $i, 0, ',', '.')];
        }

        return A::xlsx($f);
    }

    private function crearEvento(string $nombre, string $fecha, array $extra = [])
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.eventos.store'), ['nombre' => $nombre, 'fecha' => $fecha] + $extra);
    }

    private function importar(Plantilla $p, ?int $eventoId, $archivo, array $extra = [])
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), [
            'plantilla_id' => $p->id, 'nombre' => 'Base', 'evento' => 'Texto cualquiera', 'fecha' => '16 de octubre de 2026', 'intensidad_horaria' => '5 horas',
            'archivo' => $archivo, 'evento_id' => $eventoId,
        ] + $extra, ['Accept' => 'application/json']);
    }

    public function test_crear_un_evento_lo_deja_listo_para_importar(): void
    {
        $this->crearEvento('Módulo de actualización en violencias basadas en género — Ley 2605 de 2026', '16 de octubre de 2026')->assertRedirect();

        $e = EventoCertificacion::firstOrFail();
        $this->assertSame('credential_flow', $e->origen);
        $this->assertSame(2026, (int) $e->anio);
        $this->assertSame('activo', $e->estado);
    }

    public function test_crear_dos_veces_el_mismo_evento_no_lo_duplica(): void
    {
        $this->crearEvento('Seminario de Contratación Pública', '10 de octubre de 2026');
        $this->crearEvento('SEMINARIO DE CONTRATACION PUBLICA.', '11 de octubre de 2026')->assertRedirect();

        $this->assertSame(1, EventoCertificacion::count());
    }

    public function test_mismo_nombre_en_otro_anio_es_otro_evento(): void
    {
        $this->crearEvento('Seminario de Contratación Pública', '10 de octubre de 2025');
        $this->crearEvento('Seminario de Contratación Pública', '10 de octubre de 2026');

        $this->assertSame(2, EventoCertificacion::count());
    }

    public function test_sin_anio_o_ante_un_homonimo_historico_exige_confirmar(): void
    {
        EventoCertificacion::create(['nombre' => 'Foro de Pruebas', 'nombre_normalizado' => NombreConservador::normalizar('Foro de Pruebas'), 'anio' => 2026, 'estado' => 'activo', 'origen' => 'legado']);

        $this->crearEvento('Foro de Pruebas', '5 de marzo de 2026')->assertSessionHas('error');
        $this->assertSame(1, EventoCertificacion::count());

        $this->crearEvento('Foro de Pruebas', '5 de marzo de 2026', ['crear_nuevo' => true])->assertSessionHasNoErrors();
        $this->assertSame(2, EventoCertificacion::count());
        $this->assertSame(1, EventoCertificacion::where('origen', 'credential_flow')->count());
    }

    public function test_la_base_importada_con_un_evento_elegido_queda_asociada_sola(): void
    {
        $p = $this->plantilla();
        $this->crearEvento('Seminario VBG', '16 de octubre de 2026');
        $evento = EventoCertificacion::firstOrFail();

        $r = $this->importar($p, $evento->id, $this->archivo(3));
        $r->assertOk();

        $lote = Lote::findOrFail(basename($r->json('redirect')));
        $this->assertSame($evento->id, $lote->evento_id);
        $this->assertSame('SEMINARIO VBG', $lote->datos_comunes['evento']);   // el nombre del evento elegido manda sobre el texto escrito (el validador de datos comunes lo pone en mayúsculas)
        $this->assertSame(1, EventoCertificacion::count());                   // no se creó otro evento
    }

    public function test_dos_modalidades_del_mismo_evento_cuelgan_de_un_unico_evento(): void
    {
        $p = $this->plantilla();
        $this->crearEvento('Seminario VBG', '16 de octubre de 2026');
        $evento = EventoCertificacion::firstOrFail();

        $this->importar($p, $evento->id, $this->archivo(2, 1), ['nombre' => 'Presencial'])->assertOk();
        $this->importar($p, $evento->id, $this->archivo(2, 100), ['nombre' => 'Virtual'])->assertOk();

        $this->assertSame(1, EventoCertificacion::count());
        $this->assertSame(2, Lote::where('evento_id', $evento->id)->count());
    }

    public function test_volver_a_importar_el_mismo_archivo_en_el_mismo_evento_se_rechaza_sin_crear_nada(): void
    {
        $p = $this->plantilla();
        $this->crearEvento('Seminario VBG', '16 de octubre de 2026');
        $evento = EventoCertificacion::firstOrFail();
        $bytes = A::xlsxBytes([['nombre_completo', 'documento'], ['Persona 1', 'C.C. 10.000.001']]);

        $this->importar($p, $evento->id, A::subir($bytes, 'a.xlsx'))->assertOk();
        $r = $this->importar($p, $evento->id, A::subir($bytes, 'a-de-nuevo.xlsx'));

        $r->assertStatus(422)->assertJsonValidationErrors('archivo');
        $this->assertSame(1, Lote::count());
    }

    public function test_el_mismo_archivo_en_otro_evento_si_se_permite(): void
    {
        $p = $this->plantilla();
        $this->crearEvento('Seminario VBG', '16 de octubre de 2026');
        $this->crearEvento('Otro seminario', '20 de octubre de 2026');
        [$a, $b] = EventoCertificacion::orderBy('id')->get();
        $bytes = A::xlsxBytes([['nombre_completo', 'documento'], ['Persona 1', 'C.C. 10.000.001']]);

        $this->importar($p, $a->id, A::subir($bytes, 'a.xlsx'))->assertOk();
        $this->importar($p, $b->id, A::subir($bytes, 'a.xlsx'))->assertOk();

        $this->assertSame(2, Lote::count());
    }

    public function test_un_evento_historico_o_inexistente_no_recibe_bases_nuevas(): void
    {
        $p = $this->plantilla();
        $historico = EventoCertificacion::create(['nombre' => 'Histórico', 'nombre_normalizado' => 'historico', 'anio' => 2020, 'estado' => 'activo', 'origen' => 'legado']);

        $this->importar($p, $historico->id, $this->archivo())->assertStatus(422)->assertJsonValidationErrors('evento_id');
        $this->importar($p, 99999, $this->archivo())->assertStatus(422)->assertJsonValidationErrors('evento_id');
        $this->assertSame(0, Lote::count());
    }

    public function test_sin_evento_elegido_el_flujo_anterior_sigue_igual(): void
    {
        $p = $this->plantilla();

        $r = $this->importar($p, null, $this->archivo());
        $r->assertOk();
        $this->assertNull(Lote::findOrFail(basename($r->json('redirect')))->evento_id);
    }

    public function test_la_pantalla_de_nueva_base_ofrece_los_eventos_y_preselecciona_el_pedido(): void
    {
        $this->plantilla();
        $this->crearEvento('Seminario VBG', '16 de octubre de 2026');
        $evento = EventoCertificacion::firstOrFail();

        $this->actingAs($this->admin())->get(route('credential-flow.lotes.nuevo', ['evento' => $evento->id]))
            ->assertInertia(fn ($page) => $page->component('CredentialFlow/Lotes/Nuevo')->has('eventos', 1)->where('evento_seleccionado', $evento->id));
    }
}
