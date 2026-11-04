<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Emisiones\ServicioVistaPrevia;
use App\Support\CredentialFlow\Participantes\ErrorFila;
use App\Support\CredentialFlow\Participantes\ImportacionInvalidaException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Plantillas\ArchivoPlantillas;
use App\Support\CredentialFlow\Plantillas\DuplicacionException;
use App\Support\CredentialFlow\Plantillas\DuplicadorPlantilla;
use App\Support\CredentialFlow\Plantillas\ProteccionDiseno;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Illuminate\Database\QueryException;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;

/**
 * C2 · Ajustes 1 y 3. (1) El diseño de una plantilla EN USO (emisiones de cualquier estado o más de una base) queda protegido: se consulta, pero no se
 * guarda sobre el original (bloqueado en el servidor); se duplica y se edita la copia. (3) Una variante QA nunca es la plantilla OFICIAL de una base:
 * solo se usa a través de su asociación con una plantilla principal. Datos 100 % ficticios, SQLite en memoria y discos falsos.
 */
class ProteccionPlantillasTest extends ProgramacionTestCase
{
    private const MOTIVO = 'Fondo con errata reemplazado por la versión corregida';

    private const COMUNES = ['evento' => 'TALLER FICTICIO', 'fecha' => '29 de octubre de 2026', 'intensidad_horaria' => '3 horas'];

    /** Intenta guardar un diseño cambiado por la ruta real del editor. */
    private function guardarDiseno(Plantilla $p, int $y = 333)
    {
        $diseno = $p->fresh()->diseno;
        $diseno['elements'][0]['y'] = $y;

        return $this->actingAs($this->admin())->put(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => $diseno]);
    }

    /** Lo que nunca debe cambiar de la plantilla: diseño, versión, PDF base (bytes) y su hash. */
    private function huella(Plantilla $p): array
    {
        $f = Plantilla::withTrashed()->findOrFail($p->id);

        return [json_encode($f->diseno), $f->schema_version, $f->archivo_pdf, $f->hash_sha256, hash('sha256', (string) Storage::disk('local')->get($f->rutaPdfEsperada()))];
    }

    private function emisiones(): array
    {
        return Emision::query()->orderBy('id')->get()->map(fn (Emision $e) => [$e->id, $e->estado, $e->version, $e->plantilla_id, $e->pdf_hash, (string) $e->updated_at,
            hash('sha256', (string) Storage::disk('local')->get($e->pdf_archivo))])->all();
    }

    private function emitirTodo(Lote $lote): void
    {
        app(EmisorLote::class)->emitirTodo($lote->fresh(), $this->admin()->id);
    }

    private function bloqueoEsperado($respuesta, Plantilla $p, array $antes): void
    {
        $respuesta->assertRedirect(route('credential-flow.plantillas.editor', $p))->assertSessionHas('error', ProteccionDiseno::MENSAJE);
        $this->assertSame($antes, $this->huella($p), 'el diseño original no cambió');
    }

    private function editor(Plantilla $p): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $p))->assertOk()->viewData('page')['props'];
    }

    // ── 1. Edición permitida sin dependencias ─────────────────────────────────

    public function test_una_plantilla_nueva_sin_dependencias_o_con_una_sola_base_sin_emitir_se_edita(): void
    {
        $nueva = $this->plantillaLista();
        $this->assertFalse($this->editor($nueva)['proteccion']['protegida']);
        $this->guardarDiseno($nueva, 300)->assertSessionHas('success');
        $this->assertSame(300, $nueva->fresh()->diseno['elements'][0]['y']);

        // Con UNA base y sin emisiones sigue siendo editable (preparar la plantilla de una capacitación antes de emitir).
        $this->loteCon(2, $nueva);
        $this->assertSame(['protegida' => false, 'emisiones' => 0, 'bases' => 1], array_intersect_key(ProteccionDiseno::estado($nueva->fresh()), array_flip(['protegida', 'emisiones', 'bases'])));
        $this->guardarDiseno($nueva, 310)->assertSessionHas('success');
        $this->assertSame(310, $nueva->fresh()->diseno['elements'][0]['y']);

        $props = $this->actingAs($this->admin())->get(route('credential-flow.plantillas.index'))->viewData('page')['props']['plantillas'];
        $this->assertFalse(collect($props)->firstWhere('id', $nueva->id)['diseno_protegido']);
    }

    // ── 2. Bloqueada con emisiones vigentes ───────────────────────────────────

    public function test_con_emisiones_vigentes_el_diseno_queda_protegido_en_el_servidor_y_en_la_pantalla(): void
    {
        $lote = $this->base(2);
        $p = $lote->plantilla;
        $variante = Plantilla::findOrFail($p->variante_qa_id);
        $this->emitirTodo($lote);
        $antes = $this->huella($p);
        $antesVariante = $this->huella($variante);

        $this->bloqueoEsperado($this->guardarDiseno($p), $p, $antes);
        // La variante QA también: tiene sus emisiones de prueba (y cuenta las bases de su oficial).
        $this->bloqueoEsperado($this->guardarDiseno($variante), $variante, $antesVariante);

        $props = $this->editor($p);
        $this->assertTrue($props['proteccion']['protegida']);
        $this->assertSame(ProteccionDiseno::MENSAJE, $props['proteccion']['mensaje']);
        $this->assertSame(2, $props['proteccion']['emisiones']);
        $this->assertNotNull($props['diseno'], 'el diseño se sigue pudiendo consultar');
        $this->assertTrue(collect($this->actingAs($this->admin())->get(route('credential-flow.plantillas.index'))->viewData('page')['props']['plantillas'])->firstWhere('id', $p->id)['diseno_protegido']);
    }

    // ── 3. Bloqueada con emisiones revocadas ──────────────────────────────────

    public function test_con_emisiones_revocadas_el_diseno_sigue_protegido(): void
    {
        $lote = $this->base(1, false, false);
        $p = $lote->plantilla;
        $this->emitirTodo($lote);
        foreach (Emision::query()->where('plantilla_id', $p->id)->get() as $e) {
            app(EmisorCredencial::class)->revocar($e, 'Revocación ficticia para la prueba', $this->admin()->id);
        }
        $this->assertSame(0, Emision::query()->where('plantilla_id', $p->id)->where('estado', Emision::EMITIDA)->count());
        $estado = ProteccionDiseno::estado($p);
        $this->assertSame([true, 1, 1], [$estado['protegida'], $estado['emisiones'], $estado['revocadas']]);

        // Aunque se elimine la base, la evidencia de la emisión sigue apuntando a este diseño.
        $lote->delete();
        $this->assertTrue(ProteccionDiseno::protegida($p->fresh()));
        $this->bloqueoEsperado($this->guardarDiseno($p), $p, $this->huella($p));
    }

    // ── 4. Bloqueada con varias bases ─────────────────────────────────────────

    public function test_con_varias_bases_sin_emisiones_el_diseno_queda_protegido_tambien_para_su_variante_qa(): void
    {
        $p = $this->plantillaLista();
        $variante = $this->variante($p);
        $this->loteCon(1, $p);
        $this->assertFalse(ProteccionDiseno::protegida($p->fresh()));
        $this->loteCon(1, $p);

        $this->assertSame([true, 0, 2], [ProteccionDiseno::estado($p->fresh())['protegida'], ProteccionDiseno::estado($p->fresh())['emisiones'], ProteccionDiseno::estado($p->fresh())['bases']]);
        $this->bloqueoEsperado($this->guardarDiseno($p), $p, $this->huella($p));
        $this->assertSame(2, ProteccionDiseno::bases($variante), 'la variante cuenta las bases de su oficial');
        $this->bloqueoEsperado($this->guardarDiseno($variante), $variante, $this->huella($variante));
    }

    // ── 5. Duplicar una plantilla protegida (no archivada) ────────────────────

    public function test_una_plantilla_protegida_se_duplica_y_la_copia_independiente_si_se_edita(): void
    {
        $lote = $this->base(1, false, false);
        $p = $lote->plantilla;
        $this->emitirTodo($lote);
        $antes = $this->huella($p);
        $emisiones = $this->emisiones();

        $copiaId = app(DuplicadorPlantilla::class)->duplicar($p->id, 'Copia editable del diseño protegido')['copia_id'];
        $copia = Plantilla::findOrFail($copiaId);
        $this->assertFalse(ProteccionDiseno::protegida($copia), 'la copia nace sin dependencias');
        $this->guardarDiseno($copia, 280)->assertSessionHas('success');
        $this->assertSame(280, $copia->fresh()->diseno['elements'][0]['y']);

        $this->assertSame($antes, $this->huella($p), 'el original sigue intacto');
        $this->assertSame($emisiones, $this->emisiones(), 'ningún certificado cambió');
        $this->assertSame($p->id, (int) $lote->fresh()->plantilla_id, 'la base no se movió');

        // Archivada, en cambio, ya no se duplica (C2): para un diseño nuevo se parte de una vigente.
        app(ArchivoPlantillas::class)->archivar($p, $this->superAdmin(), self::MOTIVO);
        try {
            app(DuplicadorPlantilla::class)->planear($p->id, 'Copia de una archivada');
            $this->fail('No debió duplicarse.');
        } catch (DuplicacionException $e) {
            $this->assertSame(DuplicacionException::ORIGEN_ARCHIVADA, $e->codigo);
        }
    }

    // ── 6. Archivo y restauración sin pérdida de datos ────────────────────────

    public function test_archivar_y_restaurar_una_plantilla_protegida_no_pierde_datos_ni_la_desprotege(): void
    {
        $lote = $this->base(2);
        $p = $lote->plantilla;
        $variante = Plantilla::findOrFail($p->variante_qa_id);
        $this->emitirTodo($lote);
        $antes = [$this->huella($p), $this->huella($variante), $this->emisiones(), $lote->participantes()->orderBy('id')->get(['id', 'nombre_completo', 'documento'])->toArray()];

        $r = app(ArchivoPlantillas::class)->archivar($p, $this->superAdmin(), self::MOTIVO);
        $this->assertEqualsCanonicalizing([$p->id, $variante->id], $r['archivadas']);
        app(ArchivoPlantillas::class)->restaurar($p->fresh(), $this->superAdmin(), 'Se vuelve a usar mientras se prepara la nueva');

        $this->assertSame($antes, [$this->huella($p), $this->huella($variante), $this->emisiones(), $lote->participantes()->orderBy('id')->get(['id', 'nombre_completo', 'documento'])->toArray()]);
        $this->assertSame([false, false], [$p->fresh()->estaArchivada(), $variante->fresh()->estaArchivada()]);
        $this->assertSame($variante->id, (int) $p->fresh()->variante_qa_id, 'la asociación QA se conserva');
        $this->assertTrue(ProteccionDiseno::protegida($p->fresh()), 'restaurar no desbloquea la edición: las emisiones siguen ahí');
        $this->bloqueoEsperado($this->guardarDiseno($p), $p, $antes[0]);
    }

    // ── 7. Una variante QA no es plantilla oficial ────────────────────────────

    public function test_una_variante_qa_no_se_elige_ni_se_asigna_como_plantilla_oficial_por_ninguna_via(): void
    {
        $oficial = $this->plantillaLista();
        $variante = $this->variante($oficial);
        $lote = $this->loteCon(1, $oficial);
        $this->actingAs($this->admin());

        // Selectores: nueva base clásica, asistente de la base e importación inteligente.
        $this->assertNotContains($variante->id, array_column($this->get(route('credential-flow.lotes.nuevo'))->viewData('page')['props']['plantillas'], 'id'));
        $this->assertNotContains($variante->id, array_column($this->get(route('credential-flow.lotes.show', $lote))->viewData('page')['props']['lote']['flujo']['plantillas_disponibles'], 'id'));
        $this->assertContains($oficial->id, array_column($this->get(route('credential-flow.lotes.nuevo'))->viewData('page')['props']['plantillas'], 'id'));

        // Nueva base clásica por la ruta (sin la interfaz): validación y creación.
        foreach (['credential-flow.lotes.validar', 'credential-flow.lotes.store'] as $ruta) {
            $this->post(route($ruta), ['plantilla_id' => $variante->id, 'nombre' => 'Base con QA', 'archivo' => A::subir("nombre_completo,documento\nUNO,C.C. 9.100.111\n", 'b.csv')] + self::COMUNES)
                ->assertSessionHasErrors('plantilla_id');
        }
        // El importador (al que llegan la clásica y la inteligente) también lo rechaza.
        try {
            app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('p.csv', "nombre_completo,documento\nA,C.C. 1.000.001\n"), $variante, 'Base QA', null, self::COMUNES);
            $this->fail('Debió rechazar la variante QA.');
        } catch (ImportacionInvalidaException $e) {
            $this->assertSame(ErrorFila::PLANTILLA_QA, $e->resultado->errores[0]->codigo ?? ($e->resultado->errores[0]['codigo'] ?? null));
        }
        $this->assertSame(1, Lote::count(), 'no se creó ninguna base');

        // Paso «Plantilla» de la base.
        $this->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $variante->id])->assertSessionHas('error');
        $this->assertSame($oficial->id, (int) $lote->fresh()->plantilla_id);

        // Una plantilla oficial EN USO no se convierte en QA, y una QA no lleva variante propia.
        $conAdvertencia = $this->plantillaLista([...$oficial->diseno['elements'], $this->elemento(['type' => 'text', 'text' => ServicioQa::ADVERTENCIA, 'y' => 400])]);
        $this->loteCon(1, $conAdvertencia);
        $this->put(route('credential-flow.plantillas.qa', $conAdvertencia), ['es_plantilla_qa' => true])->assertSessionHas('error');
        $this->assertFalse((bool) $conAdvertencia->fresh()->es_plantilla_qa);
        $this->assertTrue(collect($this->get(route('credential-flow.plantillas.index'))->viewData('page')['props']['plantillas'])->firstWhere('id', $conAdvertencia->id)['uso_oficial']);
        $otraQa = $this->variante($this->plantillaLista());
        $this->expectException(InvalidArgumentException::class);
        app(ServicioQa::class)->asignarVariante($variante->fresh(), $otraQa);
    }

    // ── 8. La QA legítima, a través de su asociación ──────────────────────────

    public function test_la_variante_qa_sigue_funcionando_a_traves_de_su_plantilla_oficial(): void
    {
        $lote = $this->base(2);
        $oficial = $lote->plantilla;
        $variante = Plantilla::findOrFail($oficial->variante_qa_id);
        $this->assertTrue((bool) $variante->es_plantilla_qa);
        $this->assertSame($variante->id, app(ServicioQa::class)->plantillaPara($lote)?->id);

        $this->emitirTodo($lote);
        // Oficiales con la oficial; los 2 contactos QA con su variante (como #19/#20 y #21/#22).
        $this->assertSame(2, Emision::query()->where('lote_id', $lote->id)->where('plantilla_id', $oficial->id)->count());
        $this->assertSame(2, Emision::query()->where('lote_id', $lote->id)->where('plantilla_id', $variante->id)->count());

        // Crear la variante de una oficial que ya tiene bases sigue disponible (es una copia nueva, no convierte la oficial).
        $otra = $this->plantillaLista();
        $this->loteCon(1, $otra);
        $nueva = $this->variante($otra);
        $this->assertTrue((bool) $nueva->es_plantilla_qa);
        $this->assertFalse((bool) $otra->fresh()->es_plantilla_qa);
        // Designar una plantilla nueva (sin bases) como QA y asociarla también sigue disponible.
        $suelta = $this->plantillaLista([...$otra->diseno['elements'], $this->elemento(['type' => 'text', 'text' => ServicioQa::ADVERTENCIA, 'y' => 400])]);
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.qa', $suelta), ['es_plantilla_qa' => true])->assertSessionHas('success');
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.variante-qa', $otra), ['variante_qa_id' => $suelta->id])->assertSessionHas('success');
        $this->assertSame($suelta->id, (int) $otra->fresh()->variante_qa_id);
    }

    // ── Concurrencia: el guardado coordinado (la carrera real se prueba en tests/Integracion contra MariaDB) ──

    public function test_el_guardado_vuelve_a_comprobar_con_la_plantilla_bloqueada_y_un_bloqueo_agotado_no_guarda_nada(): void
    {
        $p = $this->plantillaLista();
        $this->loteCon(1, $p);
        $antes = $this->huella($p);

        // Una dependencia que aparece DESPUÉS de abrir el editor (otra base) se ve al guardar: la comprobación se repite dentro del bloqueo.
        $props = $this->editor($p);
        $this->assertFalse($props['proteccion']['protegida']);
        $this->loteCon(1, $p);
        $this->bloqueoEsperado($this->guardarDiseno($p), $p, $antes);

        // Espera de bloqueo agotada (por ejemplo, una emisión masiva larga con esta plantilla): mensaje claro y nada guardado, ni Movimiento.
        $libre = $this->plantillaLista();
        $antesLibre = $this->huella($libre);
        $movimientos = Movimiento::count();
        ProteccionDiseno::$antesDeGuardar = fn () => throw new QueryException('mysql', 'select * from cf_plantillas for update', [], new \PDOException('SQLSTATE[HY000]: General error: 1205 Lock wait timeout exceeded'));
        try {
            $this->guardarDiseno($libre)->assertRedirect(route('credential-flow.plantillas.editor', $libre))->assertSessionHas('error');
        } finally {
            ProteccionDiseno::$antesDeGuardar = null;
        }
        $this->assertStringContainsString('se está usando', session('error'));
        $this->assertSame($antesLibre, $this->huella($libre), 'no se guardó');
        $this->assertSame($movimientos, Movimiento::count());

        // Sin la simulación, la misma plantilla nueva se guarda con normalidad.
        $this->guardarDiseno($libre, 290)->assertSessionHas('success');
        $this->assertSame(290, $libre->fresh()->diseno['elements'][0]['y']);
    }

    // ── 10. Sin regresiones: emisión, reemisión, programación y vista previa con plantillas protegidas ──

    public function test_la_proteccion_no_bloquea_emitir_reemitir_programar_ni_la_vista_previa(): void
    {
        // Vista previa y programación sobre una plantilla compartida por dos bases (protegida).
        $lote = $this->base(2);
        $this->loteCon(1, $lote->plantilla);
        $this->assertTrue(ProteccionDiseno::protegida($lote->plantilla->fresh()));
        $vp = app(ServicioVistaPrevia::class);
        $vp->registrarGenerada($lote->fresh());
        $vp->confirmar($lote->fresh());
        $this->assertNotNull($lote->fresh()->vista_previa_confirmada_at);
        $this->assertNotNull($this->programar($lote->fresh())->id);

        // Emisión y reemisión masiva con plantillas protegidas.
        $otro = $this->base(2);
        $this->emitirTodo($otro);
        $nueva = $this->base(1)->plantilla;
        $this->emitirTodo($nueva->lotes()->firstOrFail());
        $this->assertTrue(ProteccionDiseno::protegida($nueva->fresh()));
        $otro->forceFill(['plantilla_id' => $nueva->id])->save();
        $plan = app(ReemisorBase::class)->planear($otro->fresh(), ReemisorBase::OFICIALES);
        $this->assertSame([2, null], [$plan['a_reemitir'], $plan['bloqueo']]);
        app(ReemisorBase::class)->reemitir($otro->fresh(), ReemisorBase::OFICIALES, $plan['huella'], self::MOTIVO, $this->admin()->id);
        $this->assertSame(2, Emision::query()->where('lote_id', $otro->id)->where('plantilla_id', $nueva->id)->where('estado', Emision::EMITIDA)->count());
    }
}
