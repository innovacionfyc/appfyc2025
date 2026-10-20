<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Generacion\GeneradorCredencialPdf;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * F6.1 · `credential-flow:plantilla-duplicar`: copia SEGURA de una plantilla (PDF base + diseño) para trabajar sobre la copia sin tocar el original.
 * Solo plantillas sintéticas: nunca se usa ni se toca la plantilla real.
 */
class PlantillaDuplicarTest extends EmisionesTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->artisan('migrate', ['--path' => ['database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php'], '--force' => true])->assertExitCode(0);
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Plantilla con el texto fijo «Modalidad virtual» (como la institucional) y los cinco campos de siempre. */
    private function institucional(): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'];
        $els = [];
        foreach ($campos as $i => $c) {
            $els[] = $this->elemento(['field' => $c, 'y' => 40 + $i * 45, 'height' => 32, 'fontSize' => 12]);
        }
        $els[] = $this->elemento(['text' => 'Modalidad virtual', 'y' => 300, 'height' => 30, 'fontSize' => 12]);
        $p = $this->plantillaLista($els);
        $p->update(['nombre' => 'Certificado de asistencia — Virtual (sintética)']);

        return $p->fresh();
    }

    /** @return array{filas:int,archivos:list<string>,movimientos:int} lo que existe en la base y en el disco */
    private function foto(): array
    {
        return [
            'filas' => Plantilla::withTrashed()->count(),
            'archivos' => collect(Storage::disk('local')->allFiles('credential-flow/plantillas'))->sort()->values()->all(),
            'movimientos' => Movimiento::count(),
        ];
    }

    /** @return array<string,mixed> todo lo que define a la plantilla (fila completa + PDF) */
    private function estado(Plantilla $p): array
    {
        $f = Plantilla::withTrashed()->findOrFail($p->id);
        $fila = $f->getRawOriginal();
        ksort($fila);

        return ['fila' => $fila, 'pdf' => hash('sha256', (string) Storage::disk('local')->get($f->rutaPdfEsperada()))];
    }

    private function duplicar(Plantilla $p, string $nombre, bool $ejecutar = true)
    {
        return $this->artisan('credential-flow:plantilla-duplicar', array_filter(['origen' => $p->id, '--nombre' => $nombre, '--ejecutar' => $ejecutar]));
    }

    // ── Simulación ────────────────────────────────────────────────────────────

    public function test_la_simulacion_describe_lo_que_copiaria_y_no_escribe_nada(): void
    {
        $origen = $this->institucional();
        $antes = $this->foto();
        $estadoAntes = $this->estado($origen);

        $this->duplicar($origen, 'Copia institucional virtual', false)
            ->expectsOutputToContain('SIMULACIÓN (no se escribió nada)')
            ->expectsOutputToContain("Original: #{$origen->id}")
            ->expectsOutputToContain('Campos dinámicos: nombre_completo, documento, evento, fecha, intensidad_horaria')
            ->expectsOutputToContain('«Modalidad virtual»')
            ->expectsOutputToContain('Copia: «Copia institucional virtual»')
            ->expectsOutputToContain('requiere tu autorización')
            ->assertExitCode(0);

        $this->assertSame($antes, $this->foto(), 'ni filas, ni archivos, ni movimientos');
        $this->assertSame($estadoAntes, $this->estado($origen));
    }

    public function test_sin_la_opcion_ejecutar_nunca_se_crea_la_copia_aunque_todo_este_bien(): void
    {
        $origen = $this->institucional();

        $this->duplicar($origen, 'Otra copia', false)->assertExitCode(0);
        $this->duplicar($origen, 'Otra copia', false)->assertExitCode(0);

        $this->assertSame(1, Plantilla::count());
    }

    // ── Copia fiel e independiente ────────────────────────────────────────────

    public function test_la_copia_es_fiel_independiente_y_no_es_de_pruebas_internas(): void
    {
        $origen = $this->institucional();
        $estadoOrigen = $this->estado($origen);

        $this->duplicar($origen, 'Copia institucional virtual')->expectsOutputToContain('DUPLICACIÓN REALIZADA')->assertExitCode(0);

        $copia = Plantilla::where('nombre', 'Copia institucional virtual')->firstOrFail();
        $this->assertNotSame($origen->id, $copia->id);
        $this->assertSame(Storage::disk('local')->get($origen->rutaPdfEsperada()), Storage::disk('local')->get($copia->rutaPdfEsperada()), 'el PDF base es idéntico byte a byte');
        $this->assertSame($origen->hash_sha256, $copia->hash_sha256);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($copia->rutaPdfEsperada())), $copia->hash_sha256);
        $this->assertSame(json_encode($origen->diseno), json_encode($copia->diseno), 'el diseño es idéntico, incluido el texto fijo');
        $this->assertSame((int) $origen->schema_version, (int) $copia->schema_version);
        $this->assertSame("credential-flow/plantillas/{$copia->id}/base.pdf", $copia->archivo_pdf);
        $this->assertNotSame($origen->archivo_pdf, $copia->archivo_pdf);
        $this->assertFalse((bool) $copia->es_plantilla_qa);
        $this->assertNull($copia->variante_qa_id);
        $this->assertNull($copia->origen_legado_sha256);
        $this->assertStringContainsString("plantilla #{$origen->id}", (string) $copia->descripcion);

        $this->assertSame($estadoOrigen, $this->estado($origen), 'el original conserva exactamente su fila, su PDF y sus hashes');
        $this->assertSame(1, Movimiento::where('descripcion', 'like', "Se duplicó la plantilla #{$origen->id}%")->count());
    }

    public function test_copia_y_original_son_independientes_en_el_editor_y_al_eliminar(): void
    {
        $origen = $this->institucional();
        $this->duplicar($origen, 'Copia independiente')->assertExitCode(0);
        $copia = Plantilla::where('nombre', 'Copia independiente')->firstOrFail();
        $estadoOrigen = $this->estado($origen);

        // El editor abre la copia, la guarda y el original no cambia.
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $copia))->assertOk();
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf', $copia))->assertOk();
        $nuevo = ['page' => ['width' => 792, 'height' => 612], 'elements' => [$this->elemento(['field' => 'nombre_completo'])]];
        $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $copia), ['diseno' => $nuevo])->assertRedirect();
        $this->assertSame(1, count(Plantilla::findOrFail($copia->id)->diseno['elements']));
        $this->assertSame($estadoOrigen, $this->estado($origen));

        // Eliminar la copia no toca el PDF ni la fila del original.
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $copia))->assertRedirect();
        $this->assertSame($estadoOrigen, $this->estado($origen));
        $this->assertTrue(Storage::disk('local')->exists($origen->rutaPdfEsperada()));
        $this->assertFalse(Storage::disk('local')->exists($copia->rutaPdfEsperada()));
    }

    public function test_el_generador_dibuja_igual_con_la_copia_y_la_copia_acepta_el_campo_modalidad_en_lugar_del_texto_fijo(): void
    {
        $origen = $this->institucional();
        $lote = $this->loteCon(1, $origen);
        $participante = $lote->participantes()->first();
        $lote->update(['datos_comunes' => $lote->datos_comunes + ['modalidad' => 'Virtual-Sincrónico y Remoto']]);
        $datos = DatosDeParticipante::para($participante, $lote->fresh());

        $this->duplicar($origen, 'Copia para modalidad dinámica')->assertExitCode(0);
        $copia = Plantilla::where('nombre', 'Copia para modalidad dinámica')->firstOrFail();

        $textosOrigen = array_column((new InspectorPdf(GeneradorCredencialPdf::generar($origen->fresh(), $datos)))->textos(), 'texto');
        $textosCopia = array_column((new InspectorPdf(GeneradorCredencialPdf::generar($copia, $datos)))->textos(), 'texto');
        $this->assertSame($textosOrigen, $textosCopia, 'la copia dibuja exactamente lo mismo que el original');
        $this->assertContains('Modalidad virtual', $textosCopia);

        // Sobre la COPIA se reemplaza el texto fijo por el campo dinámico (sin dejar ambos); el original no se entera.
        $estadoOrigen = $this->estado($origen);
        $elementos = array_values(array_filter($copia->diseno['elements'], fn ($e) => ($e['text'] ?? '') !== 'Modalidad virtual'));
        $elementos[] = $this->elemento(['field' => 'modalidad', 'y' => 300, 'height' => 30, 'fontSize' => 12]);
        $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $copia), ['diseno' => ['page' => $copia->diseno['page'], 'elements' => $elementos]])->assertRedirect();

        $copia = $copia->fresh();
        $this->assertContains('modalidad', DisenoSchema::camposUsados($copia->diseno));
        $textos = array_column((new InspectorPdf(GeneradorCredencialPdf::generar($copia, $datos)))->textos(), 'texto');
        $this->assertContains('Virtual-Sincrónico y Remoto', $textos);
        $this->assertNotContains('Modalidad virtual', $textos, 'el texto fijo ya no se repite junto al valor dinámico');
        $this->assertSame($estadoOrigen, $this->estado($origen), 'el diseño del original sigue intacto');
    }

    public function test_avisa_si_el_diseno_repite_el_texto_fijo_y_el_campo_dinamico_de_modalidad(): void
    {
        $origen = $this->institucional();
        $diseno = $origen->diseno;
        $diseno['elements'][] = $this->elemento(['field' => 'modalidad', 'y' => 400, 'height' => 30]);
        $origen->update(['diseno' => $diseno, 'schema_version' => DisenoSchema::versionPara($diseno)]);

        $this->duplicar($origen->fresh(), 'Copia con aviso', false)->expectsOutputToContain('se verían repetidos')->assertExitCode(0);
    }

    // ── Lo ya emitido no se toca ──────────────────────────────────────────────

    public function test_las_bases_y_los_certificados_emitidos_con_el_original_no_cambian(): void
    {
        $origen = $this->institucional();
        $lote = $this->loteCon(1, $origen);
        $this->emitirPor($lote, $lote->participantes()->first())->assertSuccessful();
        $emision = Emision::firstOrFail();
        $fotoEmision = $emision->fresh()->getAttributes();
        $archivos = collect(Storage::disk('local')->allFiles())->reject(fn ($f) => str_starts_with($f, 'credential-flow/plantillas/'))->sort()->values()->all();
        $estadoOrigen = $this->estado($origen);

        $this->duplicar($origen, 'Copia sin tocar lo emitido')->assertExitCode(0);

        $this->assertSame($fotoEmision, Emision::firstOrFail()->getAttributes(), 'snapshot, hashes y datos de la emisión quedan idénticos');
        $this->assertSame($archivos, collect(Storage::disk('local')->allFiles())->reject(fn ($f) => str_starts_with($f, 'credential-flow/plantillas/'))->sort()->values()->all(), 'los PDF emitidos no se tocan');
        $this->assertSame($origen->id, $lote->fresh()->plantilla_id, 'la base sigue usando el original');
        $this->assertSame(1, Emision::count());
        $this->assertSame($estadoOrigen, $this->estado($origen));
    }

    public function test_las_plantillas_de_pruebas_internas_no_se_copian_y_no_cambian(): void
    {
        $origen = $this->institucional();
        $qa = $this->plantillaLista();
        $qa->update(['es_plantilla_qa' => true]);
        $variante = $this->plantillaLista();
        $origen->update(['variante_qa_id' => $variante->id]);
        $estadoQa = $this->estado($qa);
        $estadoVariante = $this->estado($variante);
        $qaIds = Plantilla::where('es_plantilla_qa', true)->pluck('id')->all();

        $this->duplicar($qa, 'Copia de la de pruebas', false)->expectsOutputToContain('pruebas internas')->assertExitCode(1);
        $this->duplicar($origen->fresh(), 'Copia de la institucional')->assertExitCode(0);

        $this->assertSame($qaIds, Plantilla::where('es_plantilla_qa', true)->pluck('id')->all(), 'la designación QA no cambia');
        $this->assertSame($estadoQa, $this->estado($qa));
        $this->assertSame($estadoVariante, $this->estado($variante));
        $copia = Plantilla::where('nombre', 'Copia de la institucional')->firstOrFail();
        $this->assertFalse((bool) $copia->es_plantilla_qa);
        $this->assertNull($copia->variante_qa_id, 'la copia no hereda la variante QA del original');
    }

    // ── Duplicados y repeticiones ─────────────────────────────────────────────

    public function test_repetir_la_ejecucion_con_el_mismo_nombre_no_crea_otra_copia(): void
    {
        $origen = $this->institucional();
        $this->duplicar($origen, 'Copia institucional')->assertExitCode(0);
        $despues = $this->foto();

        $this->duplicar($origen, 'Copia institucional')->expectsOutputToContain('Ya existe una plantilla con ese nombre')->assertExitCode(1);

        $this->assertSame($despues, $this->foto(), 'la segunda ejecución no escribe nada');
        $this->assertSame(2, Plantilla::count());
    }

    public function test_el_nombre_se_compara_sin_mayusculas_acentos_ni_espacios_de_mas(): void
    {
        $origen = $this->institucional();
        $antes = $this->foto();

        foreach (['certificado de asistencia — virtual (SINTÉTICA)', '  Certificado   de asistencia — Virtual (sintetica) '] as $nombre) {
            $this->duplicar($origen, $nombre)->expectsOutputToContain('Ya existe una plantilla con ese nombre')->assertExitCode(1);
        }
        $this->assertSame($antes, $this->foto());
    }

    public function test_una_segunda_copia_identica_con_otro_nombre_tambien_se_rechaza(): void
    {
        $origen = $this->institucional();
        $this->duplicar($origen, 'Primera copia')->assertExitCode(0);
        $antes = $this->foto();

        $this->duplicar($origen, 'Segunda copia accidental')->expectsOutputToContain('Ya existe una copia idéntica')->assertExitCode(1);

        $this->assertSame($antes, $this->foto());
        $this->assertSame(2, Plantilla::count());
    }

    public function test_un_nombre_vacio_o_demasiado_largo_se_rechaza_sin_escribir(): void
    {
        $origen = $this->institucional();
        $antes = $this->foto();

        $this->artisan('credential-flow:plantilla-duplicar', ['origen' => $origen->id, '--ejecutar' => true])->expectsOutputToContain('Indica el nombre de la copia')->assertExitCode(1);
        $this->duplicar($origen, str_repeat('N', 201))->expectsOutputToContain('Indica el nombre de la copia')->assertExitCode(1);
        $this->duplicar($origen, "   \t  ")->assertExitCode(1);

        $this->assertSame($antes, $this->foto());
    }

    public function test_si_hay_otra_duplicacion_en_curso_no_se_duplica(): void
    {
        $origen = $this->institucional();
        $cerrojo = Cache::lock('cf-plantilla-duplicar', 30);
        $this->assertTrue($cerrojo->get());
        $antes = $this->foto();

        $this->duplicar($origen, 'Copia concurrente')->expectsOutputToContain('Ya hay una duplicación en curso')->assertExitCode(1);

        $cerrojo->release();
        $this->assertSame($antes, $this->foto());
        $this->duplicar($origen, 'Copia concurrente')->assertExitCode(0);
    }

    // ── El original no se puede copiar si no es fiable ────────────────────────

    public function test_no_se_copia_una_plantilla_inexistente_eliminada_o_sin_diseno(): void
    {
        $origen = $this->institucional();
        $sinDiseno = $this->plantillaLista();
        $sinDiseno->update(['diseno' => null]);
        $eliminada = $this->plantillaLista();
        $eliminada->delete();
        $antes = $this->foto();

        $this->artisan('credential-flow:plantilla-duplicar', ['origen' => 999999, '--nombre' => 'X', '--ejecutar' => true])->expectsOutputToContain('No existe esa plantilla')->assertExitCode(1);
        $this->duplicar($eliminada, 'Copia de eliminada')->expectsOutputToContain('está eliminada')->assertExitCode(1);
        $this->duplicar($sinDiseno, 'Copia sin diseño')->expectsOutputToContain('todavía no tiene un diseño')->assertExitCode(1);

        $this->assertSame($antes, $this->foto());
        $this->assertNotNull($origen->id);
    }

    public function test_no_se_copia_si_el_pdf_base_falta_o_no_coincide_con_su_huella(): void
    {
        $sinPdf = $this->institucional();
        Storage::disk('local')->delete($sinPdf->rutaPdfEsperada());
        $alterada = $this->plantillaLista();
        $alterada->update(['nombre' => 'Plantilla con PDF alterado']);
        Storage::disk('local')->put($alterada->rutaPdfEsperada(), Storage::disk('local')->get($alterada->rutaPdfEsperada())."\n% alterado");
        $antes = $this->foto();

        $this->duplicar($sinPdf, 'Copia sin PDF')->expectsOutputToContain('No se encontró el PDF base')->assertExitCode(1);
        $this->duplicar($alterada, 'Copia de PDF alterado')->expectsOutputToContain('no coincide con su huella')->assertExitCode(1);

        $this->assertSame($antes, $this->foto(), 'ni registros ni carpetas a medias');
    }

    // ── Fallos: nunca quedan copias parciales ─────────────────────────────────

    public function test_si_la_copia_no_queda_identica_se_deshace_por_completo(): void
    {
        $origen = $this->institucional();
        $antes = $this->foto();
        $estadoOrigen = $this->estado($origen);
        // Simula un fallo: justo después de crearse, la copia queda con otro diseño.
        Plantilla::created(function (Plantilla $p) use ($origen) {
            if ($p->id !== $origen->id) {
                Plantilla::query()->whereKey($p->id)->update(['diseno' => json_encode(['page' => ['width' => 1, 'height' => 1], 'elements' => []])]);
            }
        });

        $this->duplicar($origen, 'Copia que sale mal')->expectsOutputToContain('no quedó idéntica')->assertExitCode(1);

        $this->assertSame($antes['filas'], Plantilla::withTrashed()->count(), 'no queda ni la fila ni una fila eliminada');
        $this->assertSame($antes['archivos'], $this->foto()['archivos'], 'no queda ninguna carpeta');
        $this->assertSame($estadoOrigen, $this->estado($origen));
    }

    public function test_si_el_original_cambia_mientras_se_copia_la_copia_se_deshace(): void
    {
        $origen = $this->institucional();
        $antes = $this->foto();
        Plantilla::created(function (Plantilla $p) use ($origen) {
            if ($p->id !== $origen->id) {
                Plantilla::query()->whereKey($origen->id)->update(['descripcion' => 'editada por otra persona a la vez']);
            }
        });

        $this->duplicar($origen, 'Copia durante una edición')->expectsOutputToContain('cambió mientras se copiaba')->assertExitCode(1);

        $this->assertSame($antes['filas'], Plantilla::withTrashed()->count());
        $this->assertSame($antes['archivos'], $this->foto()['archivos']);
    }

    public function test_el_comando_no_modifica_el_codigo_de_emision_ni_agrega_migraciones(): void
    {
        $comando = file_get_contents(app_path('Console/Commands/CredentialFlowPlantillaDuplicar.php'));
        $this->assertStringContainsString('--ejecutar', $comando);
        $this->assertStringContainsString('self::FAILURE', $comando);
        $servicio = file_get_contents(app_path('Support/CredentialFlow/Plantillas/DuplicadorPlantilla.php'));
        $this->assertStringContainsString('crearDesdePdf(', $servicio, 'usa el alta de siempre (registro y archivo juntos)');
        $this->assertDoesNotMatchRegularExpression('/(?<![A-Za-z])Schema::/', $servicio, 'no crea ni cambia tablas');
        $this->assertStringNotContainsString('DB::statement', $servicio);
    }
}
