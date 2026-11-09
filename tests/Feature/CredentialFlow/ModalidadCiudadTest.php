<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\DatosRequeridos;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Emisiones\SnapshotCredencial;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Participantes\ModalidadCiudad;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * Campo dinámico DERIVADO «modalidad_ciudad»: la modalidad de la persona (o, si no tiene, la general de la base) + «, » + la ciudad de la base, en UNA línea.
 * Solo actúa cuando una plantilla lo imprime expresamente: las plantillas, los snapshots y los PDF anteriores no cambian. Datos 100 % ficticios.
 */
class ModalidadCiudadTest extends EmisionesTestCase
{
    private const MARCADOR = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

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
            'database/migrations/2026_10_06_100700_add_plantilla_legado_to_cf_eventos_table.php',
            'database/migrations/2026_10_08_100000_cf_migraciones_corridas_table.php',
            'database/migrations/2026_10_08_100100_add_corrida_id_to_tablas_historicas.php',
            'database/migrations/2026_10_21_100000_cf_contactos_qa_y_plantilla_qa.php',
        ], '--force' => true])->assertExitCode(0);
        config(['credential_flow.qa.contactos_esperados' => 2]);
        for ($i = 1; $i <= 2; $i++) {
            $doc = (string) (71000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA CIUDAD {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa.ciudad{$i}@example.test", 'correo_normalizado' => "qa.ciudad{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Como la #19/#25 de producción: «por su asistencia al», fecha con «realizado los días», y la línea de modalidad indicada (campo + sufijo). */
    private function plantilla(string $campoModalidad = 'modalidad_ciudad', string $sufijo = ''): Plantilla
    {
        $el = fn (array $c) => $this->elemento($c + ['x' => 86, 'width' => 620, 'fontSize' => 14, 'height' => 20]);
        $modalidad = ['field' => $campoModalidad, 'y' => 346];
        if ($sufijo !== '') {
            $modalidad['suffix'] = $sufijo;
        }
        $p = $this->plantillaLista([
            $el(['field' => 'nombre_completo', 'y' => 205, 'fontSize' => 22, 'height' => 28]),
            $el(['field' => 'documento', 'y' => 232, 'fontSize' => 18, 'height' => 24]),
            $el(['field' => null, 'text' => 'por su asistencia al', 'y' => 262]),
            $el(['field' => 'evento', 'y' => 282, 'height' => 40, 'multiline' => true]),
            $el(['field' => 'fecha', 'y' => 326, 'prefix' => 'realizado los días ']),
            $el($modalidad),
            $el(['field' => 'intensidad_horaria', 'y' => 368, 'prefix' => 'con una intensidad académica de: ', 'suffix' => '.']),
        ]);
        $p->update(['schema_version' => DisenoSchema::versionPara($p->diseno)]);
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true])->assertExitCode(0);

        return $p->fresh();
    }

    /** @param array<string,int> $modalidades  modalidad propia => cuántas personas ('' = sin modalidad propia) */
    private function base(Plantilla $p, array $comunes, array $modalidades, bool $conQa = true): Lote
    {
        $lote = $this->loteCon(0, $p);
        $lote->update(['datos_comunes' => ['evento' => 'SEMINARIO TALLER DE PRUEBA', 'fecha' => '5 y 6 de noviembre de 2026', 'intensidad_horaria' => '18 horas'] + $comunes]);
        $i = 0;
        foreach ($modalidades as $mod => $n) {
            for ($k = 0; $k < $n; $k++) {
                $i++;
                $this->participante($lote, "Persona Ciudad {$lote->id} {$i}", 'C.C. '.(50_000_000 + $lote->id * 100 + $i))->update(['modalidad' => $mod === '' ? null : $mod]);
            }
        }
        if ($conQa) {
            app(ServicioQa::class)->incorporar($lote->fresh());
        }

        return $lote->fresh();
    }

    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf(Storage::disk('local')->get($e->pdf_archivo)))->textos(), 'texto');
    }

    private function emisiones(Lote $lote, bool $qa): Collection
    {
        return Emision::query()->where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)
            ->whereIn('participante_id', $lote->participantes()->where('es_control_qa', $qa)->select('id'))->orderBy('id')->get();
    }

    // ── Composición ───────────────────────────────────────────────────────────

    public static function casos(): array
    {
        return [
            'presencial + Bogotá' => ['Presencial', 'Bogotá', 'Presencial, Bogotá'],
            'virtual + Bogotá' => ['Virtual', 'Bogotá', 'Virtual, Bogotá'],
            'variante no estándar + Cali' => ['Virtual sincrónico', 'Cali', 'Virtual sincrónico, Cali'],
            'presencial + Medellín' => ['Presencial', 'Medellín', 'Presencial, Medellín'],
            'literal, sin normalizar la modalidad' => ['Virtual-Sincrónico y Remoto', 'BOGOTÁ D.C.', 'Virtual-Sincrónico y Remoto, BOGOTÁ D.C.'],
            'ciudad vacía' => ['Presencial', '', 'Presencial'],
            'ciudad nula' => ['Virtual sincrónico', null, 'Virtual sincrónico'],
            'ciudad solo espacios' => ['Presencial', '   ', 'Presencial'],
            'modalidad vacía: sin valor (nunca «, Bogotá» ni la ciudad sola)' => ['', 'Bogotá', null],
            'modalidad nula' => [null, 'Bogotá', null],
            'modalidad solo espacios y comas' => [' , ', 'Bogotá', null],
            'todo vacío' => [null, null, null],
            'la modalidad ya incluye la ciudad' => ['Presencial, Bogotá', 'Bogotá', 'Presencial, Bogotá'],
            'incluida sin distinguir mayúsculas ni tildes' => ['Presencial en BOGOTA', 'Bogotá', 'Presencial en BOGOTA'],
            'incluida con puntuación distinta' => ['Virtual - bogotá d.c.', 'Bogotá D.C.', 'Virtual - bogotá d.c.'],
            'una palabra que solo empieza como la ciudad no cuenta' => ['Presencial Bogotano', 'Bogotá', 'Presencial Bogotano, Bogotá'],
            'espacios sobrantes' => ['  Presencial  ', '  Bogotá  ', 'Presencial, Bogotá'],
            'espacios internos repetidos' => ['Virtual   sincrónico', 'Santa   Marta', 'Virtual sincrónico, Santa Marta'],
            'coma final en la modalidad' => ['Presencial,', 'Bogotá', 'Presencial, Bogotá'],
            'separador inicial en la ciudad' => ['Presencial', ', Bogotá', 'Presencial, Bogotá'],
            'la ciudad conserva su punto final' => ['Presencial', 'Bogotá D.C.', 'Presencial, Bogotá D.C.'],

            // Sufijos administrativos reconocidos explícitamente (D.C., D.T. y C., D.T.C.H.): la modalidad que ya nombra la ciudad no la repite.
            'sufijo D.C.: la modalidad ya dice Bogotá' => ['Presencial, Bogotá', 'Bogotá D.C.', 'Presencial, Bogotá'],
            'sufijo D.C. escrito «dc» en la modalidad' => ['Virtual - bogota dc', 'Bogotá D.C.', 'Virtual - bogota dc'],
            'sufijo D.C.: sin la ciudad en la modalidad se agrega completa' => ['Presencial', 'Bogotá D.C.', 'Presencial, Bogotá D.C.'],
            'sufijo D.C. sin puntos en la ciudad' => ['Presencial, Bogotá', 'Bogotá DC', 'Presencial, Bogotá'],
            'sufijo D.T. y C.' => ['Presencial, Cartagena', 'Cartagena D.T. y C.', 'Presencial, Cartagena'],
            'sufijo D.T. y C. con nombre compuesto' => ['Presencial en Cartagena de Indias', 'Cartagena de Indias D.T. y C.', 'Presencial en Cartagena de Indias'],
            'sufijo D.T.C.H.' => ['Presencial, Santa Marta', 'Santa Marta D.T.C.H.', 'Presencial, Santa Marta'],
            'la modalidad conserva su texto original (mayúsculas y puntos)' => ['PRESENCIAL — bogotá', 'Bogotá D.C.', 'PRESENCIAL — bogotá'],
            'ciudad sin sufijo: se agrega' => ['Virtual', 'Cali', 'Virtual, Cali'],

            // Negativos obligatorios: no hay equivalencias geográficas arbitrarias.
            'Bogotano no es Bogotá (con sufijo)' => ['Presencial Bogotano', 'Bogotá D.C.', 'Presencial Bogotano, Bogotá D.C.'],
            'Santa Marta no es Santa Rosa de Cabal' => ['Presencial, Santa Marta', 'Santa Rosa de Cabal', 'Presencial, Santa Marta, Santa Rosa de Cabal'],
            'San José no es San José del Guaviare' => ['Presencial, San José', 'San José del Guaviare', 'Presencial, San José, San José del Guaviare'],
            'Cali no es automáticamente Santiago de Cali' => ['Presencial, Cali', 'Santiago de Cali', 'Presencial, Cali, Santiago de Cali'],
            'Medellín no es Bogotá D.C.' => ['Presencial, Medellín', 'Bogotá D.C.', 'Presencial, Medellín, Bogotá D.C.'],
            'conector «y» respetado: San Andrés no es San Andrés y Providencia' => ['Presencial, San Andrés', 'San Andrés y Providencia', 'Presencial, San Andrés, San Andrés y Providencia'],
            'conector «y» respetado: el nombre completo sí se reconoce' => ['Presencial, San Andrés y Providencia', 'San Andrés y Providencia', 'Presencial, San Andrés y Providencia'],
            'conector «de»: Cartagena no se confunde con «Cartagena de Indias» sin sufijo' => ['Presencial, Cartagena', 'Cartagena de Indias', 'Presencial, Cartagena, Cartagena de Indias'],
            'solo la sigla en la modalidad no equivale a la ciudad' => ['Presencial D.C.', 'Bogotá D.C.', 'Presencial D.C., Bogotá D.C.'],
            'una sigla no reconocida no se quita' => ['Presencial, Neiva', 'Neiva H.', 'Presencial, Neiva, Neiva H.'],
            'la sigla sola como ciudad no se trata como sufijo' => ['Presencial', 'D.C.', 'Presencial, D.C.'],
        ];
    }

    public function test_con_sufijo_administrativo_la_modalidad_individual_se_conserva_y_la_ciudad_no_se_duplica(): void
    {
        $lote = $this->base($this->plantilla(), ['modalidad' => 'Híbrido', 'ciudad' => 'Bogotá D.C.'], ['Presencial, Bogotá' => 1, 'Virtual' => 1], false);
        [$conCiudad, $sinCiudad] = $lote->participantes()->orderBy('id')->get()->all();

        $this->assertSame('Presencial, Bogotá', DatosDeParticipante::para($conCiudad, $lote)->todos()['modalidad_ciudad']);
        $this->assertSame('Virtual, Bogotá D.C.', DatosDeParticipante::para($sinCiudad, $lote)->todos()['modalidad_ciudad']);
        $this->assertSame(['Presencial, Bogotá', 'Virtual'], [$conCiudad->fresh()->modalidad, $sinCiudad->fresh()->modalidad], 'las modalidades del Excel no cambian');
        $this->assertSame('Bogotá D.C.', $lote->fresh()->datos_comunes['ciudad']);
    }

    #[DataProvider('casos')]
    public function test_compone_modalidad_y_ciudad_en_una_linea(?string $modalidad, ?string $ciudad, ?string $esperado): void
    {
        $this->assertSame($esperado, ModalidadCiudad::componer($modalidad, $ciudad));
    }

    // ── Catálogo y editor ─────────────────────────────────────────────────────

    public function test_el_catalogo_agrega_el_campo_derivado_al_final_sin_tocar_los_demas(): void
    {
        $this->assertSame('modalidad_ciudad', array_slice(CamposDinamicos::claves(), -1)[0]);
        $c = CamposDinamicos::todos()['modalidad_ciudad'];
        $this->assertSame(['Modalidad y ciudad', 'string', false, CamposDinamicos::FORMATO_LITERAL], [$c['etiqueta'], $c['tipo'], $c['multilinea'], $c['formato']]);
        $this->assertSame(CamposDinamicos::todos()['modalidad']['maxLongitud'] + 2 + CamposDinamicos::todos()['ciudad']['maxLongitud'], $c['maxLongitud']);
        $this->assertSame(['modalidad_ciudad'], CamposDinamicos::DERIVADOS);
        $this->assertNotContains('modalidad_ciudad', CamposDinamicos::COMUNES_OPCIONALES, 'no es un dato que se escriba en la base');
        $this->assertSame(['modalidad', 'texto_certificacion', 'texto_complementario', 'ciudad'], CamposDinamicos::COMUNES_OPCIONALES);
    }

    public function test_el_editor_lo_ofrece_y_lo_guarda_como_cualquier_campo(): void
    {
        $plantilla = $this->plantillaLista();
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $plantilla))->assertInertia(fn ($page) => $page
            ->has('schema.campos', 10)
            ->where('schema.campos.9.key', 'modalidad_ciudad')
            ->where('schema.campos.9.etiqueta', 'Modalidad y ciudad')
            ->where('schema.campos.9.preview', 'Virtual-Sincrónico y Remoto, BOGOTÁ D.C.'));

        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => [$this->elemento(['field' => 'modalidad_ciudad', 'prefix' => 'Modalidad: ', 'suffix' => '.'])]];
        $this->actingAs($this->admin())->putJson(route('credential-flow.plantillas.diseno.update', $plantilla), ['diseno' => $diseno])->assertRedirect();
        $this->assertSame(['modalidad_ciudad'], DisenoSchema::camposUsados(Plantilla::findOrFail($plantilla->id)->diseno));
    }

    public function test_el_pdf_de_prueba_del_editor_lo_dibuja_con_el_ejemplo(): void
    {
        $p = $this->plantilla();
        $textos = array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.plantillas.pdf-prueba', $p))->assertOk()->getContent()))->textos(), 'texto');
        $this->assertContains('Virtual-Sincrónico y Remoto, BOGOTÁ D.C.', $textos);
    }

    // ── Datos de la persona ───────────────────────────────────────────────────

    public function test_la_modalidad_individual_manda_sobre_la_general_y_los_campos_actuales_no_cambian(): void
    {
        $lote = $this->base($this->plantilla(), ['modalidad' => 'Híbrido', 'ciudad' => 'Cali'], ['Virtual sincrónico' => 1, '' => 1], false);
        [$propia, $sin] = $lote->participantes()->orderBy('id')->get()->all();

        $d = DatosDeParticipante::para($propia, $lote)->todos();
        $this->assertSame(['Virtual sincrónico', 'Cali', 'Virtual sincrónico, Cali'], [$d['modalidad'], $d['ciudad'], $d['modalidad_ciudad']]);
        $this->assertSame('Híbrido, Cali', DatosDeParticipante::para($sin, $lote)->todos()['modalidad_ciudad'], 'sin modalidad propia usa la general');
        $this->assertSame('Virtual sincrónico', $propia->fresh()->modalidad, 'la modalidad importada no se toca');
    }

    public function test_sin_ciudad_imprime_solo_la_modalidad_y_sin_modalidad_no_hay_valor(): void
    {
        $lote = $this->base($this->plantilla(), [], ['Presencial' => 1, '' => 1], false);
        [$con, $sin] = $lote->participantes()->orderBy('id')->get()->all();
        $this->assertSame('Presencial', DatosDeParticipante::para($con, $lote)->todos()['modalidad_ciudad']);
        $this->assertArrayNotHasKey('modalidad_ciudad', DatosDeParticipante::para($sin, $lote)->todos());
    }

    // ── Compatibilidad: solo cuenta si la plantilla lo imprime ────────────────

    public function test_el_snapshot_de_una_plantilla_que_no_lo_imprime_es_exactamente_el_de_antes(): void
    {
        $anterior = $this->plantilla('modalidad', ', Bogotá');   // como la #25 de producción: modalidad + sufijo fijo
        $lote = $this->base($anterior, ['ciudad' => 'Bogotá'], ['Presencial' => 1], false);
        [, $hash] = SnapshotCredencial::cargarPlantilla($anterior);
        $s = SnapshotCredencial::capturar($lote->participantes()->first(), $lote, $anterior, $hash);

        $this->assertSame(['documento', 'evento', 'fecha', 'intensidad_horaria', 'modalidad', 'nombre_completo', 'lote_nombre', 'plantilla_nombre'], array_keys($s->datos),
            'ni «modalidad_ciudad» ni «ciudad» entran al snapshot de un diseño que no los imprime');
        $this->assertSame([], DatosRequeridos::faltantes($anterior->diseno, $lote->datos_comunes, true));
    }

    public function test_el_snapshot_de_una_plantilla_que_lo_imprime_lo_incluye_y_no_agrega_los_que_no_imprime(): void
    {
        $nueva = $this->plantilla();
        $lote = $this->base($nueva, ['modalidad' => 'Híbrido', 'ciudad' => 'Bogotá'], ['Virtual' => 1], false);
        [, $hash] = SnapshotCredencial::cargarPlantilla($nueva);
        $s = SnapshotCredencial::capturar($lote->participantes()->first(), $lote, $nueva, $hash);

        $this->assertSame('Virtual, Bogotá', $s->datos['modalidad_ciudad']);
        $this->assertArrayNotHasKey('modalidad', $s->datos, 'el diseño no imprime «modalidad» sola');
        $this->assertArrayNotHasKey('ciudad', $s->datos);
    }

    public function test_las_emisiones_anteriores_no_quedan_desactualizadas_ni_se_reemiten_solas(): void
    {
        $anterior = $this->plantilla('modalidad', ', Bogotá');
        $lote = $this->base($anterior, ['ciudad' => 'Bogotá'], ['Presencial' => 2, 'Virtual' => 1]);
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $antes = DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();

        foreach ([ReemisorBase::OFICIALES, ReemisorBase::QA] as $grupo) {
            $plan = app(ReemisorBase::class)->planear($lote->fresh(), $grupo);
            $this->assertSame([0, 0], [$plan['desactualizadas'], $plan['a_reemitir']], "grupo {$grupo}: nada que reemitir");
        }
        $this->assertSame($antes, DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all());
        $this->assertContains('Presencial, Bogotá', $this->textos($this->emisiones($lote, false)->first()));
    }

    // ── Emisión, QA, vista previa ─────────────────────────────────────────────

    public function test_emision_oficial_y_qa_imprimen_la_modalidad_de_cada_persona_con_la_ciudad_y_los_pdf_son_integros(): void
    {
        $lote = $this->base($this->plantilla(), ['modalidad' => 'Híbrido', 'ciudad' => 'Bogotá'], ['Presencial' => 2, 'Virtual' => 1, 'Virtual sincrónico' => 1]);
        $r = app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $this->assertSame([4, 6], [$r['oficiales']['total'] ?? null, $r['qa']['total'] ?? null], json_encode($r));

        $porLinea = [];
        foreach ($this->emisiones($lote, false) as $e) {
            $t = $this->textos($e);
            $this->assertSame($e->datos_snapshot['modalidad_ciudad'], collect($t)->first(fn ($x) => str_contains($x, ', Bogotá')));
            $this->assertNotContains(self::MARCADOR, $t);
            $this->assertContains('por su asistencia al', $t);
            $this->assertContains('realizado los días 5 y 6 de noviembre de 2026', $t);
            $this->assertContains('con una intensidad académica de: 18 horas.', $t);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($e->pdf_archivo)), $e->pdf_hash);
            $porLinea[$e->datos_snapshot['modalidad_ciudad']] = ($porLinea[$e->datos_snapshot['modalidad_ciudad']] ?? 0) + 1;
        }
        ksort($porLinea);
        $this->assertSame(['Presencial, Bogotá' => 2, 'Virtual sincrónico, Bogotá' => 1, 'Virtual, Bogotá' => 1], $porLinea, 'ninguna toma «Híbrido» ni se normaliza');

        $qa = $this->emisiones($lote, true);
        $this->assertCount(6, $qa, '2 contactos QA × 3 modalidades presentes');
        foreach ($qa as $e) {
            $t = $this->textos($e);
            $this->assertContains(self::MARCADOR, $t);
            $this->assertContains($e->datos_snapshot['modalidad_ciudad'], $t);
            $this->assertStringEndsWith(', Bogotá', $e->datos_snapshot['modalidad_ciudad']);
        }
    }

    public function test_sin_ciudad_la_composicion_no_lleva_coma_pero_la_emision_se_bloquea(): void
    {
        // La composición no cambia (solo la modalidad, sin coma sobrante), pero desde la regla de ciudad obligatoria no se emite sin ciudad.
        $lote = $this->base($this->plantilla(), [], ['Presencial' => 1], false);
        $this->assertSame('Presencial', DatosDeParticipante::para($lote->participantes()->firstOrFail(), $lote)->todos()['modalidad_ciudad']);
        try {
            app(EmisorLote::class)->emitirPendientes($lote, null);
            $this->fail('debía bloquearse');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, $e->codigo);
            $this->assertStringContainsString(DatosRequeridos::MENSAJE_CIUDAD, $e->getMessage());
        }
        $this->assertSame(0, Emision::count());
    }

    public function test_sin_modalidad_la_emision_se_bloquea_antes_de_generar(): void
    {
        $lote = $this->base($this->plantilla(), ['ciudad' => 'Bogotá'], ['Presencial' => 1, '' => 1], false);
        try {
            app(EmisorLote::class)->emitirPendientes($lote, null);
            $this->fail('debía bloquearse');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, $e->codigo);
            $this->assertStringContainsString('Modalidad', $e->getMessage());
        }
        $this->assertSame(0, Emision::count(), 'no se emitió ningún certificado');
    }

    public function test_la_vista_previa_del_participante_usa_la_composicion(): void
    {
        $lote = $this->base($this->plantilla(), ['modalidad' => 'Presencial', 'ciudad' => 'Medellín'], ['Virtual' => 1], false);
        $p = $lote->participantes()->first();
        $r = $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $p]))->assertOk();
        $this->assertContains('Virtual, Medellín', array_column((new InspectorPdf($r->getContent()))->textos(), 'texto'));
    }

    // ── Reemisión ─────────────────────────────────────────────────────────────

    public function test_cambiar_a_una_plantilla_con_el_campo_y_reemitir_produce_el_mismo_texto_y_conserva_los_pdf_anteriores(): void
    {
        $anterior = $this->plantilla('modalidad', ', Bogotá');
        $lote = $this->base($anterior, ['ciudad' => 'Bogotá'], ['Presencial' => 1, 'Virtual' => 1]);
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $v1 = $this->emisiones($lote, false)->mapWithKeys(fn ($e) => [$e->id => [$e->pdf_hash, Storage::disk('local')->get($e->pdf_archivo), $this->textos($e)]])->all();

        $nueva = $this->plantilla();
        Artisan::call('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id]);
        preg_match('/Huella del plan: ([0-9a-f]{64})/', Artisan::output(), $m);
        $this->assertSame(0, Artisan::call('credential-flow:base-cambiar-plantilla', ['base' => $lote->id, 'plantilla' => $nueva->id, '--usuario' => $this->admin()->id,
            '--motivo' => 'Prueba: pasar a modalidad_ciudad', '--plan' => $m[1], '--ejecutar' => true]), Artisan::output());
        $p = $lote->participantes()->where('es_control_qa', false)->orderBy('id')->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $p]))->assertOk();
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHasNoErrors();

        Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id]);
        preg_match('/Huella del plan: ([0-9a-f]{64})/', Artisan::output(), $m);
        $this->assertSame(0, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => 'Prueba: pasar a modalidad_ciudad',
            '--plan' => $m[1], '--ejecutar' => true]), Artisan::output());

        foreach ($v1 as $id => [$hash, $bytes, $textosV1]) {
            $e = Emision::findOrFail($id);
            $this->assertSame(Emision::REVOCADA, $e->estado);
            $this->assertSame($hash, $e->pdf_hash);
            $this->assertSame($bytes, Storage::disk('local')->get($e->pdf_archivo), 'el PDF anterior se conserva intacto');
            $v2 = Emision::query()->where('participante_id', $e->participante_id)->where('estado', Emision::EMITIDA)->sole();
            $this->assertSame($nueva->id, $v2->plantilla_id);
            $linea = collect($textosV1)->first(fn ($x) => str_ends_with($x, ', Bogotá'));
            $this->assertSame($linea, $v2->datos_snapshot['modalidad_ciudad'], 'la misma línea impresa, ahora compuesta por el campo dinámico');
            $this->assertContains($linea, $this->textos($v2));
        }
    }
}
