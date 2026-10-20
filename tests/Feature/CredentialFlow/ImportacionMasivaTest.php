<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\ExcelSolicitud as X;

/**
 * F5: revisión y corrección masiva CONTROLADA del borrador (elegibilidad y tipo de documento en bloque). Todo ocurre en el BORRADOR: ninguna prueba de este archivo
 * debe ver una base, un participante, una emisión ni un correo hasta que el administrador confirma la importación (y entonces tampoco se emite nada).
 * Datos 100 % sintéticos. Fila del Excel = 14 + número de persona; la última persona es «Cupos» antes de la última, es decir la persona N-1.
 */
class ImportacionMasivaTest extends EmisionesTestCase
{
    private const CONTACTOS = [['QA UNO PRUEBA', 'C.C. 8.000.001', 'qa.uno@example.test'], ['QA DOS PRUEBA', 'C.C. 8.000.002', 'qa.dos@example.test'], ['QA TRES PRUEBA', 'C.C. 8.000.003', 'qa.tres@example.test']];

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
        foreach (array_slice(self::CONTACTOS, 0, $cuantos) as [$n, $d, $c]) {
            \DB::table('cf_contactos_qa')->insert(['nombre_completo' => $n, 'documento' => $d, 'documento_clave' => Texto::claveDocumento($d), 'correo' => $c, 'correo_normalizado' => $c, 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    private function analizar(?string $bytes = null): int
    {
        return $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('solicitud.xlsx', $bytes ?? X::bytes())], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
    }

    /** Deja listo lo que NO es de F5 (plantilla, evento, confirmaciones) para poder crear la base al final. */
    private function decidir(int $id, array $extra = []): array
    {
        return $this->corregir($id, array_replace([
            'plantilla_id' => $this->plantilla->id, 'evento' => ['decision' => 'asociar', 'evento_id' => 631],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria', 'modalidad'], 'valores_desactualizados' => true],
        ], $extra))->assertOk()->json('borrador');
    }

    private function corregir(int $id, array $cuerpo): TestResponse
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cuerpo);
    }

    private function borrador(int $id): array
    {
        return $this->actingAs($this->admin())->getJson(route('credential-flow.importaciones.show', $id))->assertOk()->json('borrador');
    }

    /** @return array<int,array<string,mixed>> participantes del borrador por fila del Excel */
    private function personas(int $id): array
    {
        return collect($this->borrador($id)['participantes'])->keyBy('fila')->all();
    }

    /** @return list<int> filas cuya asistencia en el Excel es la indicada */
    private function filasPorSenal(int $id, string $senal): array
    {
        return array_values(array_map(fn ($p) => $p['fila'], array_filter($this->personas($id), fn ($p) => $p['senal'] === $senal)));
    }

    private function previa(int $id, string $campo, ?string $valor, array $filas, ?int $version = null, $usuario = null): TestResponse
    {
        return $this->actingAs($usuario ?? $this->admin())->postJson(route('credential-flow.importaciones.masivas.vista-previa', $id), [
            'version' => $version ?? Importacion::findOrFail($id)->version, 'campo' => $campo, 'valor' => $valor, 'filas' => $filas,
        ]);
    }

    /** Pide la vista previa y aplica EXACTAMENTE eso, como hace la pantalla. */
    private function aplicar(int $id, string $campo, string $valor, array $filas, array $extra = [], ?string $operacion = null): TestResponse
    {
        $version = Importacion::findOrFail($id)->version;
        $previa = $this->previa($id, $campo, $valor, $filas, $version)->assertOk()->json('vista_previa');

        return $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), [
            'version' => $version, 'operacion_id' => $operacion ?? (string) Str::uuid(), 'huella' => $previa['huella'], 'campo' => $campo, 'valor' => $valor, 'filas' => $filas,
        ] + $extra);
    }

    private function deshacer(int $id, string $operacion, ?int $version = null): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.deshacer', [$id, $operacion]), ['version' => $version ?? Importacion::findOrFail($id)->version]);
    }

    private function confirmar(int $id): TestResponse
    {
        return $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true]);
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return ['lotes' => Lote::withTrashed()->count(), 'participantes' => Participante::withTrashed()->count(), 'eventos' => EventoCertificacion::withTrashed()->count(), 'emisiones' => Emision::count(), 'correos' => (int) \DB::table('cf_correos')->count()];
    }

    // ── El caso de 17 personas (16 «Completo», 1 «Cupos», 17 con el tipo pendiente) ──

    public function test_el_caso_de_17_personas_se_revisa_en_una_sola_operacion_sin_inventar_nada(): void
    {
        Mail::fake();
        $this->sembrarQa();
        $id = $this->analizar();
        $completos = $this->filasPorSenal($id, 'completo');
        $cupos = $this->filasPorSenal($id, 'cupo');
        $this->assertCount(16, $completos, 'filtrar «Completo» selecciona exactamente 16');
        $this->assertCount(1, $cupos);
        $this->assertSame(17, collect($this->personas($id))->filter(fn ($p) => $p['tipo_documento'] === null)->count(), 'los 17 con el tipo sin confirmar');
        $this->assertSame(17, collect($this->personas($id))->where('elegibilidad', 'pendiente')->count(), 'nadie es elegible solo');
        $antes = $this->conteos();

        // Vista previa: cuántas personas, estado anterior y propuesto, y NADA se guarda.
        $v = Importacion::findOrFail($id)->version;
        $p = $this->previa($id, 'elegibilidad', 'elegible', $completos)->assertOk()->json('vista_previa');
        $this->assertSame([16, 16, 0, 0, 0], [$p['seleccionados'], $p['se_modifican'], $p['sin_cambio'], $p['omitidos_total'], $p['sobrescriben']]);
        $this->assertTrue($p['requiere_verificacion']);
        $this->assertSame(['pendiente', 'elegible'], [$p['cambios'][0]['anterior'], $p['cambios'][0]['propuesto']]);
        $this->assertSame(16, $p['sin_tipo_de_documento'], 'quedarían elegibles pero sin poder emitirse hasta confirmar su tipo');
        $this->assertSame($v, Importacion::findOrFail($id)->version, 'la vista previa no sube la versión');
        $this->assertSame(17, collect($this->personas($id))->where('elegibilidad', 'pendiente')->count(), 'ni guarda nada');

        // Sin la verificación explícita de la asistencia no se aplica.
        $this->aplicar($id, 'elegibilidad', 'elegible', $completos)->assertStatus(422)->assertJsonPath('error.code', 'ACCION_MASIVA_SIN_VERIFICACION');
        $this->assertSame($v, Importacion::findOrFail($id)->version);

        $r = $this->aplicar($id, 'elegibilidad', 'elegible', $completos, ['verificado' => true])->assertOk();
        $this->assertSame(['elegibilidad', 'elegible', 16, 0, 0], [$r->json('resultado.campo'), $r->json('resultado.valor'), $r->json('resultado.aplicados'), $r->json('resultado.omitidos'), $r->json('resultado.sobrescritos')]);
        $this->assertSame($v + 1, $r->json('borrador.version'));

        $personas = $this->personas($id);
        $this->assertSame(16, collect($personas)->where('elegibilidad', 'elegible')->count());
        $this->assertSame('pendiente', $personas[$cupos[0]]['elegibilidad'], '«Cupos» sigue pendiente');
        $this->assertSame(17, collect($personas)->filter(fn ($p) => $p['tipo_documento'] === null)->count(), 'el tipo de documento sigue sin confirmar: nadie lo asignó');
        $this->assertSame('elegibilidad', $r->json('borrador.historial.0.campo'));
        $this->assertTrue($r->json('borrador.historial.0.deshacible'));
        $this->assertSame($antes, $this->conteos(), 'aplicar al borrador no crea nada');

        // Los pendientes para emitir quedan separados de lo que impide crear la base.
        $e = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $this->decidir($id);
        $e = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $this->assertTrue($e['listo']);
        $this->assertSame(['tipo_documento' => 17, 'elegibilidad' => 1, 'no_elegibles' => 0, 'asistencia_cupo' => 1], $e['pendientes_para_emitir']);

        // Crear la base después de las revisiones: 16 elegibles + «Cupos» pendiente + QA, y no se emite nada.
        $lote = Lote::findOrFail($this->confirmar($id)->assertCreated()->json('lote_id'));
        $reales = $lote->participantes()->where('es_control_qa', false)->get();
        $this->assertSame([17, 16, 1, 0], [$reales->count(), $reales->where('elegibilidad', Participante::ELEGIBLE)->count(), $reales->where('elegibilidad', Participante::PENDIENTE)->count(), $reales->where('tipo_documento_confirmado', true)->count()]);
        $this->assertSame(3, $lote->participantes()->where('es_control_qa', true)->count());
        $this->assertSame(0, Emision::count());
        Mail::assertNothingSent();
        Mail::assertNothingQueued();
    }

    public function test_la_elegibilidad_de_los_qa_no_cambia_por_las_acciones_masivas(): void
    {
        $this->sembrarQa();

        // Base de referencia: sin ninguna acción masiva.
        $a = $this->analizar();
        $this->decidir($a);
        $baseA = Lote::findOrFail($this->confirmar($a)->assertCreated()->json('lote_id'));
        $qaA = $baseA->participantes()->where('es_control_qa', true)->orderBy('documento_clave')->pluck('elegibilidad', 'documento_clave')->all();

        // Otra base con acciones masivas sobre TODAS las personas del borrador.
        $b = $this->analizar(X::bytes(17, ['documento_base' => 2000000]));
        $this->decidir($b);
        $this->aplicar($b, 'elegibilidad', 'no_elegible', array_keys($this->personas($b)))->assertOk();
        $this->aplicar($b, 'tipo_documento', 'C.C.', array_keys($this->personas($b)))->assertOk();
        $baseB = Lote::findOrFail($this->confirmar($b)->assertCreated()->json('lote_id'));
        $qaB = $baseB->participantes()->where('es_control_qa', true)->orderBy('documento_clave')->pluck('elegibilidad', 'documento_clave')->all();

        $this->assertCount(3, $qaB);
        $this->assertSame(array_values($qaA), array_values($qaB), 'los QA quedan exactamente como siempre');
        $this->assertSame(17, $baseB->participantes()->where('es_control_qa', false)->where('elegibilidad', Participante::NO_ELEGIBLE)->count());
    }

    // ── Cupos y la asistencia que no está clara ──────────────────────────────

    public function test_cupos_no_se_vuelve_elegible_en_bloque_ni_junto_con_los_completo(): void
    {
        $id = $this->analizar();
        $todos = array_keys($this->personas($id));
        $cupo = $this->filasPorSenal($id, 'cupo')[0];

        // Aunque se seleccione a todos, «Cupos» se omite y se informa; los demás sí cambian.
        $p = $this->previa($id, 'elegibilidad', 'elegible', $todos)->assertOk()->json('vista_previa');
        $this->assertSame([17, 16, 1], [$p['seleccionados'], $p['se_modifican'], $p['omitidos_total']]);
        $this->assertSame([['fila' => $cupo, 'motivo' => 'cupos']], array_map(fn ($o) => ['fila' => $o['fila'], 'motivo' => $o['motivo']], $p['omitidos']));
        $this->assertSame(['cupos' => 1], $p['omitidos_por_motivo']);

        $this->aplicar($id, 'elegibilidad', 'elegible', $todos, ['verificado' => true])->assertOk();
        $this->assertSame('pendiente', $this->personas($id)[$cupo]['elegibilidad']);

        // Seleccionar solo «Cupos» no cambia nada: no hay nada que aplicar.
        $id2 = $this->analizar(X::bytes(17, ['documento_base' => 3000000]));
        $cupo2 = $this->filasPorSenal($id2, 'cupo');
        $this->previa($id2, 'elegibilidad', 'elegible', $cupo2)->assertOk()->assertJsonPath('vista_previa.se_modifican', 0);
        $this->aplicar($id2, 'elegibilidad', 'elegible', $cupo2, ['verificado' => true])->assertStatus(422)->assertJsonPath('error.code', 'ACCION_MASIVA_SIN_CAMBIOS');

        // Dejarlo en «pendiente» o «no elegible» sí se permite (no es aprobarlo).
        $this->aplicar($id2, 'elegibilidad', 'no_elegible', $cupo2)->assertOk();
        $this->assertSame('no_elegible', $this->personas($id2)[$cupo2[0]]['elegibilidad']);
    }

    public function test_la_asistencia_contradictoria_o_desconocida_tampoco_se_aprueba_en_bloque(): void
    {
        $id = $this->analizar();
        // Se fuerza en el borrador cifrado una persona con señal «conflicto» y otra «desconocida» (como las que produce el analizador).
        $imp = Importacion::findOrFail($id);
        $analisis = $imp->analisis;
        $analisis['tabla']['participantes'][0]['senal'] = 'conflicto';
        $analisis['tabla']['participantes'][1]['senal'] = 'desconocida';
        $imp->update(['analisis' => $analisis]);
        $filas = [$analisis['tabla']['participantes'][0]['fila'], $analisis['tabla']['participantes'][1]['fila'], $analisis['tabla']['participantes'][2]['fila']];

        $p = $this->previa($id, 'elegibilidad', 'elegible', $filas)->assertOk()->json('vista_previa');

        $this->assertSame(1, $p['se_modifican']);
        $this->assertSame(['asistencia_sin_aclarar' => 2], $p['omitidos_por_motivo']);
    }

    // ── Exclusiones: QA, excluidos y lo que no se toca ───────────────────────

    public function test_los_excluidos_y_las_personas_parecidas_a_un_contacto_qa_no_se_tocan(): void
    {
        $this->sembrarQa();
        $id = $this->analizar(X::bytes(17, ['correos' => [5 => 'qa.uno@example.test']]));   // fila 19 con el correo de un contacto QA
        $personas = $this->personas($id);
        $this->assertSame('correo', $personas[19]['posible_qa']);
        $this->corregir($id, ['participantes' => ['20' => ['excluir' => true]]])->assertOk();

        $p = $this->previa($id, 'elegibilidad', 'elegible', [19, 20, 21])->assertOk()->json('vista_previa');

        $this->assertSame(1, $p['se_modifican']);
        $this->assertSame([21], array_column($p['cambios'], 'fila'));
        $motivos = collect($p['omitidos'])->pluck('motivo', 'fila')->all();
        $this->assertSame(['posible_qa', 'excluida'], [$motivos[19], $motivos[20]]);

        $this->aplicar($id, 'tipo_documento', 'C.C.', [19, 20, 21])->assertOk();
        $personas = $this->personas($id);
        $this->assertSame([null, null, 'C.C.'], [$personas[19]['tipo_documento'], $personas[20]['tipo_documento'], $personas[21]['tipo_documento']]);
        $this->assertSame('pendiente', $personas[19]['elegibilidad']);
    }

    // ── Tipo de documento ────────────────────────────────────────────────────

    public function test_el_tipo_de_documento_se_elige_a_mano_nunca_se_asume_ni_se_infiere(): void
    {
        $id = $this->analizar();
        $todos = array_keys($this->personas($id));

        foreach ([null, '', '   ', 'Licencia', 'CC o algo'] as $valor) {
            $this->previa($id, 'tipo_documento', $valor, $todos)->assertStatus(422)->assertJsonPath('error.code', 'ACCION_MASIVA_INVALIDA');
        }
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.vista-previa', $id), ['version' => 1, 'campo' => 'tipo_documento', 'filas' => $todos])->assertStatus(422);
        $this->assertSame(17, collect($this->personas($id))->filter(fn ($p) => $p['tipo_documento'] === null)->count(), 'nadie recibe C.C. por defecto');

        // Aplicar a algunas personas deja a las demás exactamente como estaban: no se infiere por el número del documento.
        $this->aplicar($id, 'tipo_documento', 'cédula de ciudadanía', [15, 16, 17])->assertOk();
        $personas = $this->personas($id);
        $this->assertSame(['C.C.', 'C.C.', 'C.C.', null], [$personas[15]['tipo_documento'], $personas[16]['tipo_documento'], $personas[17]['tipo_documento'], $personas[18]['tipo_documento']]);
        $this->assertSame(14, collect($personas)->filter(fn ($p) => $p['tipo_documento'] === null)->count());
    }

    public function test_los_documentos_originales_no_se_alteran_y_los_ceros_iniciales_se_conservan(): void
    {
        $id = $this->analizar(X::bytes(17, ['documentos' => [1 => '0012345678', 2 => '00987']]));
        $antes = $this->personas($id);
        $this->assertSame('0012345678', $antes[15]['documento']);

        $this->aplicar($id, 'tipo_documento', 'PASAPORTE', [15, 16])->assertOk();
        $this->aplicar($id, 'elegibilidad', 'elegible', [15, 16], ['verificado' => true])->assertOk();

        $despues = $this->personas($id);
        $this->assertSame(['0012345678', '00987'], [$despues[15]['documento'], $despues[16]['documento']]);
        $this->assertSame($antes[15]['originales'], $despues[15]['originales'], 'lo que decía el Excel no cambia');
        $this->assertSame($antes[15]['nombre_completo'], $despues[15]['nombre_completo']);
        $this->assertSame($antes[15]['correo'], $despues[15]['correo']);
    }

    public function test_reemplazar_un_valor_existente_exige_una_confirmacion_adicional(): void
    {
        $id = $this->analizar();
        $this->corregir($id, ['participantes' => ['15' => ['tipo_documento' => 'C.E.'], '16' => ['tipo_documento' => 'C.C.']]])->assertOk();

        $p = $this->previa($id, 'tipo_documento', 'C.C.', [15, 16, 17])->assertOk()->json('vista_previa');
        $this->assertSame([2, 1, 1, 1], [$p['se_modifican'], $p['sobrescriben'], $p['sin_cambio'], 0 + count(array_filter($p['cambios'], fn ($c) => $c['sobrescribe']))]);
        $this->assertTrue($p['requiere_confirmar_sobrescritura']);
        $this->assertSame(['C.E.', 'C.C.'], [$p['cambios'][0]['anterior'], $p['cambios'][0]['propuesto']]);
        $version = Importacion::findOrFail($id)->version;

        $this->aplicar($id, 'tipo_documento', 'C.C.', [15, 16, 17])->assertStatus(422)->assertJsonPath('error.code', 'ACCION_MASIVA_SOBRESCRITURA_SIN_CONFIRMAR');
        $this->assertSame($version, Importacion::findOrFail($id)->version, 'sin la confirmación no se tocó nada');
        $this->assertSame('C.E.', $this->personas($id)[15]['tipo_documento']);

        $this->aplicar($id, 'tipo_documento', 'C.C.', [15, 16, 17], ['confirmar_sobrescritura' => true])->assertOk()->assertJsonPath('resultado.sobrescritos', 1);
        $this->assertSame('C.C.', $this->personas($id)[15]['tipo_documento']);

        // Cambiar una elegibilidad ya decidida también cuenta como reemplazo.
        $this->aplicar($id, 'elegibilidad', 'elegible', [15], ['verificado' => true])->assertOk();
        $this->aplicar($id, 'elegibilidad', 'no_elegible', [15])->assertStatus(422)->assertJsonPath('error.code', 'ACCION_MASIVA_SOBRESCRITURA_SIN_CONFIRMAR');
    }

    // ── Atomicidad, versiones y reintentos ───────────────────────────────────

    public function test_una_seleccion_con_una_fila_que_no_existe_se_rechaza_completa(): void
    {
        $id = $this->analizar();
        $version = Importacion::findOrFail($id)->version;

        foreach ([[15, 999], [15, 'x'], [], ['15', 16.5]] as $filas) {
            $this->previa($id, 'elegibilidad', 'pendiente', $filas)->assertStatus(422)->assertJsonPath('error.code', 'ACCION_MASIVA_INVALIDA');
        }
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), [
            'version' => $version, 'operacion_id' => (string) Str::uuid(), 'huella' => str_repeat('a', 64), 'campo' => 'tipo_documento', 'valor' => 'C.C.', 'filas' => [15, 999],
        ])->assertStatus(422);

        $this->assertSame($version, Importacion::findOrFail($id)->version);
        $this->assertSame(17, collect($this->personas($id))->filter(fn ($p) => $p['tipo_documento'] === null)->count());
    }

    public function test_si_el_borrador_cambio_despues_de_la_vista_previa_no_se_aplica_nada(): void
    {
        $id = $this->analizar();
        $v = Importacion::findOrFail($id)->version;
        $previa = $this->previa($id, 'tipo_documento', 'C.C.', [15, 16], $v)->assertOk()->json('vista_previa');

        // Otra pestaña edita el borrador entre la vista previa y «Aplicar».
        $this->corregir($id, ['participantes' => ['15' => ['nombre_completo' => 'NOMBRE NUEVO']]])->assertOk();

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), [
            'version' => $v, 'operacion_id' => (string) Str::uuid(), 'huella' => $previa['huella'], 'campo' => 'tipo_documento', 'valor' => 'C.C.', 'filas' => [15, 16],
        ])->assertStatus(409);
        $this->assertSame('BORRADOR_DESACTUALIZADO', $r->json('error.code'));
        $this->assertNull($this->personas($id)[15]['tipo_documento']);
        $this->assertSame('NOMBRE NUEVO', $this->personas($id)[15]['nombre_completo'], 'el cambio nuevo se conserva');
    }

    public function test_la_huella_debe_ser_la_de_lo_que_se_reviso(): void
    {
        $id = $this->analizar();
        $v = Importacion::findOrFail($id)->version;
        $previa = $this->previa($id, 'tipo_documento', 'C.C.', [15, 16], $v)->assertOk()->json('vista_previa');
        $envio = fn (array $c) => $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), $c + [
            'version' => $v, 'operacion_id' => (string) Str::uuid(), 'huella' => $previa['huella'], 'campo' => 'tipo_documento', 'valor' => 'C.C.', 'filas' => [15, 16],
        ]);

        // Se revisó C.C. para 15 y 16: cambiar el valor o la selección invalida la revisión.
        $envio(['valor' => 'C.E.'])->assertStatus(409)->assertJsonPath('error.code', 'ACCION_MASIVA_VISTA_PREVIA_OBSOLETA');
        $envio(['filas' => [15, 16, 17]])->assertStatus(409)->assertJsonPath('error.code', 'ACCION_MASIVA_VISTA_PREVIA_OBSOLETA');
        $envio(['huella' => str_repeat('0', 64)])->assertStatus(409)->assertJsonPath('error.code', 'ACCION_MASIVA_VISTA_PREVIA_OBSOLETA');
        $envio(['huella' => 'corta'])->assertStatus(422);
        $this->assertSame($v, Importacion::findOrFail($id)->version);

        $envio([])->assertOk();
    }

    public function test_el_doble_clic_y_los_reintentos_no_aplican_dos_veces(): void
    {
        $id = $this->analizar();
        $operacion = (string) Str::uuid();
        $v = Importacion::findOrFail($id)->version;

        $primera = $this->aplicar($id, 'tipo_documento', 'C.C.', [15, 16], [], $operacion)->assertOk();
        $this->assertFalse($primera->json('repetida'));
        $this->assertSame($v + 1, Importacion::findOrFail($id)->version);

        // El mismo envío otra vez (con la versión vieja, como un reintento de red): devuelve el borrador tal como quedó, sin aplicar nada más.
        $huella = $primera->json('borrador.historial.0.id');
        $this->assertSame($operacion, $huella);
        $repetida = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), [
            'version' => $v, 'operacion_id' => $operacion, 'huella' => str_repeat('b', 64), 'campo' => 'tipo_documento', 'valor' => 'C.C.', 'filas' => [15, 16],
        ])->assertOk();
        $this->assertTrue($repetida->json('repetida'));
        $this->assertNull($repetida->json('resultado'));
        $this->assertSame($v + 1, $repetida->json('borrador.version'));
        $this->assertCount(1, $repetida->json('borrador.historial'));
        $this->assertSame(1, Movimiento::query()->where('descripcion', 'like', 'Se aplicó un cambio masivo%')->count());

        // Otra operación nueva con la versión vieja sí se rechaza.
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), [
            'version' => $v, 'operacion_id' => (string) Str::uuid(), 'huella' => $primera->json('borrador.historial.0.id') ? str_repeat('c', 64) : '', 'campo' => 'tipo_documento', 'valor' => 'C.E.', 'filas' => [17],
        ])->assertStatus(409)->assertJsonPath('error.code', 'BORRADOR_DESACTUALIZADO');
    }

    public function test_dos_aplicaciones_con_la_misma_version_solo_deja_pasar_a_la_primera(): void
    {
        $id = $this->analizar();
        $v = Importacion::findOrFail($id)->version;
        $a = $this->previa($id, 'tipo_documento', 'C.C.', [15], $v)->assertOk()->json('vista_previa');
        $b = $this->previa($id, 'tipo_documento', 'C.E.', [16], $v)->assertOk()->json('vista_previa');
        $cuerpo = fn (array $p, string $valor, int $fila) => ['version' => $v, 'operacion_id' => (string) Str::uuid(), 'huella' => $p['huella'], 'campo' => 'tipo_documento', 'valor' => $valor, 'filas' => [$fila]];

        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), $cuerpo($a, 'C.C.', 15))->assertOk();
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), $cuerpo($b, 'C.E.', 16))->assertStatus(409)->assertJsonPath('error.code', 'BORRADOR_DESACTUALIZADO');

        $personas = $this->personas($id);
        $this->assertSame(['C.C.', null], [$personas[15]['tipo_documento'], $personas[16]['tipo_documento']]);
    }

    // ── Deshacer ─────────────────────────────────────────────────────────────

    public function test_se_puede_deshacer_la_ultima_accion_y_el_borrador_vuelve_a_como_estaba(): void
    {
        $id = $this->analizar();
        $completos = $this->filasPorSenal($id, 'completo');
        $antes = $this->personas($id);

        $uno = (string) Str::uuid();
        $dos = (string) Str::uuid();
        $this->aplicar($id, 'elegibilidad', 'elegible', $completos, ['verificado' => true], $uno)->assertOk();
        $this->aplicar($id, 'tipo_documento', 'C.C.', [15, 16], [], $dos)->assertOk();

        // Solo la última se puede deshacer.
        $this->deshacer($id, $uno)->assertStatus(409)->assertJsonPath('error.code', 'ACCION_MASIVA_NO_SE_PUEDE_DESHACER');
        $this->assertSame('elegible', $this->personas($id)[15]['elegibilidad']);

        $r = $this->deshacer($id, $dos)->assertOk();
        $this->assertSame([null, 'elegible'], [$this->personas($id)[15]['tipo_documento'], $this->personas($id)[15]['elegibilidad']]);
        $this->assertSame(['deshecha', 'aplicada'], [$r->json('borrador.historial.1.estado'), $r->json('borrador.historial.0.estado')]);
        $this->deshacer($id, $dos)->assertStatus(409);   // ya estaba deshecha

        $this->deshacer($id, $uno)->assertOk();
        $despues = $this->personas($id);
        foreach ($antes as $fila => $p) {
            $this->assertSame([$p['elegibilidad'], $p['tipo_documento'], $p['corregidos']], [$despues[$fila]['elegibilidad'], $despues[$fila]['tipo_documento'], $despues[$fila]['corregidos']], "fila $fila vuelve a como estaba");
        }
        $this->assertSame([], Importacion::findOrFail($id)->correcciones['participantes'], 'no queda ninguna corrección residual');
    }

    public function test_deshacer_devuelve_el_valor_que_habia_antes_incluso_si_ya_habia_una_decision(): void
    {
        $id = $this->analizar();
        $this->corregir($id, ['participantes' => ['15' => ['tipo_documento' => 'C.E.']]])->assertOk();
        $operacion = (string) Str::uuid();
        $this->aplicar($id, 'tipo_documento', 'PASAPORTE', [15, 16], ['confirmar_sobrescritura' => true], $operacion)->assertOk();

        $this->deshacer($id, $operacion)->assertOk();

        $personas = $this->personas($id);
        $this->assertSame(['C.E.', null], [$personas[15]['tipo_documento'], $personas[16]['tipo_documento']]);
    }

    public function test_no_se_deshace_si_alguien_cambio_a_esas_personas_despues(): void
    {
        $id = $this->analizar();
        $operacion = (string) Str::uuid();
        $this->aplicar($id, 'tipo_documento', 'C.C.', [15, 16, 17], [], $operacion)->assertOk();
        $this->corregir($id, ['participantes' => ['16' => ['tipo_documento' => 'NIT']]])->assertOk();

        $r = $this->deshacer($id, $operacion)->assertStatus(409);

        $this->assertSame('ACCION_MASIVA_NO_SE_PUEDE_DESHACER', $r->json('error.code'));
        $personas = $this->personas($id);
        $this->assertSame(['C.C.', 'NIT', 'C.C.'], [$personas[15]['tipo_documento'], $personas[16]['tipo_documento'], $personas[17]['tipo_documento']], 'no se tocó a nadie, ni se pisó el cambio posterior');
        $this->assertSame('aplicada', $this->borrador($id)['historial'][0]['estado']);
    }

    public function test_el_historial_conserva_solo_las_ultimas_acciones(): void
    {
        $id = $this->analizar();
        foreach (range(1, 22) as $i) {
            $this->aplicar($id, 'elegibilidad', $i % 2 ? 'no_elegible' : 'pendiente', [15], ['confirmar_sobrescritura' => true])->assertOk();
        }

        $this->assertCount(20, $this->borrador($id)['historial']);
    }

    // ── Privacidad y permisos ────────────────────────────────────────────────

    public function test_las_acciones_masivas_son_privadas_y_dependen_de_la_bandera(): void
    {
        $id = $this->analizar();
        $dueno = $this->admin();
        $extra = $this->superAdmin();   // ni siquiera un super-administrador ve un borrador ajeno
        $v = Importacion::findOrFail($id)->version;
        $cuerpo = ['version' => $v, 'campo' => 'tipo_documento', 'valor' => 'C.C.', 'filas' => [15]];

        $r = $this->actingAs($extra)->postJson(route('credential-flow.importaciones.masivas.vista-previa', $id), $cuerpo)->assertNotFound();
        $this->assertStringNotContainsString('PERSONA', $r->getContent());
        $this->actingAs($extra)->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), $cuerpo + ['operacion_id' => (string) Str::uuid(), 'huella' => str_repeat('a', 64)])->assertNotFound();
        $this->actingAs($extra)->postJson(route('credential-flow.importaciones.masivas.deshacer', [$id, (string) Str::uuid()]), ['version' => $v])->assertNotFound();
        $this->assertSame($v, Importacion::findOrFail($id)->version);

        config(['credential_flow.importacion_inteligente.activa' => false]);
        $this->actingAs($dueno)->postJson(route('credential-flow.importaciones.masivas.vista-previa', $id), $cuerpo)->assertNotFound();
        $this->actingAs($dueno)->postJson(route('credential-flow.importaciones.masivas.aplicar', $id), $cuerpo)->assertNotFound();
        config(['credential_flow.importacion_inteligente.activa' => true]);

        auth()->logout();
        $this->postJson(route('credential-flow.importaciones.masivas.vista-previa', $id), $cuerpo)->assertUnauthorized();
    }

    public function test_el_registro_y_el_almacenamiento_no_exponen_datos_personales(): void
    {
        $id = $this->analizar();
        $this->aplicar($id, 'elegibilidad', 'elegible', $this->filasPorSenal($id, 'completo'), ['verificado' => true])->assertOk();
        $this->aplicar($id, 'tipo_documento', 'C.C.', [15, 16])->assertOk();

        // Registro de movimientos: solo cantidades.
        $registro = Movimiento::query()->get()->toJson(JSON_UNESCAPED_UNICODE);
        foreach (['PERSONA', 'example.test', '1000001', 'Gestora'] as $dato) {
            $this->assertStringNotContainsString($dato, $registro, "el registro no debe contener «{$dato}»");
        }
        $this->assertSame(2, Movimiento::query()->where('descripcion', 'like', 'Se aplicó un cambio masivo%')->count());

        // Almacenamiento: el borrador sigue cifrado (ni nombres ni estados en claro).
        $crudo = (string) \DB::table('cf_importaciones')->where('id', $id)->value('correcciones');
        foreach (['PERSONA', 'elegible', 'C.C.', 'tipo_documento', 'historial'] as $dato) {
            $this->assertStringNotContainsString($dato, $crudo);
        }

        // Los errores tampoco cuentan quién es quién.
        $r = $this->previa($id, 'tipo_documento', 'Licencia', [15])->assertStatus(422);
        $this->assertStringNotContainsString('PERSONA', $r->getContent());
    }

    public function test_la_vista_previa_no_guarda_nada_ni_deja_rastro_en_el_registro(): void
    {
        $id = $this->analizar();
        $antes = [Importacion::findOrFail($id)->updated_at, Importacion::findOrFail($id)->version, Movimiento::count(), $this->conteos()];

        foreach (range(1, 3) as $i) {
            $this->previa($id, 'elegibilidad', 'elegible', $this->filasPorSenal($id, 'completo'))->assertOk();
        }

        $this->assertEquals($antes, [Importacion::findOrFail($id)->updated_at, Importacion::findOrFail($id)->version, Movimiento::count(), $this->conteos()]);
    }

    public function test_la_correccion_masiva_antigua_ya_no_puede_aprobar_ni_asignar_tipos(): void
    {
        $id = $this->analizar();
        $v = Importacion::findOrFail($id)->version;

        foreach ([['elegibilidad' => 'elegible'], ['tipo_documento' => 'C.C.'], ['elegibilidad' => 'pendiente', 'excluir' => false]] as $campos) {
            $r = $this->corregir($id, ['masivas' => [['filas' => 'todas', 'senal' => 'completo', 'campos' => $campos]]])->assertStatus(422);
            $this->assertSame('CORRECCION_INVALIDA', $r->json('error.code'));
        }
        $this->assertSame($v, Importacion::findOrFail($id)->version);
        $this->assertSame(0, collect($this->personas($id))->where('elegibilidad', 'elegible')->count());

        // Lo que sí sigue disponible en bloque: excluir e intensidad individual (no deciden quién certifica).
        $this->corregir($id, ['masivas' => [['filas' => [15, 16], 'campos' => ['intensidad_horaria' => '3 HORAS']]]])->assertOk();
    }

    // ── Conjuntos grandes ────────────────────────────────────────────────────

    public function test_120_personas_con_filtros_paginas_y_acciones_sucesivas(): void
    {
        $id = $this->analizar(X::bytes(120));
        $personas = $this->personas($id);
        $completos = $this->filasPorSenal($id, 'completo');
        $this->assertCount(120, $personas);
        $this->assertCount(119, $completos);

        // Una «página» a la vez (la selección se arma entre páginas) y luego el resto de los que cumplen el filtro.
        $primera = array_slice($completos, 0, 50);
        $resto = array_slice($completos, 50);
        $a = (string) Str::uuid();
        $this->aplicar($id, 'elegibilidad', 'elegible', $primera, ['verificado' => true], $a)->assertOk()->assertJsonPath('resultado.aplicados', 50);
        $this->aplicar($id, 'elegibilidad', 'elegible', array_merge($primera, $resto), ['verificado' => true])->assertOk()->assertJsonPath('resultado.aplicados', 69);

        $personas = $this->personas($id);
        $this->assertSame(119, collect($personas)->where('elegibilidad', 'elegible')->count());
        $cupo = $this->filasPorSenal($id, 'cupo')[0];
        $this->assertSame('pendiente', $personas[$cupo]['elegibilidad']);

        // Los que ya tenían tipo no se pisan por accidente: solo los 100 últimos reciben el tipo y los 20 primeros (con otro) piden confirmación.
        $filas = array_keys($personas);
        $this->aplicar($id, 'tipo_documento', 'C.E.', array_slice($filas, 0, 20))->assertOk();
        $r = $this->aplicar($id, 'tipo_documento', 'C.C.', $filas)->assertStatus(422);
        $this->assertSame('ACCION_MASIVA_SOBRESCRITURA_SIN_CONFIRMAR', $r->json('error.code'));
        $this->aplicar($id, 'tipo_documento', 'C.C.', $filas, ['confirmar_sobrescritura' => true])->assertOk()->assertJsonPath('resultado.sobrescritos', 20);
        $this->assertSame(120, collect($this->personas($id))->where('tipo_documento', 'C.C.')->count());
    }

    public function test_300_personas_se_revisan_y_se_importan_sin_emitir_nada(): void
    {
        Mail::fake();
        $this->sembrarQa();
        $id = $this->analizar(X::bytes(300));
        $this->decidir($id);
        $completos = $this->filasPorSenal($id, 'completo');
        $this->assertCount(299, $completos);

        $p = $this->previa($id, 'elegibilidad', 'elegible', $completos)->assertOk()->json('vista_previa');
        $this->assertSame([299, 299], [$p['se_modifican'], count($p['cambios'])]);
        $this->aplicar($id, 'elegibilidad', 'elegible', $completos, ['verificado' => true])->assertOk();
        $this->aplicar($id, 'tipo_documento', 'C.C.', array_keys($this->personas($id)))->assertOk();

        $e = $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.validar', $id))->assertOk()->json();
        $this->assertSame(['tipo_documento' => 0, 'elegibilidad' => 1, 'no_elegibles' => 0, 'asistencia_cupo' => 1], $e['pendientes_para_emitir']);

        $lote = Lote::findOrFail($this->confirmar($id)->assertCreated()->json('lote_id'));
        $reales = $lote->participantes()->where('es_control_qa', false);
        $this->assertSame([300, 299], [(clone $reales)->count(), (clone $reales)->where('elegibilidad', Participante::ELEGIBLE)->count()]);
        $this->assertSame(0, Emision::count());
        Mail::assertNothingSent();
    }

    // ── Otras garantías ──────────────────────────────────────────────────────

    public function test_aplicar_cambios_al_borrador_no_es_importar_ni_emitir(): void
    {
        Mail::fake();
        $id = $this->analizar();
        $antes = $this->conteos();

        $this->aplicar($id, 'elegibilidad', 'elegible', $this->filasPorSenal($id, 'completo'), ['verificado' => true])->assertOk();
        $this->aplicar($id, 'tipo_documento', 'C.C.', array_keys($this->personas($id)))->assertOk();

        $this->assertSame($antes, $this->conteos());
        $this->assertSame('en_revision', Importacion::findOrFail($id)->estado);
        $this->assertNull(Importacion::findOrFail($id)->lote_id);
        Mail::assertNothingSent();
    }

    public function test_no_se_puede_aplicar_en_un_borrador_ya_confirmado_ni_vencido(): void
    {
        $id = $this->analizar();
        $this->decidir($id);
        $this->confirmar($id)->assertCreated();

        $this->previa($id, 'tipo_documento', 'C.C.', [15])->assertStatus(409)->assertJsonPath('error.code', 'BORRADOR_YA_CONFIRMADO');

        $id2 = $this->analizar(X::bytes(17, ['documento_base' => 4000000]));
        Importacion::findOrFail($id2)->update(['expira_at' => now()->subMinute()]);
        $this->previa($id2, 'tipo_documento', 'C.C.', [15])->assertStatus(410);
    }

    public function test_los_componentes_de_f5_no_insertan_html_ni_evaluan_codigo(): void
    {
        $dir = resource_path('js/Components/CredentialFlow/Importacion');
        $archivos = array_merge(glob($dir.'/*.vue'), [resource_path('js/Composables/CredentialFlow/importacionInteligente.js')]);
        foreach ($archivos as $f) {
            $codigo = file_get_contents($f);
            foreach (['v-html', 'innerHTML', 'outerHTML', 'insertAdjacentHTML', 'document.write', 'new Function', 'window.confirm =', 'confirm = () =>'] as $peligroso) {
                $this->assertStringNotContainsString($peligroso, $codigo, basename($f).': '.$peligroso);
            }
            $this->assertSame(0, preg_match('/(?<![\w.])eval\s*\(/', $codigo), basename($f).': eval()');
        }
    }

    public function test_la_pantalla_tiene_los_puntos_de_anclaje_de_las_acciones_masivas_y_las_rutas_de_la_api(): void
    {
        $leer = fn (string $f) => file_get_contents(resource_path('js/'.$f));
        $masivas = $leer('Components/CredentialFlow/Importacion/AccionesMasivas.vue');
        $tabla = $leer('Components/CredentialFlow/Importacion/TablaParticipantes.vue');
        $store = $leer('Composables/CredentialFlow/importacionInteligente.js');

        foreach (['data-barra-seleccion', 'data-seleccionar-visibles', 'data-seleccionar-filtro', 'data-quitar-seleccion', 'data-conteo-seleccion', 'data-panel-masivo', 'data-valor-masivo',
            'data-vista-masiva', 'data-confirmar-verificacion', 'data-confirmar-sobrescritura', 'data-resumen-seleccion', 'data-deshacer-masivo', 'data-omitidas-masivo', 'data-cancelar-masivo'] as $ancla) {
            $this->assertStringContainsString($ancla, $masivas, "falta $ancla");
        }
        foreach (['data-seleccionar', '<AccionesMasivas', 'id: "completo"', 'id: "seleccionadas"'] as $ancla) {
            $this->assertStringContainsString($ancla, $tabla, "falta $ancla en la tabla");
        }
        $personas = $leer('Components/CredentialFlow/Importacion/EtapaPersonas.vue');
        foreach (['Revisar cambios', 'Aplicar cambios al borrador', 'a.revisarMasiva()', 'a.aplicarMasiva()'] as $ancla) {
            $this->assertStringContainsString($ancla, $personas, "la acción principal de la etapa debe incluir $ancla");
        }
        foreach (['masivas.vista-previa', 'masivas.aplicar', 'masivas.deshacer', 'operacion_id', 'huella'] as $ancla) {
            $this->assertStringContainsString($ancla, $store, "el estado no usa $ancla");
        }
        // La pantalla nunca decide por su cuenta: no hay valores por defecto para el tipo ni la elegibilidad propuesta.
        $this->assertStringContainsString('<option value="">Elige un tipo…</option>', $masivas, 'el tipo de documento empieza sin elegir');
        $this->assertStringContainsString('masiva = reactive({ abierta: false, campo: null, valor: ""', $store);
    }
}
