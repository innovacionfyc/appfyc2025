<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Generacion\RedaccionFecha;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;

/**
 * Fase 3: «el día» / «los días» lo decide la FECHA (no hace falta una plantilla por redacción) y las pruebas QA salen SOLO con la variante de prueba de la
 * plantilla de su base (nunca con la última designada, que podía ser el diseño de otra capacitación). Datos 100 % sintéticos.
 */
class Fase3FechasQaTest extends EmisionesTestCase
{
    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private const EVENTO = 'Taller sintético de fechas inteligentes';

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
        config(['credential_flow.importacion_inteligente.activa' => true, 'credential_flow.qa.contactos_esperados' => 2, 'credential_flow.emision.exige_vista_previa' => false]);
        for ($i = 1; $i <= 2; $i++) {
            $doc = (string) (71000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA FECHAS {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa.fechas{$i}@example.test", 'correo_normalizado' => "qa.fechas{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Plantilla como las institucionales #13 (un día) o #15 (varios días): la fecha con su «Texto antes» y la modalidad dinámica. */
    private function institucional(string $prefijoFecha = 'realizado el día '): Plantilla
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad', 'intensidad_horaria'];

        return $this->plantillaLista(array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 40 + $i * 55, 'height' => 30, 'fontSize' => 12]
            + ($c === 'fecha' && $prefijoFecha !== '' ? ['prefix' => $prefijoFecha] : [])), $campos, array_keys($campos)));
    }

    private function variante(Plantilla $p): Plantilla
    {
        $this->artisan('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true])->assertExitCode(0);

        return Plantilla::query()->findOrFail($p->fresh()->variante_qa_id);
    }

    /** Base con una persona presencial y otra virtual (y, si se pide, sus contactos QA en cada modalidad). */
    private function base(Plantilla $p, string $fecha, bool $conQa = true): Lote
    {
        $lote = $this->loteCon(0, $p);
        $lote->update(['datos_comunes' => ['evento' => 'TALLER SINTÉTICO', 'fecha' => $fecha, 'intensidad_horaria' => '8 horas', 'modalidad' => 'Presencial']]);
        $this->participante($lote, 'Persona Presencial', 'C.C. 40343231')->update(['modalidad' => 'Presencial']);
        $this->participante($lote, 'Persona Virtual', 'C.C. 40343232')->update(['modalidad' => 'Virtual']);
        if ($conQa) {
            app(ServicioQa::class)->incorporar($lote->fresh());
        }

        return $lote->fresh();
    }

    /** @return list<string> */
    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent()))->textos(), 'texto');
    }

    private function emisionDe(Lote $lote, string $modalidad, bool $qa): Emision
    {
        $p = $lote->participantes()->where('es_control_qa', $qa)->where('modalidad', $modalidad)->orderBy('id')->firstOrFail();

        return Emision::where('participante_id', $p->id)->firstOrFail();
    }

    // ── 1–6. La redacción de la fecha ─────────────────────────────────────────

    public function test_la_redaccion_depende_de_los_dias_que_nombra_la_fecha_sin_cambiar_la_fecha(): void
    {
        $casos = [
            // Prefijo de la plantilla · fecha → cómo se lee
            ['realizado el día ', '16 de octubre de 2026', 'realizado el día 16 de octubre de 2026'],                              // 1 día
            ['realizado los días ', '16 de octubre de 2026', 'realizado el día 16 de octubre de 2026'],                            // 1 día con la plantilla de varios
            ['realizado el día ', '22 y 23 de octubre de 2026', 'realizado los días 22 y 23 de octubre de 2026'],                  // 2 días
            ['realizado los días ', '22 y 23 de octubre de 2026', 'realizado los días 22 y 23 de octubre de 2026'],
            ['realizado el día ', '1, 2 y 3 de octubre de 2026', 'realizado los días 1, 2 y 3 de octubre de 2026'],                // varios días
            ['realizado el día ', '30 de septiembre de 2026 y 1 de octubre de 2026', 'realizado los días 30 de septiembre de 2026 y 1 de octubre de 2026'],   // meses distintos
            ['realizado el día ', '30 de septiembre y 1 de octubre de 2026', 'realizado los días 30 de septiembre y 1 de octubre de 2026'],
            ['realizado el día ', '16 al 18 de octubre de 2026', 'realizado los días 16 al 18 de octubre de 2026'],                // rango
            ['realizado el día ', '16 DE OCTUBRE DE 2026', 'realizado el día 16 DE OCTUBRE DE 2026'],                              // bases antiguas en mayúsculas
            ['REALIZADO EL DÍA ', '22 Y 23 DE OCTUBRE DE 2026', 'REALIZADO LOS DÍAS 22 Y 23 DE OCTUBRE DE 2026'],                  // la plantilla en mayúsculas
            ['Realizado el dia ', '22 y 23 de octubre de 2026', 'Realizado los dias 22 y 23 de octubre de 2026'],                  // sin tilde: se respeta
            // Texto personalizado: otra redacción no se toca.
            ['Fecha: ', '22 y 23 de octubre de 2026', 'Fecha: 22 y 23 de octubre de 2026'],
            ['', '22 y 23 de octubre de 2026', '22 y 23 de octubre de 2026'],
            // Redacción institucional fija dentro de la fecha (bases antiguas): nunca se duplica.
            ['', 'Realizado los días 15 y 16 de octubre de 2026', 'Realizado los días 15 y 16 de octubre de 2026'],
            ['realizado el día ', 'los días 22 y 23 de octubre de 2026', 'realizado los días 22 y 23 de octubre de 2026'],
            ['realizado el día ', 'realizado los días 22 y 23 de octubre de 2026', 'realizado los días 22 y 23 de octubre de 2026'],
            // Fecha que no deja saber cuántos días son: la plantilla queda como está (y el asistente pide revisarla).
            ['realizado el día ', 'Octubre de 2026', 'realizado el día Octubre de 2026'],
            ['realizado el día ', '18 al 16 de octubre de 2026', 'realizado el día 18 al 16 de octubre de 2026'],
        ];
        foreach ($casos as [$prefijo, $fecha, $esperado]) {
            $this->assertSame($esperado, RedaccionFecha::prefijo($prefijo, $fecha).$fecha, "{$prefijo}|{$fecha}");
        }
        $this->assertSame([1, 2, 3, 2, 3, null, null], array_map(fn ($f) => RedaccionFecha::dias($f), [
            '16 de octubre de 2026', '22 y 23 de octubre de 2026', '1, 2 y 3 de octubre de 2026', '30 de septiembre de 2026 y 1 de octubre de 2026', '16 al 18 de octubre de 2026', '16/10/2026', 'Octubre de 2026',
        ]));

        $conDia = ['elements' => [['field' => 'fecha', 'prefix' => 'realizado el día ']]];
        $this->assertTrue(RedaccionFecha::requiereRevision($conDia, 'Octubre de 2026'));
        $this->assertFalse(RedaccionFecha::requiereRevision($conDia, '16 de octubre de 2026'));
        $this->assertFalse(RedaccionFecha::requiereRevision($conDia, 'los días 22 y 23 de octubre de 2026'), 'la fecha ya trae su redacción');
        $this->assertFalse(RedaccionFecha::requiereRevision(['elements' => [['field' => 'fecha', 'prefix' => 'Fecha: ']]], 'Octubre de 2026'), 'otra redacción no depende de los días');
    }

    // ── 7–9. PDF oficial y QA, presencial y virtual, con la variante correcta ──

    public function test_oficiales_y_qa_presenciales_y_virtuales_dicen_los_dias_con_la_plantilla_de_un_dia_y_su_variante(): void
    {
        $p = $this->institucional('realizado el día ');   // como la #13
        $disenoAntes = $p->diseno;
        $v = $this->variante($p);
        $lote = $this->base($p, '22 y 23 de octubre de 2026');

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertSame([2, 4, null], [$r->json('resultado.total'), $r->json('qa.total'), $r->json('qa_error')]);

        foreach (['Presencial', 'Virtual'] as $m) {
            $oficial = $this->textos($this->emisionDe($lote, $m, false));
            $this->assertContains('realizado los días 22 y 23 de octubre de 2026', $oficial, "oficial {$m}");
            $this->assertContains($m, $oficial);
            $this->assertNotContains(self::ADVERTENCIA, $oficial);

            $qa = $this->emisionDe($lote, $m, true);
            $this->assertSame($v->id, $qa->plantilla_id, 'la prueba sale con la variante de SU plantilla');
            $this->assertContains('realizado los días 22 y 23 de octubre de 2026', $this->textos($qa), "QA {$m}: misma regla que el oficial");
            $this->assertContains(self::ADVERTENCIA, $this->textos($qa));
            $this->assertContains($m, $this->textos($qa));
        }
        $this->assertSame($disenoAntes, $p->fresh()->diseno, 'el diseño de la plantilla no se modifica');
        $this->assertSame('realizado el día ', collect($v->fresh()->diseno['elements'])->firstWhere('field', 'fecha')['prefix'], 'ni el de la variante');
    }

    public function test_un_solo_dia_con_la_plantilla_de_varios_dias_dice_el_dia(): void
    {
        $p = $this->institucional('realizado los días ');   // como la #15
        $this->variante($p);
        $lote = $this->base($p, '16 de octubre de 2026');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();

        $this->assertContains('realizado el día 16 de octubre de 2026', $this->textos($this->emisionDe($lote, 'Virtual', false)));
        $this->assertContains('realizado el día 16 de octubre de 2026', $this->textos($this->emisionDe($lote, 'Presencial', true)));
    }

    public function test_los_escenarios_de_las_bases_14_y_15_imprimen_exactamente_lo_mismo_que_hoy(): void
    {
        foreach ([['realizado el día ', '16 de octubre de 2026'], ['realizado los días ', '22 y 23 de octubre de 2026']] as [$prefijo, $fecha]) {
            $p = $this->institucional($prefijo);
            $this->variante($p);
            $lote = $this->base($p, $fecha);
            $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
            foreach ([false, true] as $qa) {
                $this->assertContains($prefijo.$fecha, $this->textos($this->emisionDe($lote, 'Virtual', $qa)), $prefijo.$fecha);
            }
        }
    }

    public function test_plantillas_antiguas_y_textos_personalizados_no_cambian(): void
    {
        // Como las plantillas #3–#12: sin «Texto antes»; la fecha de la base ya trae su redacción.
        $antigua = $this->institucional('');
        $lote = $this->base($antigua, 'Realizado los días 15 y 16 de octubre de 2026', false);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $this->assertContains('Realizado los días 15 y 16 de octubre de 2026', $this->textos($this->emisionDe($lote, 'Presencial', false)));

        // Redacción propia de la plantilla: se respeta tal cual.
        $propia = $this->institucional('Fecha del evento: ');
        $otra = $this->base($propia, '22 y 23 de octubre de 2026', false);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $otra))->assertCreated();
        $this->assertContains('Fecha del evento: 22 y 23 de octubre de 2026', $this->textos($this->emisionDe($otra, 'Virtual', false)));
    }

    // ── 10–11. Sin variante QA no empieza nada; los reintentos no duplican ──────

    public function test_sin_variante_qa_nunca_se_usa_otra_plantilla_de_prueba_ni_se_emite_nada(): void
    {
        // Variante de OTRA capacitación, designada y válida: con la regla anterior se habría usado por ser la última designada.
        $ajena = $this->variante($this->institucional('realizado los días '));
        $p = $this->institucional('realizado el día ');
        $lote = $this->base($p, '16 de octubre de 2026');

        $this->assertNull(app(ServicioQa::class)->plantillaPara($lote));
        $this->assertFalse(app(EmisorLote::class)->resumen($lote)['qa']['plantilla_lista']);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $qa = $lote->participantes()->where('es_control_qa', true)->firstOrFail();
        $this->emitirPor($lote, $qa)->assertStatus(409)->assertJsonPath('error.code', 'VARIANTE_QA_FALTANTE');
        $this->assertSame(0, Emision::count(), 'ni oficiales ni pruebas');
        $this->assertSame(0, Emision::where('plantilla_id', $ajena->id)->count(), 'nunca con el diseño de otra capacitación');

        // Una base SIN contactos QA emite sus oficiales como siempre, aunque su plantilla no tenga variante.
        $sinQa = $this->base($p, '16 de octubre de 2026', false);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $sinQa))->assertCreated()->assertJsonPath('resultado.total', 2);
    }

    public function test_los_reintentos_no_duplican_y_las_emisiones_anteriores_no_cambian(): void
    {
        $p = $this->institucional('realizado el día ');
        $this->variante($p);
        $lote = $this->base($p, '22 y 23 de octubre de 2026');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $antes = Emision::orderBy('id')->get(['id', 'version', 'pdf_hash', 'pdf_archivo'])->toArray();
        $this->assertCount(6, $antes);

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', 'SIN_PENDIENTES');
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', 'SIN_PENDIENTES');
        $this->assertSame($antes, Emision::orderBy('id')->get(['id', 'version', 'pdf_hash', 'pdf_archivo'])->toArray(), 'nada se duplica ni se reemite');
        $this->artisan('credential-flow:verificar-emisiones')->assertExitCode(0);

        // ZIP administrativo: solo los oficiales (las pruebas QA siguen aparte).
        $zip = new \ZipArchive;
        $this->assertTrue($zip->open($this->actingAs($this->admin())->get(route('credential-flow.lotes.zip', $lote))->assertOk()->baseResponse->getFile()->getPathname()));
        $this->assertSame(2, $zip->numFiles);
        $zip->close();
    }

    // ── 12. El asistente: muestra cómo se leerá la fecha y pide revisar la ambigua ──

    public function test_el_asistente_muestra_la_redaccion_pide_revisar_la_fecha_ambigua_y_avisa_si_falta_la_variante(): void
    {
        $p = $this->institucional('realizado el día ');
        $evento = EventoCertificacion::create(['nombre' => self::EVENTO, 'nombre_normalizado' => NombreConservador::normalizar(self::EVENTO), 'anio' => 2026,
            'fecha_texto' => '22 y 23 de octubre de 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        $excel = function (string $fecha) {
            $c = ['B1' => 'Nombre del Evento', 'D1' => self::EVENTO, 'F2' => 'Modalidad', 'G2' => 'Presencial', 'B3' => 'Fecha del Evento', 'D3' => $fecha, 'F3' => 'Intensidad de horas', 'G3' => '8 Horas',
                'B7' => 'Identificaciòn', 'C7' => 'Nombres y Apellidos', 'D7' => 'Correo Electronico', 'E7' => 'Observaciones o comentarios adicionales'];
            foreach (['PRESENCIAL', 'VIRTUAL'] as $i => $m) {
                $c['B'.(8 + $i)] = (string) (53000001 + $i);
                $c['C'.(8 + $i)] = 'PERSONA FECHA '.($i + 1);
                $c['D'.(8 + $i)] = 'fecha'.($i + 1).'@example.test';
                $c['E'.(8 + $i)] = $m;
            }

            return XlsxSintetico::libro([['nombre' => 'Hoja1', 'celdas' => $c]]);
        };
        $analizar = fn (string $fecha) => $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('f.xlsx', $excel($fecha))], ['Accept' => 'application/json'])->assertSuccessful()->json('borrador.id');
        $corregir = fn (int $id, array $c) => $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $c)->assertOk();
        $decidir = fn (int $id) => $corregir($id, ['plantilla_id' => $p->id, 'evento' => ['decision' => 'asociar', 'evento_id' => $evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true], 'participantes' => ['8' => ['tipo_documento' => 'C.C.'], '9' => ['tipo_documento' => 'C.C.']]]);
        $validar = fn (int $id) => $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $fechaVista = fn (int $id) => collect($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.vista-previa', $id))->assertOk()->json('vista_previa.campos'))->firstWhere('clave', 'fecha');

        // Dos días: la fecha interpretada y cómo se leerá en el certificado (con la misma regla que el PDF).
        $id = $analizar('22 Y 23 de octubre de 2026');
        $decidir($id);
        $this->assertSame(['22 y 23 de octubre de 2026', 'realizado los días 22 y 23 de octubre de 2026'], [$fechaVista($id)['valor'], $fechaVista($id)['se_lee']]);
        $e = $validar($id);
        $this->assertTrue($e['listo'], json_encode($e['bloqueos']));
        $this->assertSame(['nombre' => null], $e['variante_qa'], 'sin variante: se avisa antes de crear la base');
        $pdf = $this->actingAs($this->admin())->get(route('credential-flow.importaciones.vista-previa.pdf', $id))->assertOk()->getContent();
        $this->assertContains('realizado los días 22 y 23 de octubre de 2026', array_column((new InspectorPdf($pdf))->textos(), 'texto'));

        // Con su variante, el asistente dice con cuál saldrán las pruebas.
        $v = $this->variante($p);
        $this->assertSame(['nombre' => $v->nombre], $validar($id)['variante_qa']);

        // Fecha que no deja saber cuántos días son: se pide revisarla; corregida, desaparece el pendiente.
        $corregir($id, ['datos_comunes' => ['fecha' => 'Octubre de 2026']]);
        $this->assertContains('fecha_sin_dias_claros', array_column($validar($id)['bloqueos'], 'codigo'));
        $this->assertNotEmpty($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.vista-previa', $id))->json('vista_previa.problemas'));
        $corregir($id, ['datos_comunes' => ['fecha' => '16 de octubre de 2026']]);
        $this->assertNotContains('fecha_sin_dias_claros', array_column($validar($id)['bloqueos'], 'codigo'));
        $this->assertSame('realizado el día 16 de octubre de 2026', $fechaVista($id)['se_lee']);

        // Un rango del Excel se conserva como rango (antes se leía «16 y 18», que cambiaba el significado).
        $rango = $analizar('16 al 18 de octubre de 2026');
        $decidir($rango);
        $this->assertSame(['16 al 18 de octubre de 2026', 'realizado los días 16 al 18 de octubre de 2026'], [$fechaVista($rango)['valor'], $fechaVista($rango)['se_lee']]);
    }
}
