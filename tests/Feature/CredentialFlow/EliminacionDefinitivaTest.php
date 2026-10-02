<?php

namespace Tests\Feature\CredentialFlow;

use App\Http\Controllers\CredentialFlow\EliminacionDefinitivaController;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Movimiento;
use App\Services\CredentialFlow\EliminacionDefinitivaService;
use App\Support\CredentialFlow\Eliminacion\EliminacionException;
use App\Support\CredentialFlow\Eliminacion\Papelera;
use App\Support\CredentialFlow\Eliminacion\RutasSeguras;
use App\Support\CredentialFlow\Emisiones\AlmacenEmisiones;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use LogicException;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;

/**
 * «Eliminar definitivamente»: base completa (participantes, emisiones vigentes/revocadas/versiones, PDFs) y plantilla
 * sin relaciones. Cubre el mapa de relaciones, la papelera con restauración, la seguridad de rutas, la auditoría y
 * la auditoría de integridad posterior.
 */
class EliminacionDefinitivaTest extends EmisionesTestCase
{
    protected function tearDown(): void
    {
        EliminacionDefinitivaService::$despuesDeMover = null;
        EliminacionDefinitivaService::$antesDeConfirmar = null;
        Papelera::$alMover = null;
        Papelera::$alRestaurar = null;
        Papelera::$alVaciar = null;
        EliminacionDefinitivaService::$antesDeLimpiarCarpeta = null;

        parent::tearDown();
    }

    // ── Utilidades ────────────────────────────────────────────────────────────────────────────────────────────

    /**
     * Base con 3 participantes y 4 emisiones: P1 revocada (v1), P2 reemitida (v1 revocada → v2 vigente), P3 vigente.
     *
     * @return array{0:Lote,1:Collection<int,Emision>}
     */
    private function baseCompleta(?Plantilla $plantilla = null): array
    {
        $lote = $this->loteCon(3, $plantilla);
        $ps = $lote->participantes()->orderBy('id')->get();
        foreach ($ps as $p) {
            $this->emitirPor($lote, $p)->assertCreated();
        }
        $e = Emision::where('lote_id', $lote->id)->orderBy('id')->get();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e[0]), ['motivo' => 'Motivo de prueba'])->assertOk();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $e[1]), ['motivo' => 'Corrección de datos'])->assertCreated();

        return [$lote->fresh(), Emision::where('lote_id', $lote->id)->orderBy('id')->get()];
    }

    private function eliminarLoteHttp(Lote $lote, string $confirmacion = 'ELIMINAR')
    {
        return $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy-definitivo', $lote->id), ['confirmacion' => $confirmacion]);
    }

    private function eliminarPlantillaHttp(Plantilla $plantilla, string $confirmacion = 'ELIMINAR')
    {
        return $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy-definitivo', $plantilla->id), ['confirmacion' => $confirmacion]);
    }

    private function servicio(): EliminacionDefinitivaService
    {
        return app(EliminacionDefinitivaService::class);
    }

    private function disco()
    {
        return Storage::disk('local');
    }

    /** Todo lo que hay bajo credential-flow (excepto lo que se indique): para comparar «antes» y «después». */
    private function archivosCredentialFlow(): array
    {
        $a = $this->disco()->allFiles('credential-flow');
        sort($a);

        return $a;
    }

    private function contenidos(array $rutas): array
    {
        return collect($rutas)->mapWithKeys(fn ($r) => [$r => hash('sha256', $this->disco()->get($r))])->all();
    }

    private function conteos(): array
    {
        return [
            'lotes' => DB::table('cf_lotes')->count(),
            'participantes' => DB::table('cf_participantes')->count(),
            'emisiones' => DB::table('cf_emisiones')->count(),
            'plantillas' => DB::table('cf_plantillas')->count(),
        ];
    }

    private function unaEmisionNuevaFalsa(int $loteId): void
    {
        $base = (array) DB::table('cf_emisiones')->where('lote_id', $loteId)->orderBy('id')->first();
        unset($base['id']);
        $uuid = (string) Str::uuid();
        DB::table('cf_emisiones')->insert(array_merge($base, [
            'codigo' => 'ZZZZZZZZZZZZZZZZZZZZ', 'version' => 99, 'reemplaza_id' => null, 'estado' => 'revocada',
            'participante_vigente' => null, 'pdf_archivo' => 'credential-flow/emisiones/'.substr($uuid, 0, 2).'/'.$uuid.'.pdf',
        ]));
    }

    // ── Base: casos de borrado ────────────────────────────────────────────────────────────────────────────────

    public function test_base_vacia_se_elimina_por_completo(): void
    {
        $lote = $this->loteCon(0);

        $this->eliminarLoteHttp($lote)->assertRedirect(route('credential-flow.lotes.index'))->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertSame(0, Movimiento::where('descripcion', 'like', '%definitivamente%')->first()->metadata['participantes']);
    }

    public function test_base_con_participantes_sin_emisiones_borra_base_y_participantes_incluidos_los_ocultos(): void
    {
        $lote = $this->loteCon(3);
        $lote->participantes()->first()->delete(); // participante ya eliminado «normalmente»
        $this->assertSame(2, $lote->participantes()->count());

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertSame(0, Participante::withTrashed()->where('lote_id', $lote->id)->count());
        $this->assertSame(3, Movimiento::where('descripcion', 'like', '%definitivamente%')->first()->metadata['participantes']);
    }

    public function test_base_con_emisiones_vigentes_revocadas_y_versiones_borra_todo_y_todos_los_pdf(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();

        // La cadena v1 revocada → v2 vigente existe de verdad (reemplaza_id) y hay 4 PDFs.
        $this->assertSame(4, $emisiones->count());
        $this->assertSame(2, $emisiones->where('estado', Emision::EMITIDA)->count());
        $this->assertSame($emisiones[1]->id, $emisiones[3]->reemplaza_id);
        $this->assertSame(4, collect($this->archivosDeEmisiones())->filter(fn ($f) => str_ends_with($f, '.pdf'))->count());

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertSame(0, Participante::withTrashed()->count());
        $this->assertSame(0, DB::table('cf_emisiones')->count());
        $this->assertSame([], $this->archivosDeEmisiones());
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
    }

    public function test_eliminar_una_base_no_borra_su_plantilla_y_luego_se_puede_purgar(): void
    {
        [$lote] = $this->baseCompleta();
        $plantilla = $lote->plantilla;
        $this->disco()->assertExists($plantilla->rutaPdfEsperada());

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertNotNull(Plantilla::find($plantilla->id));
        $this->disco()->assertExists($plantilla->rutaPdfEsperada());

        // Ahora la plantilla ya no tiene relaciones: se puede purgar.
        $this->eliminarPlantillaHttp($plantilla)->assertSessionHas('success');
        $this->assertNull(Plantilla::withTrashed()->find($plantilla->id));
        $this->disco()->assertMissing($plantilla->rutaPdfEsperada());
        $this->assertFalse($this->disco()->exists($plantilla->carpeta()));
    }

    public function test_solo_se_borra_la_base_indicada_y_sus_archivos(): void
    {
        $plantilla = $this->plantillaLista();
        [$a] = $this->baseCompleta($plantilla);
        [$b, $emisionesB] = $this->baseCompleta($plantilla);
        $rutasB = $emisionesB->pluck('pdf_archivo')->all();
        $hashesB = $this->contenidos($rutasB);

        $this->eliminarLoteHttp($a)->assertSessionHas('success');

        $this->assertNotNull(Lote::find($b->id));
        $this->assertSame(3, Participante::where('lote_id', $b->id)->count());
        $this->assertSame(4, Emision::where('lote_id', $b->id)->count());
        $this->assertSame($hashesB, $this->contenidos($rutasB));
        $this->assertCount(4, collect($this->archivosDeEmisiones())->filter(fn ($f) => str_ends_with($f, '.pdf')));
    }

    public function test_una_base_ya_eliminada_normalmente_se_puede_purgar_con_su_historial(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $this->emitirPor($lote, $p)->assertCreated();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', Emision::first()), ['motivo' => 'Motivo de prueba'])->assertOk();
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $lote->id))->assertSessionHas('success'); // soft delete normal

        $this->assertNotNull(Lote::withTrashed()->find($lote->id));
        $this->assertCount(1, collect($this->archivosDeEmisiones())->filter(fn ($f) => str_ends_with($f, '.pdf')));

        $this->eliminarLoteHttp(Lote::withTrashed()->find($lote->id))->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertSame(0, DB::table('cf_emisiones')->count());
        $this->assertSame([], $this->archivosDeEmisiones());
    }

    public function test_el_eliminar_normal_sigue_igual_y_la_emision_sigue_siendo_indeleble(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();

        // Con vigentes el «Eliminar» normal se sigue negando y no borra nada.
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $lote->id))->assertSessionHas('error');
        $this->assertNull(Lote::withTrashed()->find($lote->id)->deleted_at);
        $this->assertSame(4, DB::table('cf_emisiones')->count());

        // El modelo sigue protegido: la única vía de borrado es el servicio.
        $this->expectException(LogicException::class);
        $emisiones[0]->delete();
    }

    // ── Confirmación, permisos, resumen ───────────────────────────────────────────────────────────────────────

    public function test_sin_la_palabra_exacta_no_se_borra_nada(): void
    {
        [$lote] = $this->baseCompleta();
        $antes = $this->conteos();
        $archivos = $this->archivosCredentialFlow();

        foreach (['', 'eliminar', ' eliminar ', 'SI', 'Eliminar', 'ELIMINAR!'] as $intento) {
            $this->eliminarLoteHttp($lote, $intento)->assertSessionHasErrors('confirmacion');
        }
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy-definitivo', $lote->id))->assertSessionHasErrors('confirmacion');

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($archivos, $this->archivosCredentialFlow());
        $this->assertSame(0, Movimiento::where('descripcion', 'like', '%definitivamente%')->count());
    }

    public function test_solo_super_admin_y_admin_pueden(): void
    {
        $lote = $this->loteCon(1);

        $this->actingAs($this->comercial())->delete(route('credential-flow.lotes.destroy-definitivo', $lote->id), ['confirmacion' => 'ELIMINAR'])->assertForbidden();
        $this->actingAs($this->comercial())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->assertForbidden();
        $this->assertNotNull(Lote::find($lote->id));

        $this->actingAs($this->superAdmin())->delete(route('credential-flow.lotes.destroy-definitivo', $lote->id), ['confirmacion' => 'ELIMINAR'])->assertSessionHas('success');
        $this->assertNull(Lote::withTrashed()->find($lote->id));
    }

    public function test_el_resumen_cuenta_participantes_vigentes_historicos_archivos_y_bytes_reales(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $lote->participantes()->first()->delete(); // un oculto también cuenta
        $bytes = $emisiones->sum(fn ($e) => strlen($this->disco()->get($e->pdf_archivo)));

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->assertOk()->json('resumen');

        $this->assertSame(3, $r['participantes']);
        $this->assertSame(2, $r['vigentes']);
        $this->assertSame(2, $r['historicos']);
        $this->assertSame(4, $r['archivos']);
        $this->assertSame($bytes, $r['bytes']);
        $this->assertNull($r['bloqueada']);
        $this->assertSame($lote->nombre, $r['nombre']);
    }

    public function test_el_resumen_funciona_con_una_base_ya_eliminada_y_un_archivo_faltante_no_cuenta(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $this->disco()->delete($emisiones[0]->pdf_archivo); // PDF ya inexistente
        $lote->delete();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->assertOk()->json('resumen');
        $this->assertSame(3, $r['archivos']);

        // Una base «rota» también se puede purgar: lo que falta, falta.
        $this->eliminarLoteHttp($lote)->assertSessionHas('success');
        $this->assertNull(Lote::withTrashed()->find($lote->id));
    }

    public function test_una_base_inexistente_responde_404_y_no_hay_doble_borrado(): void
    {
        $lote = $this->loteCon(1);
        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->eliminarLoteHttp($lote)->assertNotFound();
        $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->assertNotFound();
        $this->assertSame(1, Movimiento::where('descripcion', 'like', '%definitivamente%')->count());
    }

    public function test_el_formato_de_megabytes_es_legible(): void
    {
        $this->assertSame('0 MB', EliminacionDefinitivaController::megabytes(0));
        $this->assertSame('menos de 0,1 MB', EliminacionDefinitivaController::megabytes(50_000));
        $this->assertSame('1,5 MB', EliminacionDefinitivaController::megabytes((int) (1.5 * 1048576)));
    }

    // ── Auditoría ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_el_movimiento_guarda_solo_metadatos_utiles_sin_datos_personales(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $bytes = $emisiones->sum(fn ($e) => strlen($this->disco()->get($e->pdf_archivo)));
        $nombresYDocumentos = array_merge($lote->participantes->pluck('nombre_completo')->all(), $lote->participantes->pluck('documento')->all(), [$lote->nombre]);

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $m = Movimiento::where('descripcion', 'like', '%definitivamente%')->sole();
        $this->assertSame('eliminacion', $m->tipo);
        $this->assertSame('credential-flow', $m->modulo);
        $this->assertSame($this->admin()->id, $m->user_id);
        $this->assertSame($lote->id, $m->metadata['lote_id']);
        $this->assertSame(3, $m->metadata['participantes']);
        $this->assertSame(4, $m->metadata['emisiones']);
        $this->assertSame(4, $m->metadata['archivos']);
        $this->assertSame($bytes, $m->metadata['bytes_liberados']);
        $serializado = json_encode($m->toArray(), JSON_UNESCAPED_UNICODE);
        foreach ($nombresYDocumentos as $dato) {
            $this->assertStringNotContainsString((string) $dato, $serializado);
        }
        // La auditoría sobrevive a la base: no hay clave foránea hacia cf_lotes.
        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertNotNull(Movimiento::find($m->id));
    }

    public function test_la_auditoria_de_integridad_queda_limpia_tras_eliminar_una_base_y_una_plantilla(): void
    {
        [$lote] = $this->baseCompleta();
        $otra = $this->loteCon(1);
        $this->emitirPor($otra, $otra->participantes()->first())->assertCreated();

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);

        $sinRelaciones = $this->plantillaLista();
        $this->eliminarPlantillaHttp($sinRelaciones)->assertSessionHas('success');
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);
    }

    public function test_la_auditoria_detecta_residuos_de_papelera_y_carpetas_de_plantilla_huerfanas(): void
    {
        $this->disco()->put(RutasSeguras::PAPELERA.'/'.Str::uuid().'/0', 'x');
        $this->disco()->put('credential-flow/plantillas/9999/base.pdf', 'x');

        $this->artisan('credential-flow:verificar-emisiones')
            ->expectsOutputToContain('Residuo de una eliminación definitiva sin terminar')
            ->expectsOutputToContain('Carpeta de plantilla sin registro')
            ->assertExitCode(1);
    }

    public function test_una_carpeta_de_plantilla_vacia_no_es_un_problema(): void
    {
        $this->disco()->makeDirectory('credential-flow/plantillas/9998');

        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);
    }

    // ── Plantilla ─────────────────────────────────────────────────────────────────────────────────────────────

    public function test_plantilla_sin_relaciones_se_elimina_con_registro_pdf_diseno_y_carpeta(): void
    {
        $p = $this->plantillaLista();
        $this->assertNotEmpty($p->diseno['elements']);
        $bytes = strlen($this->disco()->get($p->rutaPdfEsperada()));

        $this->eliminarPlantillaHttp($p)->assertSessionHas('success');

        $this->assertNull(Plantilla::withTrashed()->find($p->id)); // el diseño vive en la fila: desaparece con ella
        $this->assertFalse($this->disco()->exists($p->carpeta()));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
        $m = Movimiento::where('descripcion', 'like', '%definitivamente una plantilla%')->sole();
        $this->assertSame($bytes, $m->metadata['bytes_liberados']);
        $this->assertSame($p->id, $m->metadata['plantilla_id']);
        $this->assertStringNotContainsString($p->nombre, json_encode($m->toArray(), JSON_UNESCAPED_UNICODE));
    }

    public function test_plantilla_con_bases_o_certificados_queda_bloqueada_y_no_se_toca(): void
    {
        $con = $this->plantillaLista();
        $lote = $this->loteCon(1, $con);
        $archivos = $this->archivosCredentialFlow();

        $this->eliminarPlantillaHttp($con)->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('error', 'Esta plantilla todavía está relacionada con bases o certificados. Elimínalos primero.');
        $this->assertNotNull(Plantilla::find($con->id));
        $this->assertSame($archivos, $this->archivosCredentialFlow());

        // Una base eliminada «normalmente» sigue relacionada (la fila existe): también bloquea.
        $lote->delete();
        $this->eliminarPlantillaHttp($con)->assertSessionHas('error');
        $this->assertNotNull(Plantilla::find($con->id));

        // Con certificados (incluso revocados) también.
        $conEmision = $this->plantillaLista();
        $l2 = $this->loteCon(1, $conEmision);
        $this->emitirPor($l2, $l2->participantes()->first())->assertCreated();
        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.plantillas.eliminacion.resumen', $conEmision->id))->assertOk()->json('resumen');
        $this->assertSame(1, $r['bases']);
        $this->assertSame(1, $r['certificados']);
        $this->assertNotNull($r['bloqueada']);
        $this->eliminarPlantillaHttp($conEmision)->assertSessionHas('error');
        $this->assertNotNull(Plantilla::find($conEmision->id));
    }

    public function test_el_resumen_de_una_plantilla_libre_no_esta_bloqueado_e_incluye_el_espacio(): void
    {
        $p = $this->plantillaLista();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.plantillas.eliminacion.resumen', $p->id))->assertOk()->json('resumen');

        $this->assertNull($r['bloqueada']);
        $this->assertSame(0, $r['bases']);
        $this->assertSame(strlen($this->disco()->get($p->rutaPdfEsperada())), $r['bytes']);
    }

    public function test_plantilla_ya_eliminada_normalmente_se_puede_purgar_con_o_sin_carpeta(): void
    {
        // Con carpeta: eliminada lógicamente pero el archivo sigue (p. ej. fallo al borrarlo en su momento).
        $conCarpeta = $this->plantillaLista();
        $conCarpeta->delete();
        $this->assertNotNull(Plantilla::withTrashed()->find($conCarpeta->id));
        $this->eliminarPlantillaHttp(Plantilla::withTrashed()->find($conCarpeta->id))->assertSessionHas('success');
        $this->assertNull(Plantilla::withTrashed()->find($conCarpeta->id));
        $this->assertFalse($this->disco()->exists($conCarpeta->carpeta()));

        // Caso de producción: eliminada normalmente, la carpeta ya no existe, la fila quedó con deleted_at.
        $p = $this->crearPlantilla(['nombre' => 'QA eliminada']);
        $this->actingAs($this->admin())->delete(route('credential-flow.plantillas.destroy', $p->id))->assertSessionHas('success');
        $this->assertFalse($this->disco()->exists($p->carpeta()));
        $this->assertNotNull(Plantilla::withTrashed()->find($p->id)->deleted_at);

        $this->eliminarPlantillaHttp(Plantilla::withTrashed()->find($p->id))->assertSessionHas('success');
        $this->assertNull(Plantilla::withTrashed()->find($p->id));
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);
    }

    // ── Limpieza final tras el commit (la plantilla ya no existe) ────────────────────────────────────────────

    public function test_plantilla_eliminada_con_limpieza_final_correcta_responde_exito_limpio(): void
    {
        $p = $this->plantillaLista();
        Log::spy();

        $this->eliminarPlantillaHttp($p)
            ->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'se eliminó definitivamente') && ! str_contains($m, 'pendiente'))
            ->assertSessionMissing('error');

        $this->assertNull(Plantilla::withTrashed()->find($p->id));
        $this->assertSame([], $this->disco()->allFiles('credential-flow/plantillas'));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
        Log::shouldNotHaveReceived('error');
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);
    }

    public function test_si_falla_borrar_la_carpeta_despues_del_commit_la_plantilla_sigue_eliminada_y_el_residuo_se_detecta(): void
    {
        $p = $this->plantillaLista();
        Log::spy();

        // Falla la limpieza FINAL de la carpeta y deja algo dentro (p. ej. un archivo que apareció tras el movimiento).
        EliminacionDefinitivaService::$antesDeLimpiarCarpeta = function (string $carpeta) {
            $this->disco()->put($carpeta.'/residuo.tmp', 'x');

            throw new RuntimeException('no se pudo borrar la carpeta');
        };

        $this->eliminarPlantillaHttp($p)
            ->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'se eliminó definitivamente') && str_contains($m, 'pendiente de limpieza'))
            ->assertSessionMissing('success');

        // No se revierte nada ni se simula que sigue existiendo: ni en la base, ni en el listado.
        $this->assertNull(Plantilla::withTrashed()->find($p->id));
        $this->assertSame(1, Movimiento::where('descripcion', 'like', '%definitivamente una plantilla%')->count());
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.index'))->assertInertia(fn ($page) => $page->has('plantillas', 0)->has('eliminadas', 0));
        $this->actingAs($this->admin())->getJson(route('credential-flow.plantillas.eliminacion.resumen', $p->id))->assertNotFound();

        // Log con contexto mínimo: id, operación, ruta relativa segura y excepción (sin datos personales).
        Log::shouldHaveReceived('error')->withArgs(function ($mensaje, $ctx = []) use ($p) {
            return str_contains($mensaje, 'limpieza pendiente')
                && $ctx['plantilla_id'] === $p->id
                && preg_match('/^[0-9a-f-]{36}$/', $ctx['operacion']) === 1
                && $ctx['ruta'] === $p->carpeta()
                && str_contains($ctx['error'], 'no se pudo borrar la carpeta')
                && ! str_contains(json_encode($ctx), $p->nombre);
        })->once();

        // La auditoría lo detecta (carpeta con archivos y sin plantilla) y no hay residuo en la papelera.
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Carpeta de plantilla sin registro en la base')->assertExitCode(1);
    }

    public function test_si_falla_vaciar_la_papelera_despues_del_commit_no_hay_error_generico_y_la_auditoria_lo_detecta(): void
    {
        $p = $this->plantillaLista();
        Log::spy();

        Papelera::$alVaciar = fn () => throw new RuntimeException('no se pudo vaciar');

        $this->eliminarPlantillaHttp($p)
            ->assertRedirect(route('credential-flow.plantillas.index'))
            ->assertSessionHas('error', fn ($m) => str_contains($m, 'se eliminó definitivamente') && str_contains($m, 'pendiente de limpieza'));

        $this->assertNull(Plantilla::withTrashed()->find($p->id));
        $this->assertFalse($this->disco()->exists($p->carpeta().'/base.pdf'));
        $this->assertCount(1, $this->disco()->allFiles(RutasSeguras::PAPELERA)); // el archivo sigue en la papelera de esa operación

        Log::shouldHaveReceived('error')->withArgs(function ($mensaje, $ctx = []) use ($p) {
            return str_contains($mensaje, 'limpieza pendiente')
                && $ctx['plantilla_id'] === $p->id
                && preg_match('/^[0-9a-f-]{36}$/', $ctx['operacion']) === 1
                && str_starts_with($ctx['ruta'], RutasSeguras::PAPELERA.'/'.$ctx['operacion']);
        })->once();

        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Residuo de una eliminación definitiva sin terminar')->assertExitCode(1);
    }

    public function test_los_listados_ofrecen_las_bases_y_plantillas_eliminadas_anteriormente(): void
    {
        $lote = $this->loteCon(2);
        $lote->delete();
        $plantilla = $this->crearPlantilla(['nombre' => 'Vieja']);
        $plantilla->delete();

        $this->actingAs($this->admin())->get(route('credential-flow.lotes.index'))->assertInertia(
            fn ($page) => $page->has('eliminadas', 1)->where('eliminadas.0.id', $lote->id)->where('eliminadas.0.participantes', 2)
        );
        $this->actingAs($this->admin())->get(route('credential-flow.plantillas.index'))->assertInertia(
            fn ($page) => $page->has('eliminadas', 1)->where('eliminadas.0.id', $plantilla->id)
        );
    }

    // ── Rollback: la base falla, el disco se restaura ─────────────────────────────────────────────────────────

    public function test_si_falla_la_base_de_datos_los_archivos_vuelven_a_su_sitio_y_nada_cambia(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $rutas = $emisiones->pluck('pdf_archivo')->all();
        $hashes = $this->contenidos($rutas);
        $antes = $this->conteos();
        $movimientos = Movimiento::count();

        // Falla justo antes del commit, con los archivos YA en la papelera y los registros ya borrados.
        EliminacionDefinitivaService::$antesDeConfirmar = function ($lote, Papelera $papelera) {
            $this->assertSame(4, $papelera->cantidad());
            $this->assertCount(4, $this->disco()->allFiles(RutasSeguras::PAPELERA));
            throw new RuntimeException('fallo simulado de la base de datos');
        };

        try {
            $this->servicio()->eliminarLote($lote->id);
            $this->fail('Debía lanzar.');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::ERROR_GENERAL, $e->codigo);
        }

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($hashes, $this->contenidos($rutas));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
        $this->assertSame($movimientos, Movimiento::count());
        $this->artisan('credential-flow:verificar-emisiones')->expectsOutputToContain('Todo correcto')->assertExitCode(0);
    }

    public function test_si_falla_mover_un_archivo_no_se_toca_la_base_y_lo_ya_movido_se_restaura(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $rutas = $emisiones->pluck('pdf_archivo')->all();
        $hashes = $this->contenidos($rutas);
        $antes = $this->conteos();

        Papelera::$alMover = function (string $origen, string $destino, int $indice) {
            if ($indice === 2) {
                throw new RuntimeException('disco lleno simulado');
            }
        };

        $respuesta = $this->eliminarLoteHttp($lote);

        $respuesta->assertSessionHas('error');
        $this->assertSame($antes, $this->conteos());
        $this->assertSame($hashes, $this->contenidos($rutas));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
        $this->assertSame(0, Movimiento::where('descripcion', 'like', '%definitivamente%')->count());
    }

    public function test_fallo_parcial_al_restaurar_conserva_el_archivo_en_la_papelera_y_la_auditoria_lo_senala(): void
    {
        $lote = $this->loteCon(2);
        foreach ($lote->participantes as $p) {
            $this->emitirPor($lote, $p)->assertCreated();
        }
        $emisiones = Emision::where('lote_id', $lote->id)->orderBy('id')->get();
        $segundo = $emisiones[1]->pdf_archivo;

        EliminacionDefinitivaService::$antesDeConfirmar = fn () => throw new RuntimeException('fallo simulado');
        Papelera::$alRestaurar = function (string $origen) use ($segundo) {
            if ($origen === $segundo) {
                throw new RuntimeException('no se pudo devolver');
            }
        };

        try {
            $this->servicio()->eliminarLote($lote->id);
            $this->fail('Debía lanzar.');
        } catch (EliminacionException) {
        }

        // La base de datos está intacta, el primer archivo volvió y el segundo NO se pierde: queda en la papelera.
        $this->assertSame(2, DB::table('cf_emisiones')->count());
        $this->disco()->assertExists($emisiones[0]->pdf_archivo);
        $this->disco()->assertMissing($segundo);
        $this->assertCount(1, $this->disco()->allFiles(RutasSeguras::PAPELERA));
        $this->artisan('credential-flow:verificar-emisiones')
            ->expectsOutputToContain('Residuo de una eliminación definitiva sin terminar')
            ->expectsOutputToContain('falta el archivo PDF')
            ->assertExitCode(1);
    }

    public function test_si_una_emision_cambia_mientras_se_prepara_la_eliminacion_no_se_borra_nada(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $rutas = $emisiones->pluck('pdf_archivo')->all();
        $hashes = $this->contenidos($rutas);
        $antes = $this->conteos();

        // Entre «mover los archivos» y «borrar los registros» aparece una emisión nueva en la base.
        EliminacionDefinitivaService::$despuesDeMover = fn ($lote) => $this->unaEmisionNuevaFalsa($lote->id);

        try {
            $this->servicio()->eliminarLote($lote->id);
            $this->fail('Debía lanzar.');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::CAMBIO_CONCURRENTE, $e->codigo);
        }

        $this->assertSame($antes, $this->conteos()); // la emisión nueva se revirtió con la transacción
        $this->assertSame($hashes, $this->contenidos($rutas));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
    }

    // ── Seguridad de rutas ────────────────────────────────────────────────────────────────────────────────────

    public static function rutasInvalidas(): array
    {
        $u = '0a1b2c3d-1111-4222-8333-444455556666';

        return [
            'traversal' => ['credential-flow/emisiones/0a/../../../.env'],
            'traversal en medio' => ["credential-flow/emisiones/0a/../0a/{$u}.pdf"],
            'fuera de emisiones' => ['credential-flow/plantillas/1/base.pdf'],
            'otro módulo' => ['uploads/cert.pdf'],
            'backups' => ['_backups_credential_flow/x.tar.gz'],
            'absoluta' => ['/etc/passwd'],
            'windows' => ['credential-flow\\emisiones\\0a\\x.pdf'],
            'carpeta incorrecta' => ["credential-flow/emisiones/ff/{$u}.pdf"],
            'sin extensión' => ["credential-flow/emisiones/0a/{$u}"],
            'staging' => ["credential-flow/emisiones/.staging/{$u}/0.pdf"],
            'nul' => ["credential-flow/emisiones/0a/{$u}.pdf\0.png"],
            'vacía' => [''],
        ];
    }

    #[DataProvider('rutasInvalidas')]
    public function test_las_rutas_que_no_son_pdfs_de_emision_se_rechazan(string $ruta): void
    {
        $this->expectException(EliminacionException::class);
        RutasSeguras::emision($ruta);
    }

    public function test_una_ruta_valida_de_emision_se_acepta(): void
    {
        $ruta = AlmacenEmisiones::rutaNueva();
        $this->assertSame($ruta, RutasSeguras::emision($ruta));
    }

    #[DataProvider('rutasInvalidas')]
    public function test_una_emision_con_ruta_fuera_de_credential_flow_bloquea_toda_la_eliminacion(string $ruta): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $intocable = 'credential-flow/plantillas/1/base.pdf';
        $this->disco()->put($intocable, 'NO TOCAR');
        $this->disco()->put('_backups_credential_flow/x.tar.gz', 'NO TOCAR');
        DB::table('cf_emisiones')->where('id', $emisiones[2]->id)->update(['pdf_archivo' => $ruta]);
        $antes = $this->conteos();
        $archivos = $this->archivosCredentialFlow();

        $respuesta = $this->eliminarLoteHttp($lote);

        $respuesta->assertSessionHas('error');
        $this->assertSame($antes, $this->conteos());
        $this->assertSame($archivos, $this->archivosCredentialFlow());
        $this->assertSame('NO TOCAR', $this->disco()->get($intocable));
        $this->assertSame('NO TOCAR', $this->disco()->get('_backups_credential_flow/x.tar.gz'));
        $this->assertNotNull($this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->json('resumen.bloqueada'));
    }

    public function test_un_archivo_que_tambien_usa_otra_base_nunca_se_borra(): void
    {
        $plantilla = $this->plantillaLista();
        $a = $this->loteCon(1, $plantilla);
        $b = $this->loteCon(1, $plantilla);
        $this->emitirPor($a, $a->participantes()->first())->assertCreated();
        $this->emitirPor($b, $b->participantes()->first())->assertCreated();
        $ea = Emision::where('lote_id', $a->id)->first();
        $eb = Emision::where('lote_id', $b->id)->first();
        // Corrupción simulada: la emisión de B apunta al PDF de A.
        DB::table('cf_emisiones')->where('id', $eb->id)->update(['pdf_archivo' => $ea->pdf_archivo]);
        $antes = $this->conteos();

        $this->eliminarLoteHttp($a)->assertSessionHas('error');
        $this->eliminarLoteHttp($b)->assertSessionHas('error', fn ($m) => true);

        $this->assertSame($antes, $this->conteos());
        $this->disco()->assertExists($ea->pdf_archivo);
    }

    public function test_un_enlace_simbolico_dentro_del_almacen_no_se_sigue(): void
    {
        $lote = $this->loteCon(1);
        $this->emitirPor($lote, $lote->participantes()->first())->assertCreated();
        $e = Emision::first();
        $externo = tempnam(sys_get_temp_dir(), 'cfext');
        file_put_contents($externo, 'NO TOCAR');
        $fisica = $this->disco()->path($e->pdf_archivo);
        unlink($fisica);
        if (! @symlink($externo, $fisica)) {
            @unlink($externo);
            $this->markTestSkipped('Este sistema no permite crear enlaces simbólicos.');
        }

        $this->eliminarLoteHttp($lote)->assertSessionHas('error');

        $this->assertNotNull(Lote::find($lote->id));
        $this->assertSame('NO TOCAR', file_get_contents($externo));
        $this->assertTrue(is_link($fisica));
        @unlink($fisica);
        @unlink($externo);
    }

    public function test_una_carpeta_intermedia_enlazada_fuera_del_almacen_no_se_sigue(): void
    {
        $lote = $this->loteCon(1);
        $this->emitirPor($lote, $lote->participantes()->first())->assertCreated();
        $e = Emision::first();
        $carpeta = dirname($this->disco()->path($e->pdf_archivo));
        $externo = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cfext_'.Str::random(8);
        mkdir($externo);
        copy($this->disco()->path($e->pdf_archivo), $externo.DIRECTORY_SEPARATOR.basename($e->pdf_archivo));
        $limpiar = function () use ($carpeta, $externo) {
            if (PHP_OS_FAMILY === 'Windows') {
                @rmdir($carpeta); // quitar el enlace (no borra el destino)
            }
            array_map('unlink', glob($externo.DIRECTORY_SEPARATOR.'*') ?: []);
            @rmdir($externo);
        };

        // La carpeta aa/ del PDF pasa a ser un enlace a otro lugar (junction en Windows, enlace simbólico en otros).
        $this->disco()->deleteDirectory(dirname($e->pdf_archivo));
        $creado = PHP_OS_FAMILY === 'Windows'
            ? (bool) shell_exec('cmd /c mklink /J '.escapeshellarg($carpeta).' '.escapeshellarg($externo).' 2>&1') && is_dir($carpeta)
            : @symlink($externo, $carpeta);
        if (! $creado) {
            $limpiar();
            $this->markTestSkipped('No se pudo crear el enlace de carpeta.');
        }

        $this->eliminarLoteHttp($lote)->assertSessionHas('error');

        $this->assertNotNull(Lote::find($lote->id));
        $this->assertFileExists($externo.DIRECTORY_SEPARATOR.basename($e->pdf_archivo));
        $limpiar();
    }

    public function test_las_rutas_fisicas_se_resuelven_solo_dentro_de_su_raiz(): void
    {
        $this->disco()->put('credential-flow/emisiones/0a/0a000000-0000-4000-8000-000000000001.pdf', 'x');

        $this->assertNotNull(RutasSeguras::fisica('credential-flow/emisiones/0a/0a000000-0000-4000-8000-000000000001.pdf', RutasSeguras::EMISIONES));
        $this->assertNull(RutasSeguras::fisica('credential-flow/emisiones/0a/0a000000-0000-4000-8000-0000000000ff.pdf', RutasSeguras::EMISIONES)); // no existe

        foreach (['credential-flow/emisiones/../plantillas/x', 'credential-flow/otra/x.pdf', 'credential-flow/emisiones/0a//x.pdf', 'credential-flow/emisiones/./x.pdf'] as $mala) {
            try {
                RutasSeguras::fisica($mala, RutasSeguras::EMISIONES);
                $this->fail("Debía rechazar {$mala}");
            } catch (EliminacionException) {
                $this->assertTrue(true);
            }
        }
    }

    // ── Temporales y lo que NO se toca ────────────────────────────────────────────────────────────────────────

    public function test_solo_se_borra_el_staging_de_las_operaciones_de_esa_base_y_nada_ajeno(): void
    {
        $plantilla = $this->plantillaLista();
        $lote = $this->loteCon(2, $plantilla);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();
        $operacion = Emision::where('lote_id', $lote->id)->value('operacion');
        $this->assertNotNull($operacion);

        $propio = AlmacenEmisiones::directorioStaging($operacion).'/0.pdf';
        $ajeno = AlmacenEmisiones::directorioStaging((string) Str::uuid()).'/0.pdf';
        $this->disco()->put($propio, 'residuo de esta base');
        $this->disco()->put($ajeno, 'staging de otra operación');
        $this->disco()->put('credential-flow/emisiones/.tmp/'.Str::uuid().'.tmp', 'temporal de otra escritura');
        $this->disco()->put('_backups_credential_flow/x.tar.gz', 'backup');
        $this->disco()->put('uploads/otro.pdf', 'otro módulo');
        $this->disco()->put('credential-flow/otra-cosa/dato.txt', 'ajeno al módulo de emisiones');

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->json('resumen');
        $this->assertSame(3, $r['archivos']); // 2 PDFs + 1 residuo propio

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->disco()->assertMissing($propio);
        $this->assertSame('staging de otra operación', $this->disco()->get($ajeno));
        $this->assertCount(1, $this->disco()->allFiles('credential-flow/emisiones/.tmp'));
        $this->assertSame('backup', $this->disco()->get('_backups_credential_flow/x.tar.gz'));
        $this->assertSame('otro módulo', $this->disco()->get('uploads/otro.pdf'));
        $this->assertSame('ajeno al módulo de emisiones', $this->disco()->get('credential-flow/otra-cosa/dato.txt'));
        $this->assertNotNull(Plantilla::find($plantilla->id));
    }

    public function test_el_resumen_y_el_borrado_no_cambian_el_comportamiento_de_zip_y_pdfs_de_prueba(): void
    {
        // Los ZIP y los PDF de prueba/vista previa son temporales de la petición: no hay nada persistente que borrar.
        [$lote] = $this->baseCompleta();
        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertSame([], $this->disco()->allFiles('credential-flow/emisiones'));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
    }
}
