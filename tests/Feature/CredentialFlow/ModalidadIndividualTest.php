<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\FlujoDeBase;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Qa\AdministradorContactosQa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;
use ZipArchive;

/**
 * Flujo operativo final: UN Excel → UNA base → modalidad POR PERSONA → certificados → OTP o ZIP.
 *
 *  - La columna «Modalidad» de la tabla se conserva literal por persona; la general de la base es solo el respaldo. Nunca se inventa.
 *  - Los 8 contactos QA prueban CADA modalidad presente (los 8 en cada una), con la variante PRUEBA INTERNA del diseño, y nunca entran al ZIP.
 *  - Nadie se certifica dos veces en el mismo evento desde otra base. La reemisión y la revocación siguen igual.
 * Datos 100 % sintéticos.
 */
class ModalidadIndividualTest extends EmisionesTestCase
{
    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private const EVENTO = 'Seminario sintético de gestión pública';

    private Plantilla $plantilla;

    private EventoCertificacion $evento;

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
            'database/migrations/2026_10_24_100000_cf_importaciones_table.php',
        ], '--force' => true])->assertExitCode(0);

        config(['credential_flow.importacion_inteligente.activa' => true, 'credential_flow.qa.contactos_esperados' => 8]);
        $this->plantilla = $this->plantillaInstitucional();
        $this->evento = EventoCertificacion::create([
            'nombre' => self::EVENTO, 'nombre_normalizado' => NombreConservador::normalizar(self::EVENTO), 'anio' => 2026,
            'fecha_texto' => '20 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]);
        $this->sembrarQa();
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Diseño como el institucional #13: los cinco campos + `modalidad` (dinámico, en el lugar del antiguo texto fijo). */
    private function elementosInstitucionales(): array
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad', 'intensidad_horaria'];

        return array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 40 + $i * 50, 'height' => 30, 'fontSize' => 12]), $campos, array_keys($campos));
    }

    private function plantillaInstitucional(): Plantilla
    {
        return $this->plantillaLista($this->elementosInstitucionales());
    }

    /** Variante de PRUEBA INTERNA del mismo diseño, designada con el mecanismo QA de siempre (pantalla de plantillas). */
    private function varianteQa(): Plantilla
    {
        $qa = $this->plantillaLista([...$this->elementosInstitucionales(), $this->elemento(['type' => 'text', 'text' => self::ADVERTENCIA, 'y' => 380, 'fontSize' => 14])]);
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.qa', $qa), ['es_plantilla_qa' => true])->assertRedirect();
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.variante-qa', $this->plantilla), ['variante_qa_id' => $qa->id])->assertRedirect();
        $this->assertTrue((bool) $qa->fresh()->es_plantilla_qa);
        $this->assertSame($qa->id, $this->plantilla->fresh()->variante_qa_id);

        return $qa->fresh();
    }

    private function sembrarQa(): void
    {
        for ($i = 1; $i <= 8; $i++) {
            $doc = 'C.C. 8.000.00'.$i;
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA {$i} PRUEBA", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    /**
     * Excel sintético: bloque del evento SIN modalidad general y una tabla con la columna «Modalidad» por persona.
     *
     * @param  list<?string>  $modalidades  una por persona (null = celda vacía)
     */
    private function excel(array $modalidades): string
    {
        $c = ['B2' => 'Nombre del Evento', 'D2' => self::EVENTO, 'B3' => 'Fecha del Evento', 'D3' => '20 de octubre de 2026', 'B4' => 'Intensidad de horas', 'D4' => '8 Horas'];
        foreach (['B' => 'Item', 'C' => 'Tipo de documento', 'D' => 'Número de identificación', 'E' => 'Nombres y Apellidos', 'F' => 'Correo Electrónico', 'G' => 'Modalidad'] as $col => $t) {
            $c[$col.'6'] = $t;
        }
        foreach ($modalidades as $i => $m) {
            $f = 7 + $i;
            $c['B'.$f] = $i + 1;
            $c['C'.$f] = 'C.C.';
            $c['D'.$f] = (string) (5000000 + $i + 1);
            $c['E'.$f] = sprintf('PERSONA %02d MODALIDAD', $i + 1);
            $c['F'.$f] = sprintf('persona%02d@example.test', $i + 1);
            if ($m !== null) {
                $c['G'.$f] = $m;
            }
        }

        return XlsxSintetico::libro([['nombre' => 'Solicitud', 'celdas' => $c]]);
    }

    /** @return list<string> 8 presenciales y 2 virtuales, mezclados como llegan en la vida real */
    private function ochoYDos(): array
    {
        return ['Presencial', 'Presencial', 'Virtual', 'Presencial', 'Presencial', 'Presencial', 'Virtual', 'Presencial', 'Presencial', 'Presencial'];
    }

    private function analizar(string $bytes): int
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('modalidades.xlsx', $bytes)], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
    }

    private function corregir(int $id, array $cambios): TestResponse
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cambios);
    }

    /** Plantilla, evento, datos comunes confirmados y las 10 personas elegibles. */
    private function decidir(int $id, int $personas = 10): void
    {
        $filas = [];
        for ($f = 7; $f < 7 + $personas; $f++) {
            $filas[(string) $f] = ['elegibilidad' => 'elegible'];
        }
        $this->corregir($id, [
            'plantilla_id' => $this->plantilla->id,
            'evento' => ['decision' => 'asociar', 'evento_id' => $this->evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria'], 'valores_desactualizados' => true, 'valores_ambiguos' => true],
            'participantes' => $filas,
        ])->assertOk();
    }

    private function validar(int $id): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk();
    }

    private function confirmar(int $id): Lote
    {
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true])->assertCreated();

        return Lote::findOrFail($r->json('lote_id'));
    }

    private function textos(string $pdf): array
    {
        return array_column((new InspectorPdf($pdf))->textos(), 'texto');
    }

    private function pdfDe(Emision $e): string
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent();
    }

    /** Base directa (sin Excel) en el evento indicado, con las personas y modalidades dadas. @param list<array{0:string,1:string,2:?string}> $personas */
    private function base(array $personas, ?int $eventoId, array $comunes = []): Lote
    {
        $lote = Lote::create(['plantilla_id' => $this->plantilla->id, 'nombre' => 'Base '.(++$this->n), 'evento_id' => $eventoId,
            'datos_comunes' => array_replace(['evento' => self::EVENTO, 'fecha' => '20 DE OCTUBRE DE 2026', 'intensidad_horaria' => '8 HORAS'], $comunes)]);
        foreach ($personas as [$nombre, $doc, $modalidad]) {
            $this->participante($lote, $nombre, $doc)->update(['modalidad' => $modalidad]);
        }

        return $lote->fresh();
    }

    // ── 1. Un Excel con 8 presenciales y 2 virtuales ──────────────────────────

    public function test_un_excel_con_8_presenciales_y_2_virtuales_da_una_base_y_diez_pdf_con_su_modalidad(): void
    {
        $this->varianteQa();   // sus pruebas QA salen SOLO con la variante de su plantilla
        $id = $this->analizar($this->excel($this->ochoYDos()));

        // Revisión: cada persona con SU modalidad, tal cual la trae el Excel.
        $personas = collect($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador.participantes'));
        $this->assertSame($this->ochoYDos(), $personas->pluck('modalidad')->all());

        $this->decidir($id);
        $v = $this->validar($id);
        $this->assertTrue($v->json('listo'), json_encode($v->json('bloqueos'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(['Presencial' => 8, 'Virtual' => 2], $v->json('resumen.por_modalidad'));

        // Vista previa: un ejemplo de cada modalidad, con su modalidad impresa.
        $vista = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.vista-previa', $id))->assertOk();
        $ejemplos = collect($vista->json('vista_previa.una_por_modalidad'));
        $this->assertSame(['Presencial', 'Virtual'], $ejemplos->pluck('modalidad')->all());
        $pdfVirtual = $this->actingAs($this->admin())->get(route('credential-flow.importaciones.vista-previa.pdf', $id).'?fila='.$ejemplos[1]['fila'])->assertOk()->getContent();
        $this->assertContains('Virtual', $this->textos($pdfVirtual));
        $this->assertSame(0, Lote::count(), 'revisar y previsualizar no crea nada');

        // Una sola base; cada persona conserva su modalidad; la base NO inventa una general.
        $lote = $this->confirmar($id);
        $this->assertSame(1, Lote::count());
        $this->assertSame($this->evento->id, $lote->evento_id);
        $this->assertArrayNotHasKey('modalidad', $lote->datos_comunes);
        $reales = $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->get();
        $this->assertCount(10, $reales);
        $this->assertSame($this->ochoYDos(), $reales->pluck('modalidad')->all());
        $this->assertSame(0, Emision::count(), 'crear la base no emite');

        // Emisión oficial: 10 PDF, cada uno con la modalidad de su persona.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 10);
        foreach ($reales as $p) {
            $e = Emision::where('participante_id', $p->id)->firstOrFail();
            $this->assertSame($p->modalidad, $e->datos_snapshot['modalidad']);
            $textos = $this->textos($this->pdfDe($e));
            $this->assertContains($p->modalidad, $textos);
            $this->assertNotContains($p->modalidad === 'Virtual' ? 'Presencial' : 'Virtual', $textos);
        }
    }

    // ── 2. QA: los ocho prueban cada modalidad, aparte de los oficiales ───────

    public function test_los_ocho_qa_prueban_cada_modalidad_con_la_variante_de_prueba_y_no_entran_al_zip(): void
    {
        $qaPlantilla = $this->varianteQa();
        $id = $this->analizar($this->excel($this->ochoYDos()));
        $this->decidir($id);
        $lote = $this->confirmar($id);

        // 8 contactos × 2 modalidades: los ocho en cada una (nunca repartidos 4 y 4).
        $qa = $lote->participantes()->where('es_control_qa', true)->get();
        $this->assertCount(16, $qa);
        $this->assertSame(['Presencial' => 8, 'Virtual' => 8], $qa->countBy('modalidad')->sortKeys()->all());
        $this->assertSame(8, $qa->pluck('documento_clave')->unique()->count(), 'los mismos ocho contactos oficiales');

        // Repetir la incorporación no duplica nada, y la pantalla de contactos QA muestra la base una sola vez por contacto.
        $this->assertSame(['creados' => 0, 'existentes' => 16, 'omitidos' => 0, 'total' => 16], app(ServicioQa::class)->incorporar($lote->fresh()));
        foreach (app(AdministradorContactosQa::class)->panorama() as $contacto) {
            $this->assertSame([$lote->id], array_column($contacto['bases'], 'lote_id'));
            $this->assertTrue($contacto['bases'][0]['coincide']);
        }

        // Emisión QA: solo los 16, con la variante PRUEBA INTERNA y la modalidad de cada uno.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated()->assertJsonPath('resultado.total', 16);
        $this->assertSame(16, Emision::where('plantilla_id', $qaPlantilla->id)->count());
        foreach (['Presencial', 'Virtual'] as $m) {
            $e = Emision::whereIn('participante_id', $qa->where('modalidad', $m)->pluck('id'))->firstOrFail();
            $textos = $this->textos($this->pdfDe($e));
            $this->assertContains(self::ADVERTENCIA, $textos);
            $this->assertContains($m, $textos);
        }

        // Oficiales: los 10 reales con la plantilla institucional, sin advertencia.
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 10);
        $oficial = Emision::where('plantilla_id', $this->plantilla->id)->firstOrFail();
        $this->assertNotContains(self::ADVERTENCIA, $this->textos($this->pdfDe($oficial)));

        // ZIP administrativo: exactamente los 10 oficiales, ningún QA.
        $z = new ZipArchive;
        $this->assertTrue($z->open($this->actingAs($this->admin())->get(route('credential-flow.lotes.zip', $lote))->assertOk()->baseResponse->getFile()->getPathname()));
        $this->assertSame(10, $z->numFiles);
        $z->close();
    }

    public function test_sin_modalidades_propias_los_qa_entran_una_sola_vez_como_siempre(): void
    {
        $lote = $this->base([['Ana Uno', 'C.C. 1.111', null], ['Beto Dos', 'C.C. 2.222', null]], $this->evento->id, ['modalidad' => 'Virtual']);
        $r = app(ServicioQa::class)->incorporar($lote);
        $this->assertSame(['creados' => 8, 'existentes' => 0, 'omitidos' => 0, 'total' => 8], $r);
        $this->assertSame(8, $lote->participantes()->where('es_control_qa', true)->whereNull('modalidad')->count());
    }

    // ── 3. Datos que faltan: nunca se inventan ────────────────────────────────

    public function test_si_una_persona_no_trae_modalidad_no_se_crea_la_base_hasta_completarla(): void
    {
        $modalidades = $this->ochoYDos();
        $modalidades[4] = null;   // fila 11 del Excel, sin modalidad
        $id = $this->analizar($this->excel($modalidades));
        $this->decidir($id);

        $bloqueo = collect($this->validar($id)->json('bloqueos'))->firstWhere('codigo', 'modalidad_por_revisar');
        $this->assertNotNull($bloqueo, 'la persona sin modalidad bloquea crear la base');
        $this->assertSame([11], $bloqueo['filas']);
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true])->assertStatus(422);
        $this->assertSame(0, Lote::count());

        // El administrador la escribe en esa fila (también podría poner una modalidad general como respaldo).
        $this->corregir($id, ['participantes' => ['11' => ['modalidad' => 'Presencial']]])->assertOk();
        $this->assertTrue($this->validar($id)->json('listo'));
        $lote = $this->confirmar($id);
        $this->assertSame('Presencial', $lote->participantes()->where('fila_origen', 11)->value('modalidad'));
    }

    public function test_en_una_base_ya_creada_la_general_respalda_a_quien_no_tiene_la_suya_y_la_propia_gana(): void
    {
        // Bases existentes (creadas antes o a mano): la modalidad general sigue siendo el respaldo de quien no tiene la suya, y la propia manda.
        $lote = $this->base([['Ana Uno', 'C.C. 1.111', 'Virtual'], ['Beto Dos', 'C.C. 2.222', null]], $this->evento->id, ['modalidad' => 'Presencial']);
        config(['credential_flow.emision.exige_vista_previa' => false]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2);

        $porNombre = fn (string $n) => Emision::where('participante_id', $lote->participantes()->where('nombre_completo', $n)->value('id'))->firstOrFail()->datos_snapshot['modalidad'];
        $this->assertSame('Virtual', $porNombre('ANA UNO'), 'la propia gana a la general');
        $this->assertSame('Presencial', $porNombre('BETO DOS'), 'sin la suya, la general de la base');
    }

    public function test_en_el_asistente_la_modalidad_general_no_rellena_a_quien_le_falta_la_suya(): void
    {
        $this->varianteQa();   // sus pruebas QA salen SOLO con la variante de su plantilla
        $modalidades = $this->ochoYDos();
        $modalidades[4] = null;   // fila 11: celda de modalidad vacía en un Excel con modalidad por persona
        $id = $this->analizar($this->excel($modalidades));
        $this->decidir($id);
        $this->corregir($id, ['datos_comunes' => ['modalidad' => 'Presencial']])->assertOk();

        // Con una modalidad general escrita, la persona sin la suya SIGUE por revisar: no se supone que sea la general.
        $bloqueos = collect($this->validar($id)->json('bloqueos'));
        $this->assertSame([11], $bloqueos->firstWhere('codigo', 'modalidad_por_revisar')['filas']);
        $this->assertNull($bloqueos->firstWhere('codigo', 'modalidad_faltante'), 'un solo aviso por persona');

        $this->corregir($id, ['participantes' => ['11' => ['modalidad' => 'Virtual']]])->assertOk();
        $this->assertTrue($this->validar($id)->json('listo'));
        $lote = $this->confirmar($id);
        $this->assertSame('Virtual', $lote->participantes()->where('fila_origen', 11)->value('modalidad'));
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 10);
        $this->assertSame(['Presencial' => 7, 'Virtual' => 3], Emision::query()->get()->filter(fn ($e) => ! Participante::find($e->participante_id)->es_control_qa)->countBy(fn ($e) => $e->datos_snapshot['modalidad'])->sortKeys()->all());
    }

    public function test_en_una_base_mixta_sin_modalidad_general_no_se_emite_a_quien_le_falta(): void
    {
        $lote = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial'], ['Beto Dos', 'C.C. 2.222', 'Virtual'], ['Caro Tres', 'C.C. 3.333', null]], $this->evento->id);

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(422)->assertJsonPath('error.code', 'DATOS_DEL_DISENO_INCOMPLETOS');
        $this->assertSame(['CARO TRES'], array_column($r->json('error.detalles'), 'nombre'));
        $this->assertSame(0, Emision::count(), 'todo o nada: no se emitió a nadie');
        $this->assertContains('1 participante sin modalidad (complétala en cada uno o como modalidad general en «Editar base»).', app(FlujoDeBase::class)->para($lote->fresh(), app(EmisorLote::class)->resumen($lote->fresh()))['faltan']);

        // Se completa en el participante (mismo formulario de siempre) y ya se puede emitir.
        $caro = $lote->participantes()->where('documento', 'C.C. 3.333')->firstOrFail();
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote, $caro]), ['nombre_completo' => 'Caro Tres', 'documento' => 'C.C. 3.333', 'modalidad' => 'Virtual'])->assertRedirect();
        $this->assertSame('Virtual', $caro->fresh()->modalidad);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 3);
    }

    public function test_tras_emitir_a_todos_con_su_modalidad_no_aparece_un_aviso_falso_de_modalidad(): void
    {
        $lote = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial'], ['Beto Dos', 'C.C. 2.222', 'Virtual']], $this->evento->id);
        $faltan = fn () => app(FlujoDeBase::class)->para($lote->fresh(), app(EmisorLote::class)->resumen($lote->fresh()))['faltan'];
        $this->assertEmpty(array_filter($faltan(), fn ($f) => str_contains($f, 'odalidad')), 'antes de emitir');

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2);
        $this->assertEmpty(array_filter($faltan(), fn ($f) => str_contains($f, 'odalidad')), 'después de emitir a todos');

        // Una base SIN modalidades propias y sin la general sigue avisando, como antes.
        $vieja = $this->base([['Caro Tres', 'C.C. 3.333', null]], null);
        $this->assertContains('Completar en «Editar base» lo que imprime la plantilla: Modalidad.', app(FlujoDeBase::class)->para($vieja, app(EmisorLote::class)->resumen($vieja))['faltan']);
    }

    // ── 3b. Revisión de la vista previa: una sola vez ────────────────────────

    /** Borrador del Excel 8 + 2 listo para crear la base, con la vista previa EXIGIDA. */
    private function borradorConVistaPrevia(): int
    {
        config(['credential_flow.emision.exige_vista_previa' => true]);
        $id = $this->analizar($this->excel($this->ochoYDos()));
        $this->decidir($id);

        return $id;
    }

    private function verPdf(int $id): void
    {
        $this->actingAs($this->admin())->get(route('credential-flow.importaciones.vista-previa.pdf', $id))->assertOk();
    }

    public function test_la_vista_previa_revisada_en_el_asistente_no_se_vuelve_a_pedir_en_la_base(): void
    {
        $this->varianteQa();   // sus pruebas QA salen SOLO con la variante de su plantilla
        $id = $this->borradorConVistaPrevia();
        $version = Importacion::findOrFail($id)->version;
        $this->verPdf($id);
        $this->assertSame($version, Importacion::findOrFail($id)->version, 'ver el PDF no cambia la versión del borrador');

        $lote = $this->confirmar($id);
        $this->assertTrue(app(ServicioVistaPrevia::class)->confirmada($lote->fresh()));
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 10);
    }

    public function test_sin_abrir_el_pdf_o_si_cambian_los_datos_despues_la_base_la_pide_de_nuevo(): void
    {
        $this->varianteQa();   // sus pruebas QA salen SOLO con la variante de su plantilla
        // Nunca abrió el PDF.
        $id = $this->borradorConVistaPrevia();
        $lote = $this->confirmar($id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');

        // Abrió el PDF, pero después cambió un dato del certificado (la fecha).
        $otro = EventoCertificacion::create(['nombre' => 'Otro seminario sintético', 'nombre_normalizado' => NombreConservador::normalizar('Otro seminario sintético'), 'anio' => 2026,
            'fecha_texto' => '22 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        $id2 = $this->analizar($this->excel(['Presencial', 'Virtual']));
        $this->decidir($id2, 2);
        $this->corregir($id2, ['evento' => ['decision' => 'asociar', 'evento_id' => $otro->id]])->assertOk();
        $this->verPdf($id2);
        $this->corregir($id2, ['datos_comunes' => ['fecha' => '23 DE OCTUBRE DE 2026']])->assertOk();
        $lote2 = $this->confirmar($id2);
        $this->assertFalse(app(ServicioVistaPrevia::class)->confirmada($lote2->fresh()));
    }

    public function test_si_la_plantilla_cambia_despues_de_crear_la_base_se_vuelve_a_pedir(): void
    {
        $this->varianteQa();   // sus pruebas QA salen SOLO con la variante de su plantilla
        $id = $this->borradorConVistaPrevia();
        $this->verPdf($id);
        $lote = $this->confirmar($id);
        $vp = app(ServicioVistaPrevia::class);
        $this->assertTrue($vp->confirmada($lote->fresh()));

        $diseno = $this->plantilla->diseno;
        $diseno['elements'][0]['fontSize'] = 13;   // alguien edita el diseño
        $this->plantilla->update(['diseno' => $diseno]);
        $this->assertFalse($vp->confirmada($lote->fresh()));
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VISTA_PREVIA_PENDIENTE');
    }

    // ── 2b. Contactos QA: personas distintas vs pruebas ──────────────────────

    public function test_la_base_distingue_8_contactos_qa_de_sus_16_pruebas(): void
    {
        $id = $this->analizar($this->excel($this->ochoYDos()));
        $this->decidir($id);
        $lote = $this->confirmar($id);

        $qa = app(EmisorLote::class)->resumen($lote)['qa'];
        $this->assertSame([8, 16], [$qa['contactos'], $qa['participantes']]);
        $fila = collect($this->actingAs($this->admin())->get(route('credential-flow.lotes.index'))->assertOk()->viewData('page')['props']['lotes']['data'])->firstWhere('id', $lote->id);
        $this->assertSame([16, 8], [$fila['qa'], $fila['qa_contactos']]);
        $this->assertSame(26, Participante::where('lote_id', $lote->id)->count(), 'no se elimina ni se altera ninguna prueba');
    }

    // ── 4. Bases existentes (modalidad general) ───────────────────────────────

    public function test_las_bases_con_modalidad_general_emiten_igual_que_antes(): void
    {
        $lote = $this->base([['Ana Uno', 'C.C. 1.111', null], ['Beto Dos', 'C.C. 2.222', null]], null, ['modalidad' => 'Virtual-Sincrónico y Remoto']);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2);
        foreach (Emision::all() as $e) {
            $this->assertSame('Virtual-Sincrónico y Remoto', $e->datos_snapshot['modalidad']);
        }

        // Una base cuya plantilla no imprime la modalidad no exige nada nuevo y su snapshot no la incluye.
        $sinModalidad = Lote::create(['plantilla_id' => $this->plantillaLista()->id, 'nombre' => 'Base antigua', 'datos_comunes' => ['evento' => 'X', 'fecha' => '1 DE ENERO DE 2026', 'intensidad_horaria' => '2 HORAS']]);
        $this->participante($sinModalidad, 'Dani Cuatro', 'C.C. 4.444');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $sinModalidad))->assertCreated();
        $this->assertArrayNotHasKey('modalidad', Emision::where('lote_id', $sinModalidad->id)->firstOrFail()->datos_snapshot);
    }

    // ── 5. Emisiones duplicadas en el mismo evento ────────────────────────────

    public function test_no_se_certifica_dos_veces_a_la_misma_persona_en_el_mismo_evento_desde_otra_base(): void
    {
        $a = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial']], $this->evento->id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $a))->assertCreated();

        // Un Excel corregido creó otra base del mismo evento con Ana y una persona nueva.
        $b = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial'], ['Beto Dos', 'C.C. 2.222', 'Virtual']], $this->evento->id);
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $b))->assertStatus(409)->assertJsonPath('error.code', 'DUPLICADO_EN_EVENTO');
        $this->assertSame(['ANA UNO'], array_column($r->json('error.detalles'), 'nombre'));
        $this->assertStringContainsString($a->nombre, $r->json('error.detalles.0.mensaje'));
        $this->assertSame(1, Emision::count(), 'no se emitió nada en la segunda base');

        // Tampoco por la emisión individual.
        $anaB = $b->participantes()->where('documento', 'C.C. 1.111')->firstOrFail();
        $this->emitirPor($b, $anaB)->assertStatus(409)->assertJsonPath('error.code', 'DUPLICADO_EN_EVENTO');

        // El administrador la marca como no elegible en la base nueva y el resto sí se emite.
        $anaB->update(['elegibilidad' => Participante::NO_ELEGIBLE, 'elegibilidad_motivo' => 'Ya certificada en la otra base']);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $b))->assertCreated()->assertJsonPath('resultado.total', 1);
        $this->assertSame(2, Emision::count());
    }

    public function test_un_documento_con_los_mismos_digitos_y_otro_tipo_pide_revision(): void
    {
        $a = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial']], $this->evento->id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $a))->assertCreated();
        $b = $this->base([['Ana Uno', 'C.E. 1.111', 'Presencial']], $this->evento->id);

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $b))->assertStatus(409)->assertJsonPath('error.code', 'DUPLICADO_EN_EVENTO');
        $this->assertSame('revisar', $r->json('error.detalles.0.tipo'));
        $this->assertSame(1, Emision::count());
    }

    public function test_otro_evento_una_revocacion_o_una_reemision_no_se_bloquean(): void
    {
        $a = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial']], $this->evento->id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $a))->assertCreated();
        $emisionA = Emision::firstOrFail();

        // Reemitir en su propia base sigue funcionando (nueva versión de la MISMA persona).
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $emisionA), ['motivo' => 'Corrección de prueba'])->assertSuccessful();

        // Otro evento: no hay conflicto.
        $otro = EventoCertificacion::create(['nombre' => 'Otro evento sintético', 'nombre_normalizado' => NombreConservador::normalizar('Otro evento sintético'), 'anio' => 2026,
            'fecha_texto' => '21 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        $c = $this->base([['Ana Uno', 'C.C. 1.111', 'Virtual']], $otro->id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $c))->assertCreated();

        // Revocada la de la primera base, la persona sí se puede certificar en otra base del mismo evento.
        $vigente = Emision::where('lote_id', $a->id)->where('estado', Emision::EMITIDA)->firstOrFail();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $vigente), ['motivo' => 'Se certifica en la base corregida'])->assertSuccessful();
        $b = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial']], $this->evento->id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $b))->assertCreated();
    }

    public function test_los_contactos_qa_nunca_cuentan_como_duplicados(): void
    {
        $this->varianteQa();
        $a = $this->base([['Ana Uno', 'C.C. 1.111', 'Presencial']], $this->evento->id);
        $b = $this->base([['Beto Dos', 'C.C. 2.222', 'Presencial']], $this->evento->id);
        foreach ([$a, $b] as $lote) {
            app(ServicioQa::class)->incorporar($lote);
            $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertCreated()->assertJsonPath('resultado.total', 8);
        }
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $b))->assertCreated()->assertJsonPath('resultado.total', 1);
    }
}
