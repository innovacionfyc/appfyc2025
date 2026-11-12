<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Emisiones\DatosRequeridos;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorCredencial;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Participantes\ModalidadImpresa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Tests\Feature\CredentialFlow\Support\ArchivosParticipantes as A;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * Ciudad OBLIGATORIA cuando el diseño imprime el campo derivado «modalidad_ciudad». La regla depende del DISEÑO (DisenoSchema::camposUsados), nunca del id de la
 * plantilla. Una operación bloqueada no deja emisiones, archivos, programaciones, cambios de estado ni movimientos. Las plantillas que no usan el campo se comportan
 * exactamente como antes, aunque la base no tenga ciudad. Datos 100 % ficticios.
 */
class ModalidadCiudadCiudadObligatoriaTest extends ProgramacionTestCase
{
    private const COMUNES = ['evento' => 'SEMINARIO DE PRUEBA', 'fecha' => '5 y 6 de noviembre de 2026', 'intensidad_horaria' => '18 horas'];

    /** @return array<string,array{0:?string}> */
    public static function ciudadesInvalidas(): array
    {
        return ['ciudad ausente' => [null], 'ciudad vacía' => [''], 'solo espacios' => ['   ']];
    }

    // ── Utilidades ────────────────────────────────────────────────────────────

    /** Plantilla de prueba: la línea de modalidad usa el campo indicado; `$ciudadAparte` añade además el campo «ciudad» independiente. */
    private function plantilla(string $campo = 'modalidad_ciudad', bool $ciudadAparte = false): Plantilla
    {
        $el = fn (array $c) => $this->elemento($c + ['x' => 86, 'width' => 620, 'fontSize' => 14, 'height' => 20]);
        $els = [
            $el(['field' => 'nombre_completo', 'y' => 205, 'fontSize' => 22, 'height' => 28]),
            $el(['field' => 'documento', 'y' => 232, 'fontSize' => 18, 'height' => 24]),
            $el(['field' => 'evento', 'y' => 282]),
            $el(['field' => 'fecha', 'y' => 326]),
            $el(['field' => $campo, 'y' => 346]),
            $el(['field' => 'intensidad_horaria', 'y' => 368]),
        ];
        if ($ciudadAparte) {
            $els[] = $el(['field' => 'ciudad', 'y' => 390]);
        }
        $p = $this->plantillaLista($els);
        $p->update(['schema_version' => DisenoSchema::versionPara($p->diseno)]);
        $this->variante($p->fresh());

        return $p->fresh();
    }

    /** Base con 2 personas (Presencial y Virtual, modalidad del Excel), sus QA y la ciudad indicada (null = sin la clave). */
    private function baseCiudad(Plantilla $p, ?string $ciudad): Lote
    {
        $lote = $this->loteCon(0, $p);
        $comunes = self::COMUNES;
        if ($ciudad !== null) {
            $comunes['ciudad'] = $ciudad;   // directo en la BD: también un valor de solo espacios guardado antes de esta regla
        }
        $lote->update(['datos_comunes' => $comunes]);
        foreach (['Presencial', 'Virtual'] as $i => $mod) {
            $this->participante($lote, "Persona Ciudad {$lote->id} {$i}", 'C.C. '.(80_000_000 + $lote->id * 10 + $i))->update(['modalidad' => $mod]);
        }
        app(ServicioQa::class)->incorporar($lote->fresh());

        return $lote->fresh();
    }

    private function quitarCiudad(Lote $lote, ?string $valor = null): void
    {
        $c = $lote->fresh()->datos_comunes;
        if ($valor === null) {
            unset($c['ciudad']);
        } else {
            $c['ciudad'] = $valor;
        }
        DB::table('cf_lotes')->where('id', $lote->id)->update(['datos_comunes' => json_encode($c, JSON_UNESCAPED_UNICODE)]);
    }

    /** Foto de todo lo que una operación bloqueada NO debe cambiar. */
    private function foto(): array
    {
        return [
            'emisiones' => Emision::query()->orderBy('id')->get(['id', 'estado', 'pdf_hash'])->toArray(),
            'archivos' => $this->archivosDeEmisiones(),
            'programaciones' => EmisionProgramada::query()->orderBy('id')->get(['id', 'estado', 'version'])->toArray(),
            'lotes' => DB::table('cf_lotes')->orderBy('id')->get()->map(fn ($l) => (array) $l)->all(),
            'movimientos' => DB::table('movimientos')->count(),
        ];
    }

    private function bloqueoCiudad(\Closure $f): EmisionException
    {
        try {
            $f();
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, $e->codigo);
            $this->assertStringContainsString(DatosRequeridos::MENSAJE_CIUDAD, $e->getMessage());

            return $e;
        }
        $this->fail('la operación debía bloquearse por falta de ciudad');
    }

    // ── Detección por diseño ──────────────────────────────────────────────────

    public function test_la_regla_depende_del_campo_en_el_diseno_y_no_del_id(): void
    {
        $con = $this->plantilla();
        $this->assertTrue(DatosRequeridos::exigeCiudad($con->diseno));
        $this->assertTrue(DatosRequeridos::exigeCiudad(Plantilla::findOrFail($con->variante_qa_id)->diseno), 'la variante QA comparte el diseño');
        $this->assertFalse(DatosRequeridos::exigeCiudad($this->plantilla('modalidad')->diseno));
        $this->assertFalse(DatosRequeridos::exigeCiudad($this->plantilla('modalidad', true)->diseno), '«ciudad» como campo independiente no activa esta regla');
        foreach ([[], ['ciudad' => ''], ['ciudad' => "  \t "]] as $comunes) {
            $this->assertTrue(DatosRequeridos::faltaCiudad($con->diseno, $comunes));
        }
        $this->assertFalse(DatosRequeridos::faltaCiudad($con->diseno, ['ciudad' => 'Bogotá']));
        $this->assertFalse(DatosRequeridos::faltaCiudad($this->plantilla('modalidad')->diseno, []));
    }

    // ── A. Emisión masiva oficial y QA ────────────────────────────────────────

    #[DataProvider('ciudadesInvalidas')]
    public function test_emision_masiva_oficial_y_qa_se_bloquea_sin_dejar_rastro(?string $ciudad): void
    {
        $lote = $this->baseCiudad($this->plantilla(), $ciudad);
        $antes = $this->foto();
        $this->bloqueoCiudad(fn () => app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id));
        $this->bloqueoCiudad(fn () => app(EmisorLote::class)->emitirPendientes($lote, $this->admin()->id));
        $this->bloqueoCiudad(fn () => app(EmisorLote::class)->emitirPendientes($lote, $this->admin()->id, true));
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(422);
        $this->assertStringContainsString(DatosRequeridos::MENSAJE_CIUDAD, $r->json('error.message'));
        $this->assertSame($antes, $this->foto(), 'nada emitido, ningún archivo, ningún cambio');
    }

    public function test_con_ciudad_valida_se_emite_todo_con_modalidad_y_ciudad(): void
    {
        $lote = $this->baseCiudad($this->plantilla(), 'Bogotá');
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $lineas = Emision::query()->where('lote_id', $lote->id)->get()->map(fn (Emision $e) => $e->datos_snapshot['modalidad_ciudad'])->countBy()->sortKeys()->all();
        $this->assertSame(['Presencial, Bogotá' => 3, ModalidadImpresa::VIRTUAL => 3], $lineas, '1 oficial + 2 QA por modalidad; la virtual sin ciudad');
    }

    // ── D. Emisión, reemisión y QA individuales ───────────────────────────────

    #[DataProvider('ciudadesInvalidas')]
    public function test_emision_individual_oficial_y_qa_se_bloquea(?string $ciudad): void
    {
        $lote = $this->baseCiudad($this->plantilla(), $ciudad);
        $oficial = $lote->participantes()->where('es_control_qa', false)->firstOrFail();
        $qa = $lote->participantes()->where('es_control_qa', true)->firstOrFail();
        $antes = $this->foto();
        $this->bloqueoCiudad(fn () => app(EmisorCredencial::class)->emitir($oficial, $lote, $this->admin()->id));
        $this->bloqueoCiudad(fn () => app(EmisorCredencial::class)->emitir($qa, $lote, $this->admin()->id));
        $r = $this->emitirPor($lote, $oficial)->assertStatus(422);
        $this->assertStringContainsString(DatosRequeridos::MENSAJE_CIUDAD, $r->json('error.message'));
        $this->assertSame($antes, $this->foto());
    }

    public function test_reemision_individual_y_masiva_se_bloquean_si_la_base_pierde_la_ciudad_y_lo_anterior_sigue_consultable(): void
    {
        $lote = $this->baseCiudad($this->plantilla(), 'Bogotá');
        app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
        $v1 = Emision::query()->where('lote_id', $lote->id)->orderBy('id')->get();
        $oficial = $v1->first(fn (Emision $e) => ! $e->participante->es_control_qa);
        $this->quitarCiudad($lote, '  ');
        $antes = $this->foto();

        $this->bloqueoCiudad(fn () => app(EmisorCredencial::class)->reemitir($oficial->fresh(), 'Prueba: corrección de datos', $this->admin()->id));
        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $oficial), ['motivo' => 'Prueba: corrección de datos'])->assertStatus(422);
        $this->assertStringContainsString(DatosRequeridos::MENSAJE_CIUDAD, $r->json('error.message'));
        foreach ([ReemisorBase::OFICIALES, ReemisorBase::QA] as $grupo) {
            $plan = app(ReemisorBase::class)->planear($lote->fresh(), $grupo);
            $this->assertGreaterThan(0, $plan['a_reemitir'], 'la línea impresa cambiaría: hay certificados desactualizados');
            $this->assertSame(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, $plan['bloqueo']['code'] ?? null);
            $this->assertStringContainsString(DatosRequeridos::MENSAJE_CIUDAD, $plan['bloqueo']['message']);
            $this->bloqueoCiudad(fn () => app(ReemisorBase::class)->reemitir($lote->fresh(), $grupo, $plan['huella'], 'Prueba: corrección de datos', $this->admin()->id));
        }
        $this->assertSame($antes, $this->foto(), 'ninguna revocación, versión nueva ni archivo');

        // Los certificados anteriores se siguen consultando, descargando y verificando.
        foreach ($v1 as $e) {
            $r = $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk();
            $bytes = $r->baseResponse instanceof BinaryFileResponse ? file_get_contents($r->baseResponse->getFile()->getPathname())
                : ($r->baseResponse instanceof StreamedResponse ? $r->streamedContent() : (string) $r->getContent());
            $this->assertSame($e->pdf_hash, hash('sha256', $bytes));
            app('auth')->forgetGuards();
            $this->get('/verificar/'.$e->codigo)->assertOk();
        }
        $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk();
    }

    // ── B. Programación, reconfirmación y ejecución ───────────────────────────

    #[DataProvider('ciudadesInvalidas')]
    public function test_programar_sin_ciudad_se_bloquea(?string $ciudad): void
    {
        $lote = $this->baseCiudad($this->plantilla(), $ciudad);
        $antes = $this->foto();
        $this->bloqueoCiudad(fn () => $this->programar($lote));
        $this->bloqueoCiudad(fn () => $this->servicio()->previsualizar($lote, '2026-10-28 08:00', $this->admin()));
        $this->assertSame($antes, $this->foto());
    }

    public function test_si_la_base_pierde_la_ciudad_la_programacion_no_emite_y_no_se_puede_reconfirmar(): void
    {
        $lote = $this->baseCiudad($this->plantilla(), 'Bogotá');
        $prog = $this->programar($lote, '2026-10-27 10:30');
        $this->quitarCiudad($lote);

        $this->travelTo(CarbonImmutable::create(2026, 10, 27, 10, 30, 5, 'America/Bogota'));
        app('auth')->forgetGuards();
        $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas'));
        $this->assertSame(EmisionProgramada::REQUIERE_CONFIRMACION, $prog->fresh()->estado, Artisan::output());
        $this->assertSame(0, Emision::count(), 'la ejecución no emitió nada');

        $this->travelTo(CarbonImmutable::create(2026, 10, 27, 10, 0, 0, 'America/Bogota'));
        $this->bloqueoCiudad(fn () => $this->servicio()->reconfirmar($prog->fresh(), $this->admin()));
        $this->assertSame(0, Emision::count());
    }

    // ── E. Confirmación de la vista previa ────────────────────────────────────

    #[DataProvider('ciudadesInvalidas')]
    public function test_no_se_confirma_la_vista_previa_sin_ciudad(?string $ciudad): void
    {
        config(['credential_flow.emision.exige_vista_previa' => true]);
        $lote = $this->baseCiudad($this->plantilla(), $ciudad);
        $p = $lote->participantes()->where('es_control_qa', false)->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $p]))->assertOk();
        $movimientos = DB::table('movimientos')->count();

        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHas('error', DatosRequeridos::MENSAJE_CIUDAD);
        $this->assertNull($lote->fresh()->vista_previa_confirmada_at);
        $this->assertSame($movimientos, DB::table('movimientos')->count());

        $this->quitarCiudad($lote, 'Bogotá');
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHas('success');
        $this->assertNotNull($lote->fresh()->vista_previa_confirmada_at);
    }

    // ── F. Crear y editar la base ─────────────────────────────────────────────

    #[DataProvider('ciudadesInvalidas')]
    public function test_crear_una_base_con_la_plantilla_exige_ciudad(?string $ciudad): void
    {
        $plantilla = $this->plantilla();
        $csv = "nombre_completo,documento\nUNO,C.C. 9.100.121\n";
        $campos = ['plantilla_id' => $plantilla->id, 'nombre' => 'Base con ciudad', 'archivo' => A::subir($csv, 'b.csv')] + self::COMUNES + ($ciudad === null ? [] : ['ciudad' => $ciudad]);
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), $campos)->assertSessionHasErrors(['ciudad' => DatosRequeridos::MENSAJE_CIUDAD]);
        $this->assertSame(0, Lote::count());

        $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), ['archivo' => A::subir($csv, 'b.csv'), 'ciudad' => 'Bogotá'] + $campos)->assertSessionHasNoErrors();
        $this->assertSame('Bogotá', Lote::query()->sole()->datos_comunes['ciudad']);
    }

    #[DataProvider('ciudadesInvalidas')]
    public function test_editar_la_base_no_permite_quitar_la_ciudad(?string $ciudad): void
    {
        $lote = $this->baseCiudad($this->plantilla(), 'Bogotá');
        $antes = $lote->datos_comunes;
        $datos = ['nombre' => $lote->nombre] + self::COMUNES + ($ciudad === null ? [] : ['ciudad' => $ciudad]);
        if ($ciudad === null) {
            $datos['ciudad'] = null;   // el formulario envía el campo vacío
        }
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), $datos)->assertSessionHasErrors(['ciudad' => DatosRequeridos::MENSAJE_CIUDAD]);
        $this->assertSame($antes, $lote->fresh()->datos_comunes);

        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), ['ciudad' => 'Medellín'] + $datos)->assertSessionHasNoErrors();
        $this->assertSame('Medellín', $lote->fresh()->datos_comunes['ciudad']);
    }

    // ── Compatibilidad: plantillas que no usan el campo ───────────────────────

    public function test_una_plantilla_sin_el_campo_crea_edita_confirma_programa_y_emite_sin_ciudad_como_antes(): void
    {
        config(['credential_flow.emision.exige_vista_previa' => true]);
        $plantilla = $this->plantilla('modalidad');
        $csv = "nombre_completo,documento\nUNO,C.C. 9.100.121\n";
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.store'), ['plantilla_id' => $plantilla->id, 'nombre' => 'Base antigua',
            'archivo' => A::subir($csv, 'b.csv'), 'modalidad' => 'Presencial'] + self::COMUNES)->assertSessionHasNoErrors();

        $lote = $this->baseCiudad($plantilla, null);
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.update', $lote), ['nombre' => $lote->nombre, 'ciudad' => ''] + self::COMUNES)->assertSessionHasNoErrors();
        $p = $lote->participantes()->where('es_control_qa', false)->firstOrFail();
        $this->actingAs($this->admin())->get(route('credential-flow.participantes.pdf', [$lote, $p]))->assertOk();
        $this->actingAs($this->admin())->post(route('credential-flow.lotes.vista-previa.confirmar', $lote))->assertSessionHas('success');
        $this->programar($lote->fresh())->delete();
        app(EmisorLote::class)->emitirTodo($lote->fresh(), $this->admin()->id);
        $this->assertSame(6, Emision::query()->where('lote_id', $lote->id)->count());
        $e = Emision::query()->where('lote_id', $lote->id)->firstOrFail();
        $this->assertArrayNotHasKey('modalidad_ciudad', $e->datos_snapshot);
        $this->assertContains($e->datos_snapshot['modalidad'], array_column((new InspectorPdf(Storage::disk('local')->get($e->pdf_archivo)))->textos(), 'texto'));
    }

    public function test_ciudad_como_campo_independiente_conserva_su_regla_y_mensaje_de_siempre(): void
    {
        $lote = $this->baseCiudad($this->plantilla('modalidad', true), null);
        try {
            app(EmisorLote::class)->emitirTodo($lote, $this->admin()->id);
            $this->fail('debía bloquearse');
        } catch (EmisionException $e) {
            $this->assertSame(EmisionException::DATOS_DEL_DISENO_INCOMPLETOS, $e->codigo);
            $this->assertStringContainsString('«Ciudad»', $e->getMessage());
            $this->assertStringNotContainsString(DatosRequeridos::MENSAJE_CIUDAD, $e->getMessage(), 'la regla nueva es solo para «Modalidad y ciudad»');
        }
        // Y la emisión individual sigue fallando en el generador, como antes (no por la regla nueva).
        $p = $lote->participantes()->where('es_control_qa', false)->firstOrFail();
        $r = $this->emitirPor($lote, $p);
        $this->assertStringNotContainsString(DatosRequeridos::MENSAJE_CIUDAD, (string) $r->getContent());
        $this->assertSame(0, Emision::count());
    }
}
