<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Participantes\ImportacionInvalidaException;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Plantillas\ArchivoException;
use App\Support\CredentialFlow\Plantillas\ArchivoPlantillas;
use App\Support\CredentialFlow\Plantillas\DuplicacionException;
use App\Support\CredentialFlow\Plantillas\DuplicadorPlantilla;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * C2 · Archivo seguro de plantillas. Una plantilla archivada se conserva entera (diseño, PDF base, bases, emisiones y sus PDF, auditoría), deja de ofrecerse en
 * los selectores y NO genera emisiones ni reemisiones nuevas, bloqueado en los servicios (rutas, comandos y programador). Solo un super-admin archiva o
 * restaura, con motivo y confirmación. Variantes QA coherentes con su oficial. Datos 100 % ficticios, SQLite en memoria y discos falsos.
 */
class ArchivoPlantillasTest extends ProgramacionTestCase
{
    private const MOTIVO = 'Fondo con errata reemplazado por la versión corregida';

    private function archivo(): ArchivoPlantillas
    {
        return app(ArchivoPlantillas::class);
    }

    private function archivar(Plantilla $p, ?int $sucesora = null): array
    {
        return $this->archivo()->archivar($p, $this->superAdmin(), self::MOTIVO, $sucesora);
    }

    private function codigoArchivo(\Closure $f): string
    {
        try {
            $f();
        } catch (ArchivoException $e) {
            return $e->codigo;
        }
        $this->fail('Se esperaba un ArchivoException.');
    }

    /** Columnas de la plantilla que el archivo NUNCA debe tocar. */
    private function contenido(Plantilla $p): array
    {
        $f = Plantilla::withTrashed()->findOrFail($p->id);

        return [$f->nombre, $f->descripcion, $f->archivo_pdf, $f->hash_sha256, json_encode($f->diseno), $f->schema_version, (bool) $f->es_plantilla_qa, $f->variante_qa_id, $f->deleted_at,
            hash('sha256', (string) Storage::disk('local')->get($f->rutaPdfEsperada()))];
    }

    /** Huella de todas las emisiones (filas + PDF) para comprobar que el archivo no toca ninguna. */
    private function emisiones(): array
    {
        return Emision::query()->orderBy('id')->get()->map(fn (Emision $e) => [$e->id, $e->estado, $e->version, $e->plantilla_id, $e->pdf_hash, $e->codigo,
            json_encode($e->datos_snapshot), (string) $e->updated_at, hash('sha256', (string) Storage::disk('local')->get($e->pdf_archivo))])->all();
    }

    private function emitirTodo(Lote $lote): void
    {
        app(EmisorLote::class)->emitirTodo($lote->fresh(), $this->admin()->id);
    }

    // ── Migración y estado inicial ────────────────────────────────────────────

    public function test_la_migracion_es_aditiva_todas_quedan_vigentes_y_las_columnas_no_son_asignables(): void
    {
        $a = $this->plantillaLista();
        $b = $this->plantillaLista();
        $this->assertTrue(ArchivoPlantillas::disponible());
        $this->assertSame([false, false], [$a->estaArchivada(), $b->estaArchivada()]);
        $this->assertSame(Plantilla::count(), Plantilla::query()->vigentes()->count());

        // Ninguna ruta de asignación masiva puede archivar: las columnas no están en $fillable.
        $a->update(['archivada_at' => now(), 'motivo_archivo' => 'intento', 'sucesora_id' => $b->id]);
        $this->assertFalse($a->fresh()->estaArchivada());
        $this->assertNull($a->fresh()->sucesora_id);
    }

    // ── Permisos, motivo y confirmación ───────────────────────────────────────

    public function test_solo_un_super_admin_archiva_por_ruta_y_por_servicio(): void
    {
        $p = $this->plantillaLista();
        $datos = ['motivo' => self::MOTIVO, 'confirmacion' => true];

        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.archivo.resumen', $p))->assertForbidden();
        $this->actingAs($this->admin())->post(route('credential-flow.plantillas.archivar', $p), $datos)->assertForbidden();
        $this->actingAs($this->comercial())->post(route('credential-flow.plantillas.archivar', $p), $datos)->assertForbidden();
        $this->post(route('credential-flow.plantillas.archivar', $p), $datos)->assertForbidden();
        Auth::logout();
        $this->post(route('credential-flow.plantillas.archivar', $p), $datos)->assertRedirect(route('login'));
        // El servicio tampoco acepta a un admin (comandos u otras rutas que lo llamen directamente).
        $this->assertSame(ArchivoException::NO_AUTORIZADO, $this->codigoArchivo(fn () => $this->archivo()->archivar($p, $this->admin(), self::MOTIVO)));
        $this->assertSame(ArchivoException::NO_AUTORIZADO, $this->codigoArchivo(fn () => $this->archivo()->archivar($p, null, self::MOTIVO)));
        $this->assertFalse($p->fresh()->estaArchivada());

        $this->actingAs($this->superAdmin())->get(route('credential-flow.plantillas.archivo.resumen', $p))->assertOk()->assertJsonPath('bloqueos', []);
        $this->actingAs($this->superAdmin())->post(route('credential-flow.plantillas.archivar', $p), $datos)->assertRedirect(route('credential-flow.plantillas.index'))->assertSessionHas('success');

        $p->refresh();
        $this->assertTrue($p->estaArchivada());
        $this->assertSame([$this->superAdmin()->id, self::MOTIVO], [(int) $p->archivada_por, $p->motivo_archivo]);
        $mov = Movimiento::query()->where('descripcion', "Se archivó la plantilla de Credential Flow: {$p->nombre}")->sole();
        $this->assertSame([$this->superAdmin()->id, 'archivar_plantilla', $p->id, [$p->id], self::MOTIVO], [$mov->user_id, $mov->metadata['accion'], $mov->metadata['plantilla_id'], $mov->metadata['archivadas'], $mov->metadata['motivo']]);
    }

    public function test_archivar_exige_motivo_y_confirmacion_explicita(): void
    {
        $p = $this->plantillaLista();
        $this->actingAs($this->superAdmin());

        $this->post(route('credential-flow.plantillas.archivar', $p), ['motivo' => self::MOTIVO])->assertSessionHasErrors('confirmacion');
        $this->post(route('credential-flow.plantillas.archivar', $p), ['motivo' => 'corto', 'confirmacion' => true])->assertSessionHasErrors('motivo');
        $this->post(route('credential-flow.plantillas.archivar', $p), ['confirmacion' => true])->assertSessionHasErrors('motivo');
        $this->assertSame(ArchivoException::MOTIVO_INVALIDO, $this->codigoArchivo(fn () => $this->archivo()->archivar($p, $this->superAdmin(), '   corto   ')));
        $this->assertFalse($p->fresh()->estaArchivada());
        $this->assertSame(0, Movimiento::query()->where('descripcion', 'like', 'Se archivó%')->count());
    }

    // ── Conservación total ────────────────────────────────────────────────────

    public function test_archivar_conserva_diseno_pdf_base_bases_emisiones_y_sus_pdf(): void
    {
        $lote = $this->base(3);
        $this->emitirTodo($lote);
        $oficial = $lote->plantilla;
        $variante = Plantilla::findOrFail($oficial->variante_qa_id);
        $antes = [$this->contenido($oficial), $this->contenido($variante), $this->emisiones(), Lote::findOrFail($lote->id)->only(['plantilla_id', 'estado_operativo', 'deleted_at'])];
        $this->assertCount(5, $antes[2]);

        $r = $this->archivar($oficial);

        $this->assertSame([$oficial->id, $variante->id], $r['archivadas']);
        $this->assertSame($antes, [$this->contenido($oficial), $this->contenido($variante), $this->emisiones(), Lote::findOrFail($lote->id)->only(['plantilla_id', 'estado_operativo', 'deleted_at'])]);
        $this->assertNull($oficial->fresh()->deleted_at, 'no es un soft delete');
        foreach (Emision::all() as $e) {
            AlmacenEmisiones::rutaFisicaVerificada($e);   // los PDF emitidos siguen íntegros
        }
        // La base conserva su consulta administrativa: la pantalla abre y avisa.
        $flujo = $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()->viewData('page')['props']['lote']['flujo'];
        $this->assertSame($oficial->id, $flujo['aviso_archivo']['plantilla_id']);
        $this->assertTrue($flujo['plantilla']['archivada']);
    }

    // ── Variantes QA ──────────────────────────────────────────────────────────

    public function test_la_variante_qa_se_archiva_con_su_oficial_no_por_separado_y_se_restaura_con_ella(): void
    {
        $lote = $this->base(2);
        $oficial = $lote->plantilla;
        $variante = Plantilla::findOrFail($oficial->variante_qa_id);

        $r = $this->archivo()->resumen($variante);
        $this->assertSame([ArchivoException::VARIANTE_EN_USO], array_column($r['bloqueos'], 'code'));
        $this->assertSame(ArchivoException::VARIANTE_EN_USO, $this->codigoArchivo(fn () => $this->archivar($variante)));
        $this->assertFalse($variante->fresh()->estaArchivada());

        $this->archivar($oficial);
        $oficial->refresh();
        $variante->refresh();
        $this->assertTrue($variante->estaArchivada());
        $this->assertTrue($oficial->archivada_at->equalTo($variante->archivada_at), 'mismo instante');
        $this->assertSame($variante->id, (int) $oficial->variante_qa_id, 'la asociación oficial → variante se conserva');

        // La variante no se restaura sola mientras su oficial siga archivada; restaurar la oficial restaura también la variante.
        $this->assertSame(ArchivoException::OFICIAL_ARCHIVADA, $this->codigoArchivo(fn () => $this->archivo()->restaurar($variante, $this->superAdmin(), self::MOTIVO)));
        $res = $this->archivo()->restaurar($oficial, $this->superAdmin(), 'Se vuelve a usar en pruebas internas');
        $this->assertSame([$oficial->id, $variante->id], $res['restauradas']);
        $this->assertSame([false, false], [$oficial->fresh()->estaArchivada(), $variante->fresh()->estaArchivada()]);
        $this->assertSame([null, null, null], [$oficial->fresh()->motivo_archivo, $oficial->fresh()->sucesora_id, $variante->fresh()->motivo_archivo]);
    }

    public function test_una_variante_compartida_por_otra_oficial_vigente_no_se_archiva(): void
    {
        $lote = $this->base(1);
        $a = $lote->plantilla;
        $b = $this->plantillaLista();
        $b->forceFill(['variante_qa_id' => $a->variante_qa_id])->save();

        $r = $this->archivo()->resumen($a);
        $this->assertFalse($r['variante']['se_archiva_con_ella']);
        $this->assertSame([$b->id], $r['variante']['compartida_con']);
        $this->assertSame([$a->id], $this->archivar($a)['archivadas']);
        $this->assertFalse(Plantilla::findOrFail($a->variante_qa_id)->estaArchivada());
        $this->assertNotNull(app(ServicioQa::class)->varianteDe($b->fresh()), 'la otra oficial conserva su variante');
    }

    // ── Sucesora, programaciones y estados ────────────────────────────────────

    public function test_la_sucesora_debe_ser_vigente_del_mismo_tipo_y_con_diseno(): void
    {
        $vieja = $this->base(1)->plantilla;
        $nueva = $this->base(1)->plantilla;
        $archivada = $this->plantillaLista();
        $this->archivar($archivada);
        $varianteVieja = $vieja->variante_qa_id;

        foreach ([$vieja->id, $varianteVieja, $archivada->id, 999999] as $mala) {
            $this->assertSame(ArchivoException::SUCESORA_INVALIDA, $this->codigoArchivo(fn () => $this->archivar($vieja, $mala)), "sucesora #{$mala}");
        }
        $this->assertFalse($vieja->fresh()->estaArchivada());

        $this->archivar($vieja, $nueva->id);
        $this->assertSame($nueva->id, (int) $vieja->fresh()->sucesora_id);
        $this->assertSame((int) $nueva->variante_qa_id, (int) Plantilla::findOrFail($varianteVieja)->sucesora_id, 'la variante apunta a la variante de la sucesora');
        $this->assertSame([$vieja->id], $this->archivo()->resumen($nueva)['es_sucesora_de']);
        $this->assertSame(ArchivoException::YA_ARCHIVADA, $this->codigoArchivo(fn () => $this->archivar($vieja)));
    }

    public function test_una_programacion_activa_bloquea_el_archivo(): void
    {
        $lote = $this->base(2);
        $prog = $this->programar($lote);
        $r = $this->archivo()->resumen($lote->plantilla);
        $this->assertSame([ArchivoException::PROGRAMACION_ACTIVA], array_column($r['bloqueos'], 'code'));
        $this->assertSame([$prog->id], $r['programaciones_activas']);
        $this->assertSame(ArchivoException::PROGRAMACION_ACTIVA, $this->codigoArchivo(fn () => $this->archivar($lote->plantilla)));
        $this->assertFalse($lote->plantilla->fresh()->estaArchivada());
        $this->assertSame(EmisionProgramada::PENDIENTE, $prog->fresh()->estado);
    }

    public function test_una_plantilla_eliminada_no_se_archiva(): void
    {
        $p = $this->plantillaLista();
        $p->delete();
        $this->assertSame(ArchivoException::ELIMINADA, $this->codigoArchivo(fn () => $this->archivar(Plantilla::withTrashed()->findOrFail($p->id))));
    }

    // ── Selectores ────────────────────────────────────────────────────────────

    public function test_los_selectores_no_ofrecen_plantillas_archivadas(): void
    {
        $vigente = $this->plantillaLista();
        $retirada = $this->plantillaLista();
        $lote = $this->loteCon(1, $vigente);
        $this->archivar($retirada);
        $this->actingAs($this->admin());

        $nuevo = $this->get(route('credential-flow.lotes.nuevo'))->assertOk()->viewData('page')['props']['plantillas'];
        $this->assertContains($vigente->id, array_column($nuevo, 'id'));
        $this->assertNotContains($retirada->id, array_column($nuevo, 'id'));

        $flujo = $this->get(route('credential-flow.lotes.show', $lote))->assertOk()->viewData('page')['props']['lote']['flujo'];
        $this->assertNotContains($retirada->id, array_column($flujo['plantillas_disponibles'], 'id'));
        $this->assertNull($flujo['aviso_archivo']);

        $pantalla = $this->get(route('credential-flow.plantillas.index'))->assertOk()->viewData('page')['props'];
        $this->assertNotContains($retirada->id, collect($pantalla['plantillas'])->pluck('id')->all());
        $this->assertSame([$retirada->id], collect($pantalla['archivadas'])->pluck('id')->all());
        $this->assertFalse($pantalla['puede_archivar'], 'un admin no ve la acción');
        $this->assertTrue($this->actingAs($this->superAdmin())->get(route('credential-flow.plantillas.index'))->viewData('page')['props']['puede_archivar']);
    }

    // ── Asignación bloqueada en el backend ────────────────────────────────────

    public function test_no_se_asigna_una_plantilla_archivada_a_una_base(): void
    {
        $vigente = $this->plantillaLista();
        $retirada = $this->plantillaLista();
        $lote = $this->loteCon(1, $vigente);
        $this->archivar($retirada);

        // Paso «Plantilla» de una base sin emisiones (ruta directa, sin pasar por la interfaz).
        $this->actingAs($this->admin())->put(route('credential-flow.lotes.plantilla', $lote), ['plantilla_id' => $retirada->id])->assertSessionHas('error');
        $this->assertSame($vigente->id, (int) $lote->fresh()->plantilla_id);

        // Creación de bases (clásica e importación inteligente terminan aquí).
        try {
            app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('p.csv', "nombre,documento\nA,1\n"), $retirada, 'Base nueva', null, []);
            $this->fail('Debió rechazar la plantilla archivada.');
        } catch (ImportacionInvalidaException) {
        }
        $this->assertSame(1, Lote::count());

        // Cambio autorizado de plantilla en una base YA emitida: la nueva debe estar vigente.
        $emitida = $this->base(1);
        $this->emitirTodo($emitida);
        $otra = $this->base(1)->plantilla;
        $this->archivar($otra);
        $this->assertSame(1, Artisan::call('credential-flow:base-cambiar-plantilla', ['base' => $emitida->id, 'plantilla' => $otra->id]));
        $this->assertStringContainsString('archivada', Artisan::output());
        $this->assertNotSame($otra->id, (int) $emitida->fresh()->plantilla_id);
    }

    // ── Emisión y reemisión bloqueadas en el backend ──────────────────────────

    public function test_no_se_emite_con_una_plantilla_archivada_por_ninguna_ruta_ni_el_programador(): void
    {
        $lote = $this->base(2);
        $this->archivar($lote->plantilla);
        $real = $lote->participantes()->where('es_control_qa', false)->firstOrFail();
        $this->actingAs($this->admin());

        $this->postJson(route('credential-flow.lotes.emitir', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::PLANTILLA_ARCHIVADA);
        $this->postJson(route('credential-flow.participantes.emitir', [$lote, $real]))->assertStatus(409)->assertJsonPath('error.code', EmisionException::PLANTILLA_ARCHIVADA);
        $this->postJson(route('credential-flow.lotes.emitir-qa', $lote))->assertStatus(409)->assertJsonPath('error.code', EmisionException::PLANTILLA_ARCHIVADA);
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $this->codigo(fn () => app(EmisorLote::class)->emitirPendientes($lote->fresh(), $this->admin()->id)));
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $this->codigo(fn () => app(EmisorLote::class)->emitirPendientes($lote->fresh(), $this->admin()->id, true)));
        // Programar tampoco: la validación de la base usa la misma guarda.
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $this->codigo(fn () => $this->programar($lote->fresh())));
        $this->assertSame([0, 0], [Emision::count(), EmisionProgramada::count()]);
    }

    public function test_una_programacion_que_se_ejecuta_despues_de_archivar_no_emite(): void
    {
        $lote = $this->base(2);
        $prog = $this->programar($lote, '2026-10-27 10:30');
        // Carrera simulada: la plantilla queda archivada por fuera del servicio (que lo bloquearía) antes de la hora.
        $lote->plantilla->forceFill(['archivada_at' => now(), 'archivada_por' => $this->superAdmin()->id, 'motivo_archivo' => self::MOTIVO])->save();

        $this->travelTo(CarbonImmutable::create(2026, 10, 27, 10, 30, 0, 'America/Bogota'));
        app('auth')->forgetGuards();
        $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas'));
        $this->assertSame(0, Emision::count(), 'no emitió nada');
        $this->assertNotSame(EmisionProgramada::COMPLETADA, $prog->fresh()->estado);
    }

    public function test_no_se_reemite_individual_ni_masivamente_con_una_plantilla_archivada(): void
    {
        $lote = $this->base(2);
        $this->emitirTodo($lote);
        $vigente = Emision::query()->where('lote_id', $lote->id)->orderBy('id')->firstOrFail();

        // Plantilla nueva (vigente) para que los certificados queden desactualizados; se planea la reemisión ANTES de archivarla.
        $nueva = $this->base(1)->plantilla;
        $lote->forceFill(['plantilla_id' => $nueva->id])->save();
        $plan = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);
        $this->assertSame([2, null], [$plan['a_reemitir'], $plan['bloqueo']]);
        $this->assertSame(0, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id]));
        preg_match('/Huella del plan: ([0-9a-f]{64})/', Artisan::output(), $m);
        $huella = $m[1] ?? $this->fail('la simulación debe mostrar la huella');

        $this->archivar($nueva);
        $antes = $this->emisiones();

        // Simulación: bloqueada y explicada.
        $sim = app(ReemisorBase::class)->planear($lote->fresh(), ReemisorBase::OFICIALES);
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $sim['bloqueo']['code']);
        $this->assertSame(1, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id]));
        $this->assertStringContainsString('archivada', Artisan::output());
        // Ejecutar con la huella vieja tampoco reemite nada (se vuelve a planear con la base reservada).
        $this->assertSame(1, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $huella, '--ejecutar' => true]));
        // El servicio directo (sin comando) tampoco.
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $this->codigo(fn () => app(ReemisorBase::class)->reemitir($lote->fresh(), ReemisorBase::OFICIALES, $plan['huella'], self::MOTIVO, $this->admin()->id)));
        // Reemisión individual por la ruta.
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $vigente), ['motivo' => self::MOTIVO])
            ->assertStatus(409)->assertJsonPath('error.code', EmisionException::PLANTILLA_ARCHIVADA);

        $this->assertSame($antes, $this->emisiones(), 'ninguna emisión cambió');
    }

    public function test_restaurar_vuelve_a_permitir_emitir(): void
    {
        $lote = $this->base(2);
        $this->archivar($lote->plantilla);
        $this->assertSame(EmisionException::PLANTILLA_ARCHIVADA, $this->codigo(fn () => $this->emitirTodo($lote)));
        $this->archivo()->restaurar($lote->plantilla, $this->superAdmin(), 'Archivada por error, se vuelve a usar');
        $this->emitirTodo($lote);
        $this->assertSame(4, Emision::count());
        $this->assertSame(1, Movimiento::query()->where('descripcion', 'like', 'Se restauró del archivo la plantilla%')->count());
    }

    // ── Editor, duplicado y QA de plantillas archivadas ───────────────────────

    public function test_una_plantilla_archivada_no_se_edita_ni_se_duplica_ni_cambia_su_qa(): void
    {
        $lote = $this->base(1);
        $p = $lote->plantilla;
        $variante = Plantilla::findOrFail($p->variante_qa_id);
        $this->archivar($p);
        $antes = $this->contenido($p);

        $diseno = $p->diseno;
        $diseno['elements'][0]['y'] = 333;
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.diseno.update', $p), ['diseno' => $diseno])->assertSessionHas('error');
        $this->assertTrue($this->actingAs($this->admin())->get(route('credential-flow.plantillas.editor', $p))->assertOk()->viewData('page')['props']['plantilla']['archivada']);

        try {
            app(DuplicadorPlantilla::class)->planear($p->id, 'Copia de una archivada');
            $this->fail('No debió duplicarse.');
        } catch (DuplicacionException $e) {
            $this->assertSame(DuplicacionException::ORIGEN_ARCHIVADA, $e->codigo);
        }
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.variante-qa', $p), ['variante_qa_id' => null])->assertSessionHas('error');
        $this->actingAs($this->admin())->put(route('credential-flow.plantillas.qa', $variante), ['es_plantilla_qa' => false])->assertSessionHas('error');

        $this->assertSame($antes, $this->contenido($p));
        $this->assertTrue((bool) $variante->fresh()->es_plantilla_qa);
    }

    // ── Comando ───────────────────────────────────────────────────────────────

    public function test_el_comando_simula_por_defecto_y_solo_ejecuta_con_super_admin_y_motivo(): void
    {
        $lote = $this->base(1);
        $p = $lote->plantilla;

        $this->assertSame(0, Artisan::call('credential-flow:plantilla-archivar', ['plantilla' => $p->id]));
        $this->assertStringContainsString('SIMULACIÓN', Artisan::output());
        $this->assertFalse($p->fresh()->estaArchivada());

        $this->assertSame(1, Artisan::call('credential-flow:plantilla-archivar', ['plantilla' => $p->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--ejecutar' => true]));
        $this->assertFalse($p->fresh()->estaArchivada());
        $this->assertSame(1, Artisan::call('credential-flow:plantilla-archivar', ['plantilla' => $p->id, '--usuario' => $this->superAdmin()->id, '--ejecutar' => true]));
        $this->assertFalse($p->fresh()->estaArchivada());

        app('auth')->forgetGuards();
        $this->assertSame(0, Artisan::call('credential-flow:plantilla-archivar', ['plantilla' => $p->id, '--usuario' => $this->superAdmin()->id, '--motivo' => self::MOTIVO, '--ejecutar' => true]));
        $this->assertTrue($p->fresh()->estaArchivada());
        $this->assertTrue(Plantilla::findOrFail($p->variante_qa_id)->estaArchivada());
        $this->assertSame($this->superAdmin()->id, Movimiento::query()->where('descripcion', 'like', 'Se archivó%')->sole()->user_id, 'autor en la auditoría también desde la consola');
        $this->assertFalse(Auth::check(), 'la consola queda sin sesión');

        $this->assertSame(0, Artisan::call('credential-flow:plantilla-archivar', ['plantilla' => $p->id, '--restaurar' => true, '--usuario' => $this->superAdmin()->id, '--motivo' => 'Prueba de reversión del archivo', '--ejecutar' => true]));
        $this->assertFalse($p->fresh()->estaArchivada());
    }

    public function test_la_huella_de_programacion_de_una_plantilla_vigente_no_cambia(): void
    {
        // Las programaciones ya confirmadas antes de desplegar C2 siguen valiendo: la huella solo incluye el archivo cuando la plantilla está archivada.
        $lote = $this->base(2);
        $prog = $this->programar($lote, '2026-10-27 10:30');
        $this->travelTo(CarbonImmutable::create(2026, 10, 27, 10, 30, 0, 'America/Bogota'));
        app('auth')->forgetGuards();
        $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas'));
        $this->assertSame(EmisionProgramada::COMPLETADA, $prog->fresh()->estado);
        $this->assertSame(4, Emision::count());
        $this->assertSame(0, DB::table('cf_plantillas')->whereNotNull('archivada_at')->count());
    }
}
