<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;

/**
 * Fase 2 del importador inteligente: lo que antes se corregía a mano en las bases #14 y #15 ahora sale bien desde el Excel.
 *
 *  - Modalidad POR PERSONA desde una columna «Modalidad» o desde «Observaciones o comentarios adicionales» cuando dice exactamente PRESENCIAL / VIRTUAL.
 *    Lo dudoso, contradictorio o vacío se pide revisar: nunca se rellena con la modalidad general.
 *  - El Excel trae a quienes se certifican: cada persona nace ELEGIBLE salvo que el archivo diga otra cosa.
 *  - Las fechas salen con el mes en minúscula: «16 de octubre de 2026», «22 y 23 de octubre de 2026».
 *
 * Caso de regresión: la estructura del seminario PIC (39 personas: 23 presenciales y 16 virtuales en «Observaciones»). Datos 100 % sintéticos.
 */
class ImportadorFase2Test extends EmisionesTestCase
{
    private const EVENTO_PIC = 'Seminario sintético de talento humano y herramientas para la elaboración del plan institucional de capacitación - PIC';

    /** Filas del Excel con «VIRTUAL», en la misma posición que en el seminario PIC real (las demás dicen «PRESENCIAL»). */
    private const FILAS_VIRTUALES = [9, 11, 12, 13, 21, 22, 23, 24, 27, 28, 29, 30, 31, 32, 33, 37];

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
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad', 'intensidad_horaria'];
        $this->plantilla = $this->plantillaLista(array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 40 + $i * 50, 'height' => 30, 'fontSize' => 12]), $campos, array_keys($campos)));
        $this->evento = EventoCertificacion::create([
            'nombre' => self::EVENTO_PIC, 'nombre_normalizado' => NombreConservador::normalizar(self::EVENTO_PIC), 'anio' => 2026,
            'fecha_texto' => '22 y 23 de octubre de 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]);
        for ($i = 1; $i <= 8; $i++) {
            $doc = '8.000.00'.$i;
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA {$i} PRUEBA", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /**
     * Excel con la estructura del seminario PIC: bloque del evento con «Modalidad: Presencial» general y una tabla (encabezados en la fila 7) cuya columna E
     * «Observaciones o comentarios adicionales» trae la modalidad de cada persona. Una observación null deja la celda vacía.
     *
     * @param  list<?string>  $observaciones  una por persona, desde la fila 8
     * @param  array<string,mixed>  $celdas  celdas adicionales (por ejemplo, otra columna)
     */
    private function excelPic(array $observaciones, array $celdas = [], string $fecha = '22 Y 23 de octubre de 2026'): string
    {
        $c = [
            'B1' => 'Nombre del Evento', 'D1' => self::EVENTO_PIC,
            'B2' => 'Gestora Comercial Encargada', 'D2' => 'Gestora Ficticia', 'F2' => 'Modalidad', 'G2' => 'Presencial',
            'B3' => 'Fecha del Evento', 'D3' => $fecha, 'F3' => 'Intensidad de horas', 'G3' => 'Seminario 18 Horas',
            'B5' => 'Información Diplomas',
            'B7' => 'Identificaciòn', 'C7' => 'Nombres y Apellidos', 'D7' => 'Correo Electronico', 'E7' => 'Observaciones o comentarios adicionales',
        ];
        foreach ($observaciones as $i => $obs) {
            $f = 8 + $i;
            $c['B'.$f] = (string) (52000000 + $f);
            $c['C'.$f] = sprintf('PERSONA %02d PIC', $i + 1);
            $c['D'.$f] = sprintf('pic%02d@example.test', $i + 1);
            if ($obs !== null) {
                $c['E'.$f] = $obs;
            }
        }

        return XlsxSintetico::libro([['nombre' => 'Hoja1', 'celdas' => $celdas + $c]]);
    }

    /** @return list<string> las 39 observaciones del seminario PIC, en su orden */
    private function observacionesPic(): array
    {
        return array_map(fn (int $f) => in_array($f, self::FILAS_VIRTUALES, true) ? 'VIRTUAL' : 'PRESENCIAL', range(8, 46));
    }

    private function analizar(string $bytes): TestResponse
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('pic.xlsx', $bytes)], ['Accept' => 'application/json'])->assertSuccessful();
    }

    private function corregir(int $id, array $cambios): TestResponse
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cambios)->assertOk();
    }

    /** @return array<int,array<string,mixed>> */
    private function personas(int $id): array
    {
        return collect($this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador.participantes'))->keyBy('fila')->all();
    }

    /** Plantilla, evento y datos comunes decididos; el tipo de documento (el Excel PIC no lo trae) confirmado como C.C. para todos. */
    private function decidir(int $id): void
    {
        $tipos = array_map(fn () => ['tipo_documento' => 'C.C.'], $this->personas($id));
        $this->corregir($id, [
            'plantilla_id' => $this->plantilla->id,
            'evento' => ['decision' => 'asociar', 'evento_id' => $this->evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true],
            'participantes' => array_combine(array_map('strval', array_keys($tipos)), array_values($tipos)),
        ]);
    }

    private function validar(int $id): array
    {
        return $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
    }

    private function confirmar(int $id): Lote
    {
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true])->assertCreated();

        return Lote::findOrFail($r->json('lote_id'));
    }

    // ── 1. Regresión del seminario PIC: 39 personas, 23 presenciales y 16 virtuales ──

    public function test_el_excel_del_seminario_pic_da_23_presenciales_y_16_virtuales_elegibles_sin_correcciones_manuales(): void
    {
        $r = $this->analizar($this->excelPic($this->observacionesPic()));
        $id = $r->json('borrador.id');
        $personas = collect($r->json('borrador.participantes'))->keyBy('fila');

        // Lo que antes se corregía a mano: la modalidad de cada persona y su elegibilidad.
        $this->assertCount(39, $personas);
        $this->assertSame(self::FILAS_VIRTUALES, $personas->where('modalidad', 'Virtual')->keys()->all(), 'las 16 virtuales, exactamente en sus filas');
        $this->assertSame(23, $personas->where('modalidad', 'Presencial')->count());
        $this->assertSame(39, $personas->where('elegibilidad', 'elegible')->count(), 'nadie queda «Por validar»');
        $this->assertTrue($personas->every(fn ($p) => $p['elegibilidad_origen'] === 'automatica' && ! $p['modalidad_por_revisar']));
        $this->assertSame('VIRTUAL', $personas[9]['originales']['modalidad'], 'se ve lo que decía el Excel');
        // El mes, en minúscula; la modalidad general del bloque del evento se conserva como respaldo de la base.
        $comunes = $r->json('borrador.datos_comunes');
        $this->assertSame(['22 y 23 de octubre de 2026', 'Presencial'], [$comunes['fecha']['valor'], $comunes['modalidad']['valor']]);

        $this->decidir($id);
        $e = $this->validar($id);
        $this->assertTrue($e['listo'], json_encode($e['bloqueos']));
        $this->assertSame(['Presencial' => 23, 'Virtual' => 16], $e['resumen']['por_modalidad']);

        $lote = $this->confirmar($id);
        $reales = $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->get();
        $this->assertCount(39, $reales);
        $this->assertSame(self::FILAS_VIRTUALES, $reales->where('modalidad', 'Virtual')->pluck('fila_origen')->map(fn ($f) => (int) $f)->values()->all());
        $this->assertSame(23, $reales->where('modalidad', 'Presencial')->count());
        $this->assertSame(39, $reales->where('elegibilidad', Participante::ELEGIBLE)->count());
        $this->assertSame('22 y 23 de octubre de 2026', $lote->datos_comunes['fecha']);
        // Los QA, aparte: los ocho en cada modalidad presente.
        $qa = $lote->participantes()->where('es_control_qa', true)->get();
        $this->assertSame(['Presencial' => 8, 'Virtual' => 8], $qa->countBy('modalidad')->sortKeys()->all());
        $this->assertSame(16, $qa->where('elegibilidad', Participante::ELEGIBLE)->count());

        // Los certificados oficiales salen con la modalidad de cada persona, la cédula con puntos y el mes en minúscula.
        config(['credential_flow.emision.exige_vista_previa' => false]);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 39);
        $virtual = Emision::where('participante_id', $reales->firstWhere('fila_origen', 9)->id)->firstOrFail();
        $textos = array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $virtual))->getContent()))->textos(), 'texto');
        $this->assertContains('Virtual', $textos);
        $this->assertContains('C.C. 52.000.009', $textos);
        $this->assertContains('22 y 23 de octubre de 2026', $textos);
    }

    // ── 2. Escritura de la modalidad ──────────────────────────────────────────

    public function test_mayusculas_tildes_espacios_y_punto_final_no_cambian_la_modalidad(): void
    {
        $personas = collect($this->analizar($this->excelPic(['  virtual ', 'Presencial.', 'PRESENCIAL', 'Virtual', 'presencial']))->json('borrador.participantes'))->keyBy('fila');

        $this->assertSame(['Virtual', 'Presencial', 'Presencial', 'Virtual', 'Presencial'], $personas->pluck('modalidad')->values()->all(), 'siempre escritas igual en el certificado');
        $this->assertFalse($personas->contains(fn ($p) => $p['modalidad_por_revisar']));
    }

    public function test_un_excel_con_una_sola_modalidad_tambien_se_reconoce(): void
    {
        $id = $this->analizar($this->excelPic(array_fill(0, 5, 'PRESENCIAL')))->json('borrador.id');
        $this->decidir($id);

        $e = $this->validar($id);
        $this->assertTrue($e['listo'], json_encode($e['bloqueos']));
        $this->assertSame(['Presencial' => 5], $e['resumen']['por_modalidad']);
    }

    // ── 3. Lo dudoso se pide revisar; la modalidad general nunca lo rellena ──

    public function test_las_observaciones_dudosas_vacias_o_contradictorias_se_piden_revisar_sin_usar_la_general(): void
    {
        // Filas 8 a 13: clara, con signo de duda, otro concepto, vacía, dos modalidades, clara.
        $id = $this->analizar($this->excelPic(['VIRTUAL', 'Virtual?', 'Semipresencial', null, 'virtual y presencial', 'PRESENCIAL']))->json('borrador.id');
        $personas = $this->personas($id);

        $this->assertSame([9, 10, 11, 12], array_keys(array_filter($personas, fn ($p) => $p['modalidad_por_revisar'])));
        foreach ([9, 10, 11, 12] as $f) {
            $this->assertNull($personas[$f]['modalidad'], "fila {$f}: no se adivina ni se usa la general");
            $this->assertContains('modalidad_por_revisar', $personas[$f]['avisos']);
        }
        $this->assertSame(['Virtual', 'Presencial'], [$personas[8]['modalidad'], $personas[13]['modalidad']]);

        $this->decidir($id);
        $bloqueos = collect($this->validar($id)['bloqueos']);
        $this->assertSame([9, 10, 11, 12], $bloqueos->firstWhere('codigo', 'modalidad_por_revisar')['filas']);
        $this->assertNull($bloqueos->firstWhere('codigo', 'modalidad_faltante'), 'un solo aviso por persona');

        // El administrador escribe la de cada una (la general del evento sigue siendo «Presencial» y no cuenta).
        $this->corregir($id, ['participantes' => ['9' => ['modalidad' => 'Virtual'], '10' => ['modalidad' => 'Presencial'], '11' => ['modalidad' => 'Presencial'], '12' => ['modalidad' => 'Virtual']]]);
        $this->assertTrue($this->validar($id)['listo']);
        $lote = $this->confirmar($id);
        $this->assertSame(['Virtual', 'Virtual', 'Presencial', 'Presencial', 'Virtual', 'Presencial'], $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->pluck('modalidad')->all());
    }

    public function test_si_la_columna_modalidad_y_las_observaciones_se_contradicen_se_pide_revisar(): void
    {
        // Columna F «Modalidad» además de las observaciones: en la fila 9 dicen cosas distintas; en la 10 la columna está vacía y manda la observación.
        $celdas = ['F7' => 'Modalidad', 'F8' => 'Virtual', 'F9' => 'Presencial', 'F11' => 'Virtual-Sincrónico y Remoto'];
        $personas = collect($this->analizar($this->excelPic(['VIRTUAL', 'VIRTUAL', 'PRESENCIAL', 'VIRTUAL'], $celdas))->json('borrador.participantes'))->keyBy('fila');

        $this->assertSame([false, true, false, false], $personas->pluck('modalidad_por_revisar')->values()->all());
        $this->assertSame(['Virtual', null, 'Presencial', 'Virtual-Sincrónico y Remoto'], $personas->pluck('modalidad')->values()->all(), 'la columna manda si no se contradice; un texto propio de la columna se respeta');
    }

    public function test_si_las_observaciones_son_notas_libres_no_se_inventa_ninguna_modalidad(): void
    {
        $id = $this->analizar($this->excelPic(['Pagó con transferencia', null, 'Llegó tarde el segundo día']))->json('borrador.id');
        $personas = $this->personas($id);

        $this->assertSame([null, null, null], array_column(array_values($personas), 'modalidad'));
        $this->assertFalse(collect($personas)->contains(fn ($p) => $p['modalidad_por_revisar']), 'sin modalidad por persona: vale la general de la base, como siempre');
        $this->decidir($id);
        $e = $this->validar($id);
        $this->assertTrue($e['listo'], json_encode($e['bloqueos']));
        $this->assertSame([], $e['resumen']['por_modalidad']);
    }

    // ── 4. Fechas en minúscula ────────────────────────────────────────────────

    public function test_las_fechas_salen_con_el_mes_en_minuscula(): void
    {
        foreach ([
            '16/10/2026' => '16 de octubre de 2026',
            '16 DE OCTUBRE DE 2026' => '16 de octubre de 2026',
            '22 Y 23 de octubre de 2026' => '22 y 23 de octubre de 2026',
            '14, 15 y 16 de octubre de 2026' => '14, 15 y 16 de octubre de 2026',
            '30 de septiembre de 2026 y 1 de octubre de 2026' => '30 de septiembre de 2026 y 1 de octubre de 2026',
        ] as $excel => $esperada) {
            $fecha = $this->analizar($this->excelPic(['PRESENCIAL'], [], $excel))->json('borrador.datos_comunes.fecha.valor');
            $this->assertSame($esperada, $fecha, "«{$excel}»");
            $this->assertSame(0, preg_match('/[A-ZÁÉÍÓÚ]{3,}/u', $fecha), 'ningún mes en mayúscula');
        }
    }

    // ── 5. Elegibilidad: la regla de negocio y lo que declara el archivo ─────

    public function test_si_el_excel_declara_otra_cosa_que_elegible_se_advierte_y_se_decide_a_proposito(): void
    {
        $celdas = ['F7' => 'Elegibilidad', 'F8' => 'Elegible', 'F9' => 'No elegible', 'F10' => 'Pendiente', 'F11' => 'Tal vez'];
        $id = $this->analizar($this->excelPic(['PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL', 'PRESENCIAL'], $celdas))->json('borrador.id');
        $personas = $this->personas($id);

        $this->assertSame(
            [8 => ['elegible', 'excel'], 9 => ['pendiente', 'excel'], 10 => ['pendiente', 'excel'], 11 => ['pendiente', 'excel'], 12 => ['elegible', 'automatica']],
            array_map(fn ($p) => [$p['elegibilidad'], $p['elegibilidad_origen']], $personas),
            'nadie queda excluido en silencio: «No elegible», «Pendiente» o un estado raro esperan una decisión',
        );
        $this->assertContains('excel_declara_no_elegible', $personas[9]['avisos']);
        $this->assertContains('excel_declara_pendiente', $personas[10]['avisos']);
        $this->assertContains('excel_declara_estado_dudoso', $personas[11]['avisos']);

        $this->decidir($id);
        $this->assertSame([9, 10, 11], collect($this->validar($id)['bloqueos'])->firstWhere('codigo', 'elegibilidad_sin_decidir')['filas']);
        // El administrador decide a propósito: la 9 se respeta como no elegible, la 10 sí se certifica, la 11 no.
        $this->corregir($id, ['participantes' => ['9' => ['elegibilidad' => 'no_elegible'], '10' => ['elegibilidad' => 'elegible'], '11' => ['elegibilidad' => 'no_elegible']]]);
        $lote = $this->confirmar($id);

        $this->assertSame([8 => 'elegible', 9 => 'no_elegible', 10 => 'elegible', 11 => 'no_elegible', 12 => 'elegible'],
            $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->pluck('elegibilidad', 'fila_origen')->map(fn ($e) => (string) $e)->all());
        $this->assertSame(0, Emision::count(), 'crear la base no emite nada');
    }

    public function test_las_bases_anteriores_no_cambian_y_los_qa_siguen_aparte(): void
    {
        // Una base anterior, con sus personas pendientes y sin modalidad propia: la nueva regla no la toca.
        $vieja = Lote::create(['plantilla_id' => $this->plantilla->id, 'nombre' => 'Base anterior', 'datos_comunes' => ['evento' => 'X', 'fecha' => '16 DE OCTUBRE DE 2026', 'intensidad_horaria' => '5 HORAS']]);
        $p = $vieja->participantes()->create(['nombre_completo' => 'PERSONA ANTERIOR', 'documento' => 'C.C. 1234567', 'documento_clave' => Texto::claveDocumento('C.C. 1234567'), 'elegibilidad' => 'pendiente']);
        $antes = $p->fresh()->only(['elegibilidad', 'modalidad', 'documento', 'updated_at']);

        $id = $this->analizar($this->excelPic($this->observacionesPic()))->json('borrador.id');
        $this->assertCount(39, $this->personas($id), 'los contactos QA no aparecen entre las personas del borrador');
        $this->decidir($id);
        $nueva = $this->confirmar($id);

        $this->assertEquals($antes, $p->fresh()->only(['elegibilidad', 'modalidad', 'documento', 'updated_at']));
        $this->assertSame('16 DE OCTUBRE DE 2026', $vieja->fresh()->datos_comunes['fecha'], 'los datos guardados de antes no se reescriben');
        $this->assertSame(39, $nueva->participantes()->where('es_control_qa', false)->count());
        $this->assertSame(16, $nueva->participantes()->where('es_control_qa', true)->count());
    }
}
