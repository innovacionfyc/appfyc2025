<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use App\Support\CredentialFlow\Participantes\ErrorFila;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Programacion\ServicioProgramacion;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Testing\TestResponse;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;
use ZipArchive;

/**
 * Doble certificación por modalidad (DobleModalidad): SOLO en un evento autorizado expresamente, una misma persona puede tener una participación presencial y
 * otra virtual en la MISMA base, cada una con su certificado. Fuera de esos eventos todo sigue como siempre (un documento por base). Datos 100 % sintéticos.
 */
class DobleModalidadTest extends EmisionesTestCase
{
    private const ADVERTENCIA = 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA';

    private const EVENTO = 'Seminario sintético de gestión de cartera';

    private const DOBLE = 'ANA FICTICIA DOBLE';

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

        config(['credential_flow.importacion_inteligente.activa' => true, 'credential_flow.qa.contactos_esperados' => 8, 'credential_flow.emision.exige_vista_previa' => false]);
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad', 'intensidad_horaria'];
        $this->plantilla = $this->plantillaLista(array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 40 + $i * 50, 'height' => 30, 'fontSize' => 12]), $campos, array_keys($campos)));
        $this->evento = $this->nuevoEvento(self::EVENTO);
        for ($i = 1; $i <= 8; $i++) {
            $doc = 'C.C. 8.000.00'.$i;
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA {$i} PRUEBA", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function nuevoEvento(string $nombre): EventoCertificacion
    {
        return EventoCertificacion::create(['nombre' => $nombre, 'nombre_normalizado' => NombreConservador::normalizar($nombre), 'anio' => 2026,
            'fecha_texto' => '29 Y 30 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
    }

    private function autorizar(?EventoCertificacion $evento = null): void
    {
        config(['credential_flow.doble_modalidad.eventos' => (string) ($evento ?? $this->evento)->id]);   // el texto de la variable, como en el .env
    }

    /** Variante de PRUEBA INTERNA del diseño (la programación la exige). */
    private function varianteQa(): Plantilla
    {
        $qa = $this->plantillaLista([...$this->plantilla->diseno['elements'], $this->elemento(['type' => 'text', 'text' => self::ADVERTENCIA, 'y' => 380, 'fontSize' => 14])]);
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.qa', $qa), ['es_plantilla_qa' => true])->assertRedirect();
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.variante-qa', $this->plantilla), ['variante_qa_id' => $qa->id])->assertRedirect();

        return $qa->fresh();
    }

    /**
     * Excel sintético con la columna «Modalidad» por persona (tabla desde la fila 7).
     *
     * @param  list<array{0:string,1:string,2:string}>  $personas  [nombre, número de documento, modalidad]
     */
    private function excel(array $personas): string
    {
        $c = ['B2' => 'Nombre del Evento', 'D2' => self::EVENTO, 'B3' => 'Fecha del Evento', 'D3' => '29 y 30 de octubre de 2026', 'B4' => 'Intensidad de horas', 'D4' => '18 Horas'];
        foreach (['B' => 'Item', 'C' => 'Tipo de documento', 'D' => 'Número de identificación', 'E' => 'Nombres y Apellidos', 'F' => 'Correo Electrónico', 'G' => 'Modalidad'] as $col => $t) {
            $c[$col.'6'] = $t;
        }
        foreach ($personas as $i => [$nombre, $doc, $modalidad]) {
            $f = 7 + $i;
            $c['B'.$f] = $i + 1;
            $c['C'.$f] = 'C.C.';
            $c['D'.$f] = $doc;
            $c['E'.$f] = $nombre;
            $c['F'.$f] = Texto::claveDocumento($doc).'@example.test';
            $c['G'.$f] = $modalidad;
        }

        return XlsxSintetico::libro([['nombre' => 'Solicitud', 'celdas' => $c]]);
    }

    /** La persona de doble modalidad (presencial y virtual) y una persona más. */
    private function pareja(string $segundoNombre = self::DOBLE, string $segundaModalidad = 'Virtual'): array
    {
        return [[self::DOBLE, '5100001', 'Presencial'], [$segundoNombre, '5100001', $segundaModalidad], ['BETO FICTICIO UNO', '5100002', 'Presencial']];
    }

    /** Analiza el Excel y deja el borrador decidido: plantilla, evento (asociar o crear) y todas las personas elegibles. */
    private function borrador(array $personas, ?array $evento = null): int
    {
        $id = $this->actingAs($this->admin())->post(route('credential-flow.importaciones.analizar'), ['archivo' => UploadedFile::fake()->createWithContent('doble.xlsx', $this->excel($personas))], ['Accept' => 'application/json'])
            ->assertSuccessful()->json('borrador.id');
        $filas = [];
        foreach (array_keys($personas) as $i) {
            $filas[(string) (7 + $i)] = ['elegibilidad' => 'elegible'];
        }
        $this->corregir($id, [
            'plantilla_id' => $this->plantilla->id,
            'evento' => $evento ?? ['decision' => 'asociar', 'evento_id' => $this->evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria'], 'valores_desactualizados' => true, 'valores_ambiguos' => true],
            'participantes' => $filas,
        ])->assertOk();

        return $id;
    }

    private function corregir(int $id, array $cambios): TestResponse
    {
        return $this->actingAs($this->admin())->putJson(route('credential-flow.importaciones.correcciones', $id), ['version' => Importacion::findOrFail($id)->version] + $cambios);
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

    /** @return list<array<string,mixed>> errores de documento duplicado de la validación */
    private function duplicados(int $id): array
    {
        return collect($this->validar($id)->json('bloqueos'))->where('codigo', 'fila_invalida')->where('error', ErrorFila::DOCUMENTO_DUPLICADO)->values()->all();
    }

    /** Base ya creada con la pareja (por el asistente, en el evento autorizado), con la variante QA que exige la emisión. */
    private function baseConPareja(): Lote
    {
        $this->varianteQa();
        $this->autorizar();
        $id = $this->borrador($this->pareja());
        $this->assertTrue($this->validar($id)->json('listo'));

        return $this->confirmar($id);
    }

    /** @return array{0:Participante,1:Participante} la participación presencial y la virtual */
    private function gemelos(Lote $lote): array
    {
        $dos = $lote->participantes()->where('es_control_qa', false)->where('documento_clave', Texto::claveDocumento('C.C. 5100001'))->orderBy('id')->get();
        $this->assertCount(2, $dos);

        return [$dos->firstWhere('modalidad', 'Presencial'), $dos->firstWhere('modalidad', 'Virtual')];
    }

    private function editar(Lote $lote, Participante $p, array $campos): TestResponse
    {
        return $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))
            ->put(route('credential-flow.participantes.update', [$lote, $p]), ['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento] + $campos);
    }

    private function textos(Emision $e): array
    {
        return array_column((new InspectorPdf($this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent()))->textos(), 'texto');
    }

    private function zip(string $url): array
    {
        $z = new ZipArchive;
        $this->assertTrue($z->open($this->actingAs($this->admin())->get($url)->assertOk()->baseResponse->getFile()->getPathname()));
        $nombres = [];
        for ($i = 0; $i < $z->numFiles; $i++) {
            $nombres[] = $z->getNameIndex($i);
        }
        $z->close();

        return $nombres;
    }

    // ── 1. Evento autorizado: presencial y virtual del mismo documento ────────

    public function test_evento_autorizado_acepta_el_mismo_documento_presencial_y_virtual(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja());
        $v = $this->validar($id);
        $this->assertTrue($v->json('listo'), json_encode($v->json('bloqueos'), JSON_UNESCAPED_UNICODE));
        $this->assertSame(1, collect($v->json('avisos'))->firstWhere('codigo', 'doble_modalidad_autorizada')['cantidad']);

        $lote = $this->confirmar($id);
        [$presencial, $virtual] = $this->gemelos($lote);
        $this->assertSame([7, 8], [$presencial->fila_origen, $virtual->fila_origen]);
        $this->assertSame(3, $lote->participantes()->where('es_control_qa', false)->count());
        $this->assertSame(16, $lote->participantes()->where('es_control_qa', true)->count(), '8 contactos QA × 2 modalidades');

        // Auditoría de la excepción, sin datos personales.
        $mov = Movimiento::where('descripcion', 'like', 'Se confirmó una importación inteligente%')->sole();
        $this->assertSame(['evento_id' => $this->evento->id, 'documentos' => 1], $mov->metadata['doble_modalidad']);
    }

    public function test_el_nombre_escrito_con_tildes_o_puntuacion_distinta_es_la_misma_persona(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja('Ána  Ficticia, Doble'));
        $this->assertTrue($this->validar($id)->json('listo'));
    }

    // ── 1b. Correspondencia fila ↔ modalidad: nunca se cruza ──────────────────

    /** @return array<int,?string> modalidad de cada participante real por su fila del Excel */
    private function modalidadPorFila(Lote $lote): array
    {
        return $lote->participantes()->where('es_control_qa', false)->orderBy('fila_origen')->pluck('modalidad', 'fila_origen')->all();
    }

    public function test_una_fila_excluida_entre_las_dos_no_desplaza_las_modalidades(): void
    {
        $this->autorizar();
        // Fila 8 (otra persona, Virtual) se excluye: la virtual de la persona doble (fila 9) debe seguir siendo Virtual y la fila 10 Presencial.
        $id = $this->borrador([[self::DOBLE, '5100001', 'Presencial'], ['CARO FICTICIA EXCLUIDA', '5100003', 'Virtual'], [self::DOBLE, '5100001', 'Virtual'], ['BETO FICTICIO UNO', '5100002', 'Presencial']]);
        $this->corregir($id, ['participantes' => ['8' => ['excluir' => true]]])->assertOk();
        $this->assertTrue($this->validar($id)->json('listo'));

        $lote = $this->confirmar($id);
        $this->assertSame([7 => 'Presencial', 9 => 'Virtual', 10 => 'Presencial'], $this->modalidadPorFila($lote));
        $this->assertSame(0, $lote->participantes()->where('documento_clave', Texto::claveDocumento('C.C. 5100003'))->count());
    }

    public function test_el_orden_del_excel_no_importa_cada_fila_conserva_su_modalidad(): void
    {
        $this->autorizar();
        $id = $this->borrador([['BETO FICTICIO UNO', '5100002', 'Virtual'], [self::DOBLE, '5100001', 'Virtual'], ['CARO FICTICIA', '5100003', 'Presencial'], [self::DOBLE, '5100001', 'Presencial']]);
        $this->assertTrue($this->validar($id)->json('listo'));
        $this->assertSame([7 => 'Virtual', 8 => 'Virtual', 9 => 'Presencial', 10 => 'Presencial'], $this->modalidadPorFila($this->confirmar($id)));
    }

    public function test_en_el_importador_la_modalidad_se_busca_por_numero_de_fila_y_una_fila_sin_ella_bloquea(): void
    {
        $importador = app(ImportadorParticipantes::class);
        // Línea en blanco (la 3) y una fila inválida (la 4, sin nombre) entre las dos participaciones: cada modalidad sigue a SU fila.
        $csv = "nombre_completo,documento\n".self::DOBLE.",C.C. 5.100.001\n\n,C.C. 5.100.009\n".self::DOBLE.",C.C. 5.100.001\n";
        $r = $importador->validar(UploadedFile::fake()->createWithContent('base.csv', $csv), null, [], [2 => 'presencial', 4 => 'virtual', 5 => 'virtual']);
        $this->assertSame([4], array_values(array_unique(array_map(fn (ErrorFila $e) => $e->fila, $r->errores))), 'solo la fila inválida');
        $this->assertNotContains(ErrorFila::DOCUMENTO_DUPLICADO, array_map(fn (ErrorFila $e) => $e->codigo, $r->errores));

        // Si a una fila le faltara su modalidad (nunca debería pasar), no hereda la de otra: cuenta como vacía y bloquea.
        $r = $importador->validar(UploadedFile::fake()->createWithContent('base.csv', $csv), null, [], [2 => 'presencial', 4 => 'virtual']);
        $dup = array_values(array_filter($r->errores, fn (ErrorFila $e) => $e->codigo === ErrorFila::DOCUMENTO_DUPLICADO));
        $this->assertCount(1, $dup);
        $this->assertSame(5, $dup[0]->fila);
        $this->assertStringContainsString('cada una debe tener su modalidad', $dup[0]->mensaje);
    }

    public function test_al_confirmar_se_vuelve_a_validar_con_la_regla_vigente(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja());
        $this->assertTrue($this->validar($id)->json('listo'));
        $confirmar = fn () => $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true]);

        // Validado con autorización, pero retirada antes de confirmar: no se crea la base.
        config(['credential_flow.doble_modalidad.eventos' => null]);
        $confirmar()->assertStatus(422);
        $this->assertSame(0, Lote::count());

        // Con autorización, pero alguien cambió la virtual a presencial en el borrador después de validar: tampoco.
        $this->autorizar();
        $this->corregir($id, ['participantes' => ['8' => ['modalidad' => 'Presencial']]])->assertOk();
        $confirmar()->assertStatus(422);
        $this->assertSame(0, Lote::count());
    }

    // ── 2–4. Lo que sigue bloqueado aunque el evento esté autorizado ──────────

    public function test_mismo_documento_y_misma_modalidad_se_bloquea(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja(self::DOBLE, 'Presencial'));
        $dup = $this->duplicados($id);
        $this->assertCount(1, $dup);
        $this->assertSame(8, $dup[0]['fila']);
        $this->assertStringContainsString('misma modalidad', $dup[0]['mensaje']);
        $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true])->assertStatus(422);
        $this->assertSame(0, Lote::count());
    }

    public function test_mayusculas_y_espacios_no_distinguen_modalidades(): void
    {
        $this->assertSame('virtual sincrónico', DobleModalidad::efectiva('  VIRTUAL   Sincrónico ', 'Presencial'));
        $this->assertSame('presencial', DobleModalidad::efectiva(null, ' Presencial '));
        $this->assertSame('presencial', DobleModalidad::efectiva('   ', 'PRESENCIAL'));
        $this->assertSame('', DobleModalidad::efectiva(null, null));

        $this->autorizar();
        $id = $this->borrador([[self::DOBLE, '5100001', 'Virtual Sincrónico'], [self::DOBLE, '5100001', 'VIRTUAL   SINCRÓNICO'], ['BETO FICTICIO UNO', '5100002', 'Presencial']]);
        $dup = $this->duplicados($id);
        $this->assertCount(1, $dup);
        $this->assertStringContainsString('misma modalidad', $dup[0]['mensaje']);
    }

    public function test_mismo_documento_con_otro_nombre_se_bloquea(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja('ANA FICTICIA DISTINTA'));
        $dup = $this->duplicados($id);
        $this->assertCount(1, $dup);
        $this->assertStringContainsString('otro nombre', $dup[0]['mensaje']);
    }

    // ── 5–7. Sin autorización: exactamente como antes ─────────────────────────

    public function test_evento_no_autorizado_conserva_la_regla_anterior(): void
    {
        $otro = $this->nuevoEvento('Otro seminario sintético');
        $this->autorizar($otro);   // autorizado OTRO evento, no este
        $id = $this->borrador($this->pareja());
        $dup = $this->duplicados($id);
        $this->assertCount(1, $dup);
        $this->assertSame('Documento duplicado: ya aparece en la fila 7.', $dup[0]['mensaje'], 'el mensaje de siempre');
        $this->assertNull(collect($this->validar($id)->json('avisos'))->firstWhere('codigo', 'doble_modalidad_autorizada'));

        config(['credential_flow.doble_modalidad.eventos' => null]);   // variable ausente: desactivado (valor por defecto)
        $this->assertCount(1, $this->duplicados($id));
    }

    public function test_importacion_que_crea_un_evento_nuevo_conserva_la_regla_anterior(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja(), ['decision' => 'crear', 'nombre' => 'Seminario sintético recién creado', 'confirmar_distinto' => true]);
        $dup = $this->duplicados($id);
        $this->assertCount(1, $dup);
        $this->assertSame('Documento duplicado: ya aparece en la fila 7.', $dup[0]['mensaje']);
    }

    public function test_el_importador_tradicional_no_cambia(): void
    {
        $this->autorizar();
        $csv = "nombre_completo,documento\n".self::DOBLE.",C.C. 5.100.001\n".self::DOBLE.",C.C. 5.100.001\n";
        $r = app(ImportadorParticipantes::class)->validar(UploadedFile::fake()->createWithContent('base.csv', $csv));
        $this->assertFalse($r->valido());
        $this->assertSame([ErrorFila::DOCUMENTO_DUPLICADO], array_map(fn (ErrorFila $e) => $e->codigo, $r->errores));
        $this->assertSame('Documento duplicado: ya aparece en la fila 2.', $r->errores[0]->mensaje);

        // «Nueva base» por el formulario de siempre, incluso asociada al evento autorizado: también bloquea.
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), [
            'plantilla_id' => $this->plantilla->id, 'nombre' => 'Base tradicional', 'evento_id' => $this->evento->id,
            'datos_comunes' => ['evento' => self::EVENTO, 'fecha' => '29 y 30 de octubre de 2026', 'intensidad_horaria' => '18 horas', 'modalidad' => 'Presencial'],
            'archivo' => UploadedFile::fake()->createWithContent('base.csv', $csv),
        ]);
        $this->assertSame(0, Lote::where('nombre', 'Base tradicional')->count());
    }

    // ── 8–9. Formulario de participantes: la misma regla ──────────────────────

    public function test_se_puede_editar_el_correo_de_cualquiera_de_las_dos_participaciones(): void
    {
        $lote = $this->baseConPareja();
        [$presencial, $virtual] = $this->gemelos($lote);

        $this->editar($lote, $presencial, ['correo' => 'ana.nueva@example.test'])->assertSessionHasNoErrors()->assertRedirect();
        $this->editar($lote, $virtual, ['correo' => 'ana.nueva@example.test', 'modalidad' => 'Virtual'])->assertSessionHasNoErrors()->assertRedirect();
        $this->assertSame([$presencial->id, $virtual->id], DB::table('cf_correos')->where('correo_normalizado', 'ana.nueva@example.test')->orderBy('participante_id')->pluck('participante_id')->all());
        $this->assertSame(['Presencial', 'Virtual'], [$presencial->fresh()->modalidad, $virtual->fresh()->modalidad]);

        $mov = Movimiento::where('descripcion', 'Se editó un participante de un lote de Credential Flow')->latest('id')->first();
        $this->assertSame(['evento_id' => $this->evento->id], $mov->metadata['doble_modalidad']);
    }

    public function test_un_cambio_de_modalidad_o_un_alta_que_duplique_se_bloquea(): void
    {
        $lote = $this->baseConPareja();
        [$presencial, $virtual] = $this->gemelos($lote);

        // La virtual no puede pasar a presencial (con otras mayúsculas y espacios tampoco).
        $this->editar($lote, $virtual, ['modalidad' => ' presencial '])->assertSessionHasErrors('documento');
        $this->assertSame('Virtual', $virtual->fresh()->modalidad);
        // Quitarle la modalidad propia la deja sin modalidad: tampoco.
        $this->editar($lote, $virtual, ['modalidad' => ''])->assertSessionHasErrors('documento');

        $alta = fn (array $campos) => $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))
            ->post(route('credential-flow.participantes.store', $lote), ['documento' => 'C.C. 5100001'] + $campos);
        $alta(['nombre_completo' => self::DOBLE, 'modalidad' => 'VIRTUAL'])->assertSessionHasErrors('documento');
        $alta(['nombre_completo' => 'OTRA PERSONA FICTICIA', 'modalidad' => 'Híbrida'])->assertSessionHasErrors('documento');
        $this->assertSame(2, $lote->participantes()->where('es_control_qa', false)->where('documento_clave', $presencial->documento_clave)->count());
    }

    public function test_en_una_base_sin_evento_autorizado_el_formulario_sigue_igual(): void
    {
        $lote = Lote::create(['plantilla_id' => $this->plantilla->id, 'nombre' => 'Base sin autorización', 'evento_id' => $this->evento->id,
            'datos_comunes' => ['evento' => self::EVENTO, 'fecha' => '29 y 30 de octubre de 2026', 'intensidad_horaria' => '18 horas']]);
        $this->participante($lote, self::DOBLE, 'C.C. 5100001')->update(['modalidad' => 'Presencial']);
        $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))
            ->post(route('credential-flow.participantes.store', $lote), ['nombre_completo' => self::DOBLE, 'documento' => 'C.C. 5100001', 'modalidad' => 'Virtual'])
            ->assertSessionHasErrors(['documento' => 'Ya existe un participante con ese documento en esta base.']);
    }

    // ── 10–11. Emisión, reemisión y revocación independientes ─────────────────

    public function test_cada_participacion_tiene_su_certificado_con_codigo_y_pdf_propios(): void
    {
        $lote = $this->baseConPareja();
        [$presencial, $virtual] = $this->gemelos($lote);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 3);

        $ep = Emision::where('participante_id', $presencial->id)->sole();
        $ev = Emision::where('participante_id', $virtual->id)->sole();
        $this->assertNotSame($ep->codigo, $ev->codigo);
        $this->assertNotSame($ep->pdf_archivo, $ev->pdf_archivo);
        $this->assertSame(DB::table('cf_emisiones')->count(), DB::table('cf_emisiones')->distinct()->count('codigo'));
        $this->assertSame(['Presencial', 'Virtual'], [$ep->datos_snapshot['modalidad'], $ev->datos_snapshot['modalidad']]);
        $this->assertContains('Presencial', $this->textos($ep));
        $this->assertNotContains('Virtual', $this->textos($ep));
        $this->assertContains('Virtual', $this->textos($ev));
        // Exactamente un vigente por participante.
        $this->assertSame([1, 1], [Emision::where('participante_id', $presencial->id)->where('estado', Emision::EMITIDA)->count(), Emision::where('participante_id', $virtual->id)->where('estado', Emision::EMITIDA)->count()]);
    }

    public function test_reemitir_o_revocar_una_participacion_no_toca_la_otra(): void
    {
        $lote = $this->baseConPareja();
        [$presencial, $virtual] = $this->gemelos($lote);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $ep = Emision::where('participante_id', $presencial->id)->sole();
        $ev = Emision::where('participante_id', $virtual->id)->sole();

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $ep), ['motivo' => 'Corrección sintética de prueba'])->assertSuccessful();
        $nueva = Emision::where('participante_id', $presencial->id)->where('estado', Emision::EMITIDA)->sole();
        $this->assertSame([2, $ep->id], [$nueva->version, $nueva->reemplaza_id]);
        $this->assertSame([Emision::EMITIDA, 1, $ev->codigo], [$ev->fresh()->estado, $ev->fresh()->version, $ev->fresh()->codigo], 'la virtual sigue intacta');

        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $ev), ['motivo' => 'Revocación sintética de prueba'])->assertSuccessful();
        $this->assertSame(Emision::REVOCADA, $ev->fresh()->estado);
        $this->assertSame(Emision::EMITIDA, $nueva->fresh()->estado, 'la presencial sigue vigente');
    }

    // ── 13. Informes y ZIP ─────────────────────────────────────────────────────

    public function test_el_informe_y_los_zip_incluyen_los_dos_certificados(): void
    {
        $lote = $this->baseConPareja();
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();

        $resumen = app(ServicioEventos::class)->resumen($this->evento->fresh());
        $this->assertSame(['vigentes' => 3, 'revocadas' => 0, 'participantes' => 3, 'pendientes' => 0], $resumen['reales']);
        foreach ([route('credential-flow.lotes.zip', $lote), route('credential-flow.eventos.zip', $this->evento)] as $url) {
            $nombres = array_values(array_filter($this->zip($url), fn ($n) => str_ends_with($n, '.pdf')));
            $this->assertCount(3, $nombres);
            $this->assertCount(2, array_filter($nombres, fn ($n) => str_contains($n, 'ANA-FICTICIA-DOBLE')), 'las dos participaciones, cada una en su archivo');
            $this->assertSame(count($nombres), count(array_unique($nombres)));
        }
    }

    // ── 14. Programador: 36 oficiales + 16 QA, una sola vez ───────────────────

    public function test_la_emision_programada_emite_36_oficiales_y_16_qa_una_sola_vez(): void
    {
        $this->varianteQa();
        $this->autorizar();
        // 36 participaciones de 35 personas: 32 presenciales y 4 virtuales (una persona en las dos modalidades).
        $personas = [[self::DOBLE, '5100001', 'Presencial'], [self::DOBLE, '5100001', 'Virtual']];
        for ($i = 2; $i <= 35; $i++) {
            $personas[] = [sprintf('PERSONA %02d FICTICIA', $i), (string) (5100000 + $i), $i <= 4 ? 'Virtual' : 'Presencial'];
        }
        $id = $this->borrador($personas);
        $this->assertTrue($this->validar($id)->json('listo'));
        $lote = $this->confirmar($id);
        $this->assertSame([36, 16], [$lote->participantes()->where('es_control_qa', false)->count(), $lote->participantes()->where('es_control_qa', true)->count()]);
        $this->assertSame(['Presencial' => 32, 'Virtual' => 4], $lote->participantes()->where('es_control_qa', false)->get()->countBy('modalidad')->sortKeys()->all());

        config(['credential_flow.programacion.activa' => true]);
        $this->travelTo(CarbonImmutable::create(2026, 10, 29, 16, 0, 0, 'America/Bogota'));
        $p = app(ServicioProgramacion::class)->programar($lote->fresh(), '2026-10-31 08:00', $this->admin());

        $correr = function (): string {
            app('auth')->forgetGuards();
            $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas'));

            return Artisan::output();
        };
        $this->travelTo(CarbonImmutable::create(2026, 10, 31, 7, 59, 0, 'America/Bogota'));
        $correr();
        $this->assertSame(0, Emision::count(), 'antes de la hora no emite');

        $this->travelTo(CarbonImmutable::create(2026, 10, 31, 8, 0, 0, 'America/Bogota'));
        $this->assertStringContainsString("#{$p->id}: completada. Oficiales 36, QA 16.", $correr());
        $this->assertSame(EmisionProgramada::COMPLETADA, $p->fresh()->estado);
        $this->assertSame(52, Emision::where('lote_id', $lote->id)->count());
        $porParticipante = Emision::where('lote_id', $lote->id)->selectRaw('participante_id, count(*) n')->groupBy('participante_id')->pluck('n')->unique()->all();
        $this->assertSame([1], array_values($porParticipante), 'una emisión por participación');
        $this->assertSame(2, Emision::where('lote_id', $lote->id)->whereIn('participante_id', $lote->participantes()->where('documento_clave', Texto::claveDocumento('C.C. 5100001'))->where('es_control_qa', false)->pluck('id'))->count());

        // Otra corrida no duplica nada.
        $this->travelTo(CarbonImmutable::create(2026, 10, 31, 8, 5, 0, 'America/Bogota'));
        $correr();
        $this->assertSame(52, Emision::where('lote_id', $lote->id)->count());
    }

    // ── 16. Lectura ESTRICTA de CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS ───────

    public function test_la_variable_solo_acepta_ids_decimales_separados_por_comas(): void
    {
        $validos = ['636' => [636], '636,637' => [636, 637], '1' => [1], '999999999999999999' => [999999999999999999]];
        foreach ($validos as $texto => $esperado) {
            $this->assertSame($esperado, DobleModalidad::interpretar((string) $texto), "«{$texto}» es válido");
        }
    }

    public function test_cualquier_elemento_invalido_deja_sin_autorizar_a_todos(): void
    {
        $invalidos = [
            '6 36', '1e3', '636x', '636;637', '636,,637', '636,636', '0', '-1', '', ' ', '+636', '0636', '636.0', '0x27C', '636 , 637', '636, 637',
            ' 636', '636 ', "636\n", "636\t", '636,', ',636', ',', '636,valor_invalido', '636,0', '1000000000000000000', '99999999999999999999', '６３６',
        ];
        foreach ($invalidos as $texto) {
            $this->assertSame([], DobleModalidad::interpretar($texto), 'rechaza «'.addcslashes($texto, "\n\t").'»');
        }
        // Lo que no es texto (p. ej. env() convierte «true»/«null») tampoco autoriza nada.
        foreach ([null, true, false, 636, [636], ['636']] as $valor) {
            $this->assertSame([], DobleModalidad::interpretar($valor));
        }

        // A través de la configuración: «636,valor_invalido» no autoriza ni siquiera el 636.
        config(['credential_flow.doble_modalidad.eventos' => '636,valor_invalido']);
        $this->assertFalse(DobleModalidad::autorizado(636));
        config(['credential_flow.doble_modalidad.eventos' => '636,637']);
        $this->assertSame([true, true, false, false], [DobleModalidad::autorizado(636), DobleModalidad::autorizado(637), DobleModalidad::autorizado(6), DobleModalidad::autorizado(null)]);
    }

    public function test_sin_la_variable_ningun_evento_esta_autorizado(): void
    {
        $this->assertFalse(getenv('CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS'), 'el entorno de pruebas no la define');
        $config = require config_path('credential_flow.php');
        $this->assertNull($config['doble_modalidad']['eventos'], 'variable ausente = null');
        $this->assertSame([], DobleModalidad::eventosAutorizados());
        $this->assertFalse(DobleModalidad::autorizado($this->evento->id));
    }

    public function test_la_cache_de_configuracion_no_cambia_el_resultado(): void
    {
        // `php artisan config:cache` guarda la configuración con var_export y la vuelve a cargar con require: se reproduce ese ida y vuelta.
        foreach (['636,637' => [636, 637], '6 36' => [], '636,valor_invalido' => []] as $texto => $esperado) {
            config(['credential_flow.doble_modalidad.eventos' => $texto]);
            $cacheada = eval('return '.var_export(config('credential_flow'), true).';');
            config(['credential_flow' => $cacheada]);
            $this->assertSame($texto, config('credential_flow.doble_modalidad.eventos'), 'se cachea el texto tal cual');
            $this->assertSame($esperado, DobleModalidad::eventosAutorizados(), "«{$texto}» tras la caché");
        }
    }

    // ── 15. El criterio entre bases del mismo evento no se amplía ─────────────

    public function test_otra_base_del_mismo_evento_autorizado_sigue_bloqueando_el_documento(): void
    {
        $lote = $this->baseConPareja();
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();

        $otra = Lote::create(['plantilla_id' => $this->plantilla->id, 'nombre' => 'Otra base del evento', 'evento_id' => $this->evento->id,
            'datos_comunes' => ['evento' => self::EVENTO, 'fecha' => '29 y 30 de octubre de 2026', 'intensidad_horaria' => '18 horas']]);
        $this->participante($otra, self::DOBLE, 'C.C. 5100001')->update(['modalidad' => 'Híbrida']);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $otra))->assertStatus(409)->assertJsonPath('error.code', 'DUPLICADO_EN_EVENTO');
    }
}
