<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Importacion\FilasDeOrigen;
use App\Support\CredentialFlow\Importacion\ImportacionException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\ExcelSolicitud as X;

/**
 * Trazabilidad: en una base creada con la importación inteligente, `fila_origen` es la fila REAL de la hoja de Excel (no la línea del CSV intermedio).
 * El importador de siempre (CSV/Excel subido directamente) NO cambia: sigue numerando por línea del archivo. Datos 100 % sintéticos.
 */
class ImportacionFilaOrigenTest extends EmisionesTestCase
{
    private Plantilla $plantilla;

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

        config(['credential_flow.importacion_inteligente.activa' => true]);
        $this->plantilla = $this->plantillaLista([$this->elemento(['field' => 'nombre_completo', 'y' => 20, 'height' => 30, 'fontSize' => 12])]);
        EventoCertificacion::unguarded(fn () => EventoCertificacion::create([
            'id' => 631, 'nombre' => X::EVENTO_631, 'nombre_normalizado' => NombreConservador::normalizar(X::EVENTO_631), 'anio' => 2026,
            'fecha_texto' => '16 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW,
        ]));
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function sembrarQa(int $cuantos = 3): void
    {
        for ($i = 1; $i <= $cuantos; $i++) {
            $doc = "C.C. 8.000.00{$i}";
            $correo = "qa.{$i}@example.test";
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA {$i} PRUEBA", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc), 'correo' => $correo, 'correo_normalizado' => $correo, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function analizar(?string $bytes = null): int
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('solicitud.xlsx', $bytes ?? X::bytes())], ['Accept' => 'application/json'])->assertSuccessful()->json('borrador.id');
    }

    private function corregir(int $id, array $cuerpo): array
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cuerpo)->assertOk()->json('borrador');
    }

    /**
     * Deja el borrador listo para crear la base. Todas nacen elegibles (el Excel trae a quienes se certifican); el administrador confirma el tipo de
     * documento (el Excel no lo trae) y, a propósito, decide que la persona «Cupos» no se certifica (para comprobar que su decisión llega a SU fila).
     */
    private function prepararBorrador(?string $bytes = null, array $extra = []): int
    {
        $id = $this->analizar($bytes);
        $b = $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->json('borrador');
        $completos = [];
        foreach ($b['participantes'] as $p) {
            $completos[(string) $p['fila']] = ['tipo_documento' => 'C.C.'] + ($p['senal'] === 'cupo' ? ['elegibilidad' => 'no_elegible'] : []);
        }
        $this->corregir($id, array_replace_recursive([
            'plantilla_id' => $this->plantilla->id, 'evento' => ['decision' => 'asociar', 'evento_id' => 631],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true],
            'participantes' => $completos,
        ], $extra));

        return $id;
    }

    private function confirmar(int $id): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true]);
    }

    /** @return array<string,array<string,mixed>> participantes reales de la base, por nombre */
    private function reales(Lote $lote): array
    {
        return $lote->participantes()->where('es_control_qa', false)->get()->keyBy('nombre_completo')->all();
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return ['lotes' => Lote::withTrashed()->count(), 'participantes' => Participante::withTrashed()->count(), 'eventos' => EventoCertificacion::withTrashed()->count(), 'emisiones' => Emision::count()];
    }

    // ── La importación inteligente guarda la fila real del Excel ──────────────

    public function test_la_base_guarda_las_filas_reales_del_excel_y_no_las_lineas_del_csv_intermedio(): void
    {
        Mail::fake();
        $this->sembrarQa();
        $id = $this->prepararBorrador();

        $r = $this->confirmar($id)->assertCreated();
        $lote = Lote::findOrFail($r->json('lote_id'));
        $reales = $this->reales($lote);

        $this->assertCount(17, $reales);
        $this->assertSame(range(15, 31), $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->pluck('fila_origen')->map(fn ($f) => (int) $f)->all(), 'filas 15 a 31, no 2 a 18');
        $this->assertSame(15, (int) $reales['PERSONA 01 PRUEBA']->fila_origen);
        $this->assertSame(30, (int) $reales['PERSONA 16 PRUEBA']->fila_origen, 'la persona «Cupos» es la fila 30');
        $this->assertSame(31, (int) $reales['PERSONA 17 PRUEBA']->fila_origen);

        // Todo lo demás queda como estaba.
        $this->assertSame(Participante::NO_ELEGIBLE, $reales['PERSONA 16 PRUEBA']->elegibilidad, 'la decisión del administrador sobre «Cupos» llega a SU fila');
        $this->assertSame(16, collect($reales)->where('elegibilidad', Participante::ELEGIBLE)->count());
        $this->assertSame(17, collect($reales)->where('tipo_documento_confirmado', true)->count(), 'el tipo de documento se confirmó antes de crear la base');
        $qa = $lote->participantes()->where('es_control_qa', true)->get();
        $this->assertCount(3, $qa);
        $this->assertSame([null, null, null], $qa->pluck('fila_origen')->all(), 'los QA no tienen fila de origen');
        $this->assertSame(0, Emision::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_funciona_con_una_tabla_que_empieza_en_otra_fila_y_con_personas_excluidas_antes(): void
    {
        // Encabezados en la fila 22: la persona 01 está en la fila 23. No hay números fijos en el código.
        $id = $this->prepararBorrador(X::bytes(17, ['fila_encabezado' => 22]), ['participantes' => ['27' => ['excluir' => true]]]);   // se excluye a la persona 05 (fila 27)

        $lote = Lote::findOrFail($this->confirmar($id)->assertCreated()->json('lote_id'));
        $reales = $this->reales($lote);

        $this->assertCount(16, $reales);
        $this->assertArrayNotHasKey('PERSONA 05 PRUEBA', $reales);
        $this->assertSame(23, (int) $reales['PERSONA 01 PRUEBA']->fila_origen);
        $this->assertSame(26, (int) $reales['PERSONA 04 PRUEBA']->fila_origen);
        $this->assertSame(28, (int) $reales['PERSONA 06 PRUEBA']->fila_origen, 'la persona siguiente a la excluida conserva SU fila, no la de la posición');
        $this->assertSame(38, (int) $reales['PERSONA 16 PRUEBA']->fila_origen);
        $this->assertSame(39, (int) $reales['PERSONA 17 PRUEBA']->fila_origen);
    }

    public function test_la_intensidad_individual_sigue_llegando_a_la_persona_correcta(): void
    {
        $id = $this->prepararBorrador(null, ['participantes' => ['16' => ['intensidad_horaria' => '3 HORAS'], '31' => ['intensidad_horaria' => '2 HORAS']]]);

        $lote = Lote::findOrFail($this->confirmar($id)->assertCreated()->json('lote_id'));
        $reales = $this->reales($lote);

        $this->assertSame('3 HORAS', $reales['PERSONA 02 PRUEBA']->intensidad_horaria);
        $this->assertSame('2 HORAS', $reales['PERSONA 17 PRUEBA']->intensidad_horaria);
        $this->assertSame(2, collect($reales)->whereNotNull('intensidad_horaria')->count());
    }

    public function test_la_confirmacion_es_idempotente_y_no_vuelve_a_tocar_las_filas(): void
    {
        $id = $this->prepararBorrador();
        $loteId = $this->confirmar($id)->assertCreated()->json('lote_id');
        $antes = $this->conteos();
        $filas = Participante::query()->where('lote_id', $loteId)->orderBy('id')->pluck('fila_origen')->all();

        $this->confirmar($id)->assertStatus(409)->assertJsonPath('error.code', 'BORRADOR_YA_CONFIRMADO');

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($filas, Participante::query()->where('lote_id', $loteId)->orderBy('id')->pluck('fila_origen')->all());
    }

    // ── Si la correspondencia falla, no se crea nada ──────────────────────────

    public function test_si_una_persona_importada_queda_con_otra_linea_no_se_crea_la_base(): void
    {
        $this->sembrarQa();
        $id = $this->prepararBorrador();
        // Simula un importador defectuoso: justo después de importar (cuando registra su movimiento), la persona de la línea 5 queda con la línea 99.
        DB::unprepared("CREATE TRIGGER falla_fila AFTER INSERT ON movimientos WHEN NEW.modulo = 'credential-flow' BEGIN UPDATE cf_participantes SET fila_origen = 99 WHERE fila_origen = 5; END");
        $antes = $this->conteos();

        $r = $this->confirmar($id)->assertStatus(409);

        $this->assertSame('FILA_DE_ORIGEN_INCONSISTENTE', $r->json('error.code'));
        $this->assertStringContainsString('No se creó la base ni ningún participante', $r->json('error.message'));
        $this->assertStringNotContainsString('PERSONA', $r->getContent(), 'sin datos personales en el error');
        $this->assertSame($antes, $this->conteos(), 'todo o nada: ni base, ni participantes, ni QA');
        $imp = Importacion::findOrFail($id);
        $this->assertSame('en_revision', $imp->estado);
        $this->assertNotNull($imp->analisis, 'el borrador sigue intacto para reintentar');
    }

    public function test_si_una_persona_importada_queda_sin_linea_no_se_crea_la_base(): void
    {
        $id = $this->prepararBorrador();
        DB::unprepared("CREATE TRIGGER falla_fila AFTER INSERT ON movimientos WHEN NEW.modulo = 'credential-flow' BEGIN UPDATE cf_participantes SET fila_origen = NULL WHERE fila_origen = 9; END");
        $antes = $this->conteos();

        $this->confirmar($id)->assertStatus(409)->assertJsonPath('error.code', 'FILA_DE_ORIGEN_INCONSISTENTE');

        $this->assertSame($antes, $this->conteos());
        $this->assertSame('en_revision', Importacion::findOrFail($id)->estado);
    }

    public function test_la_comprobacion_detecta_cantidades_lineas_repetidas_y_filas_del_excel_invalidas(): void
    {
        $lote = Lote::create(['nombre' => 'Base de prueba', 'plantilla_id' => $this->plantilla->id, 'datos_comunes' => ['evento' => 'X']]);
        $insertar = fn (array $lineas) => array_map(fn ($l) => DB::table('cf_participantes')->insertGetId([
            'lote_id' => $lote->id, 'nombre_completo' => "P{$l}", 'documento' => "D{$l}", 'documento_clave' => "d{$l}", 'fila_origen' => $l, 'es_control_qa' => false, 'created_at' => now(), 'updated_at' => now(),
        ]), $lineas);
        $revisadas = fn (array $filas) => array_map(fn ($f) => ['fila' => $f], $filas);
        $esperaFallo = function (callable $f, string $caso) {
            try {
                $f();
                $this->fail("Debía rechazar: $caso");
            } catch (ImportacionException $e) {
                $this->assertSame(ImportacionException::FILA_ORIGEN_INCONSISTENTE, $e->codigo, $caso);
            }
        };
        $filasGuardadas = fn () => DB::table('cf_participantes')->where('lote_id', $lote->id)->orderBy('id')->pluck('fila_origen')->map(fn ($f) => $f === null ? null : (int) $f)->all();

        $insertar([2, 3, 4]);
        // Cantidad distinta: se revisaron 4 y se importaron 3.
        $esperaFallo(fn () => FilasDeOrigen::asignar($lote->id, $revisadas([15, 16, 17, 18])), 'cantidades distintas');
        // Filas del Excel repetidas, ausentes o no numéricas en lo revisado.
        $esperaFallo(fn () => FilasDeOrigen::asignar($lote->id, $revisadas([15, 15, 17])), 'fila repetida');
        $esperaFallo(fn () => FilasDeOrigen::asignar($lote->id, [['fila' => 15], ['fila' => '16'], ['fila' => 17]]), 'fila no entera');
        $esperaFallo(fn () => FilasDeOrigen::asignar($lote->id, [['fila' => 15], [], ['fila' => 17]]), 'fila ausente');
        $this->assertSame([2, 3, 4], $filasGuardadas(), 'ante un fallo no se cambió nada');

        // Líneas importadas que no son 2…n+1.
        DB::table('cf_participantes')->where('lote_id', $lote->id)->where('fila_origen', 4)->update(['fila_origen' => 7]);
        $esperaFallo(fn () => FilasDeOrigen::asignar($lote->id, $revisadas([15, 16, 17])), 'línea fuera de lo esperado');
        DB::table('cf_participantes')->where('lote_id', $lote->id)->where('fila_origen', 7)->update(['fila_origen' => 3]);
        $esperaFallo(fn () => FilasDeOrigen::asignar($lote->id, $revisadas([15, 16, 17])), 'línea repetida');
        $this->assertSame([2, 3, 3], $filasGuardadas());

        // El caso correcto sí asigna, devuelve el id de cada posición y no toca nada más.
        DB::table('cf_participantes')->where('lote_id', $lote->id)->where('fila_origen', 3)->orderBy('id', 'desc')->limit(1)->update(['fila_origen' => 4]);
        $antes = DB::table('cf_participantes')->where('lote_id', $lote->id)->orderBy('id')->get(['id', 'nombre_completo', 'documento', 'elegibilidad', 'updated_at'])->map(fn ($r) => (array) $r)->all();
        $ids = FilasDeOrigen::asignar($lote->id, $revisadas([15, 16, 17]));
        $this->assertSame([15, 16, 17], $filasGuardadas());
        $this->assertSame(array_column($antes, 'id'), array_values($ids));
        $this->assertSame($antes, DB::table('cf_participantes')->where('lote_id', $lote->id)->orderBy('id')->get(['id', 'nombre_completo', 'documento', 'elegibilidad', 'updated_at'])->map(fn ($r) => (array) $r)->all(), 'solo cambió la fila de origen');
    }

    // ── El importador de siempre no cambia ────────────────────────────────────

    public function test_el_importador_tradicional_sigue_numerando_por_linea_del_archivo(): void
    {
        $csv = "nombre_completo,tipo_documento,documento,correo,elegibilidad\nANA PRUEBA,C.C.,1111111,ana@example.test,pendiente\nBETO PRUEBA,C.C.,2222222,beto@example.test,pendiente\nCARLA PRUEBA,C.C.,3333333,carla@example.test,pendiente\n";
        $archivo = UploadedFile::fake()->createWithContent('participantes.csv', $csv);

        $lote = $this->actingAs($this->admin())->app->make(ImportadorParticipantes::class)->importar($archivo, $this->plantilla, 'Base tradicional', null, ['evento' => 'Evento de prueba', 'fecha' => '16 DE OCTUBRE DE 2026', 'intensidad_horaria' => '5 HORAS']);

        $this->assertSame([2, 3, 4], $lote->participantes()->orderBy('id')->pluck('fila_origen')->map(fn ($f) => (int) $f)->all(), 'el importador de siempre sigue numerando por línea (encabezado = 1)');
    }

    public function test_el_cambio_vive_solo_en_la_importacion_inteligente(): void
    {
        $importador = file_get_contents(app_path('Support/CredentialFlow/Participantes/ImportadorParticipantes.php'));
        $this->assertStringNotContainsString('FilasDeOrigen', $importador, 'el importador tradicional no se toca');
        $this->assertStringContainsString("'fila_origen' => \$p['fila']", $importador);
        $servicio = file_get_contents(app_path('Support/CredentialFlow/Importacion/ServicioImportaciones.php'));
        $this->assertSame(1, substr_count($servicio, 'FilasDeOrigen::asignar('));
    }
}
