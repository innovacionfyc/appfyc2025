<?php

namespace Tests\Feature\CredentialFlow;

use App\Http\Controllers\CredentialFlow\EliminacionDefinitivaController;
use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Descarga;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
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
    /** Tablas del modelo aditivo (Fase 1/1.1) que el servicio consulta: cf_eventos, certificados históricos, descargas y correos. */
    private const FASE_1 = [
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
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->artisan('migrate', ['--path' => self::FASE_1, '--force' => true])->assertExitCode(0);
    }

    protected function tearDown(): void
    {
        EliminacionDefinitivaService::$despuesDeBorrarCorreos = null;
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
            'correos' => DB::table('cf_correos')->count(),
            'descargas' => DB::table('cf_descargas')->count(),
            'legado' => DB::table('cf_certificados_legado')->count(),
        ];
    }

    /** Copia una emisión existente (de cualquier base) como emisión revocada de OTRO participante. Datos sintéticos. */
    private function unaEmisionNuevaFalsa(int $participanteId, int $loteId, ?int $plantillaId = null): int
    {
        $base = (array) DB::table('cf_emisiones')->orderBy('id')->first();
        unset($base['id']);
        $uuid = (string) Str::uuid();

        return DB::table('cf_emisiones')->insertGetId(array_merge($base, [
            'codigo' => strtoupper(substr(str_replace('-', '', $uuid), 0, 20)), 'participante_id' => $participanteId, 'lote_id' => $loteId, 'version' => 1,
            'reemplaza_id' => null, 'estado' => 'revocada', 'participante_vigente' => null,
            'pdf_archivo' => 'credential-flow/emisiones/'.substr($uuid, 0, 2).'/'.$uuid.'.pdf',
        ] + ($plantillaId !== null ? ['plantilla_id' => $plantillaId] : [])));
    }

    /** Añade correos sintéticos (example.test) a un participante. */
    private function conCorreos(Participante $p, int $cuantos): Participante
    {
        for ($i = 1; $i <= $cuantos; $i++) {
            Correo::create(['participante_id' => $p->id, 'correo' => "p{$p->id}-{$i}@example.test", 'origen' => Correo::ORIGEN_CREDENTIAL_FLOW, 'orden' => $i]);
        }

        return $p;
    }

    private function certificadoLegado(?int $eventoId = null): CertificadoLegado
    {
        $evento = $eventoId !== null ? EventoCertificacion::findOrFail($eventoId) : EventoCertificacion::create(['nombre' => 'Evento '.(++$this->n), 'nombre_normalizado' => 'evento '.$this->n, 'origen' => 'legado']);

        return CertificadoLegado::create(['evento_id' => $evento->id, 'documento_clave' => (string) (900000 + $this->n), 'nombre_completo' => 'HISTORICO PRUEBA', 'snapshot_legado' => []]);
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

    // ── A, B, C: base sin historial; los correos se borran de forma explícita y atómica ────────────────────

    public function test_a_base_sin_historial_y_participantes_sin_correos_se_elimina(): void
    {
        $lote = $this->loteCon(2);
        $this->assertSame(0, DB::table('cf_correos')->count());

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertSame(0, Participante::withTrashed()->count());
        $this->assertSame(0, Movimiento::where('descripcion', 'like', '%definitivamente%')->sole()->metadata['correos']);
    }

    public function test_b_participante_con_un_correo_se_elimina_junto_con_su_correo(): void
    {
        $lote = $this->loteCon(1);
        $this->conCorreos($lote->participantes()->first(), 1);
        $this->assertSame(1, DB::table('cf_correos')->count());

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertSame(0, Participante::withTrashed()->count());
        $this->assertSame(0, DB::table('cf_correos')->count());
        $this->assertSame(1, Movimiento::where('descripcion', 'like', '%definitivamente%')->sole()->metadata['correos']);
    }

    public function test_c_participantes_con_varios_correos_borran_todos_los_correos_y_luego_los_participantes(): void
    {
        $lote = $this->loteCon(3);
        $ps = $lote->participantes()->orderBy('id')->get();
        $this->conCorreos($ps[0], 4);
        $this->conCorreos($ps[1], 2);
        $ps[2]->delete(); // participante ya eliminado «normalmente», sin correos: también se borra
        $this->assertSame(6, DB::table('cf_correos')->count());

        // El orden es correos → participantes → base: al borrar los participantes ya no queda ningún correo suyo.
        $visto = null;
        EliminacionDefinitivaService::$despuesDeBorrarCorreos = function () use (&$visto) {
            $visto = ['correos' => DB::table('cf_correos')->count(), 'participantes' => DB::table('cf_participantes')->count()];
        };

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertSame(['correos' => 0, 'participantes' => 3], $visto);
        $this->assertSame([0, 0, 0], [DB::table('cf_correos')->count(), Participante::withTrashed()->count(), DB::table('cf_lotes')->count()]);
        $this->assertSame(6, Movimiento::where('descripcion', 'like', '%definitivamente%')->sole()->metadata['correos']);
    }

    public function test_los_correos_de_otra_base_y_los_de_certificados_historicos_no_se_tocan(): void
    {
        $a = $this->loteCon(1);
        $b = $this->loteCon(1);
        $this->conCorreos($a->participantes()->first(), 2);
        $this->conCorreos($b->participantes()->first(), 3);
        $legado = $this->certificadoLegado();
        Correo::create(['certificado_legado_id' => $legado->id, 'correo' => 'historico@example.test', 'origen' => Correo::ORIGEN_LEGADO]);

        $this->eliminarLoteHttp($a)->assertSessionHas('success');

        $this->assertSame(3, Correo::whereNotNull('participante_id')->count(), 'Solo los de la base eliminada');
        $this->assertSame(1, Correo::whereNotNull('certificado_legado_id')->count());
        $this->assertSame(1, $legado->fresh()->correos()->count());
    }

    // ── D, E, F: la historia bloquea, con mensajes humanos y sin tocar nada ──────────────────────────────

    public function test_d_participante_con_emision_vigente_bloquea_la_eliminacion_de_la_base(): void
    {
        $lote = $this->loteCon(2);
        $p = $lote->participantes()->orderBy('id')->first();
        $this->conCorreos($p, 2);
        $this->emitirPor($lote, $p)->assertCreated();
        $antes = $this->conteos();
        $archivos = $this->archivosCredentialFlow();

        $this->eliminarLoteHttp($lote)->assertSessionHas('error', EliminacionException::conHistorial()->getMessage());

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($archivos, $this->archivosCredentialFlow());
        $this->assertNotNull(Lote::find($lote->id));
        $this->assertSame(0, Movimiento::where('descripcion', 'like', '%definitivamente%')->count());
        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->json('resumen');
        $this->assertSame(EliminacionException::conHistorial()->getMessage(), $r['bloqueada']);
        $this->assertSame([2, 1, 0, 2], [$r['participantes'], $r['vigentes'], $r['historicos'], $r['correos']]);
    }

    public function test_base_con_emisiones_revocadas_y_versiones_tambien_queda_bloqueada(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();

        // La cadena v1 revocada → v2 vigente existe de verdad (reemplaza_id) y hay 4 PDFs: todo es historial.
        $this->assertSame(4, $emisiones->count());
        $this->assertSame($emisiones[1]->id, $emisiones[3]->reemplaza_id);
        $antes = $this->conteos();
        $archivos = $this->archivosCredentialFlow();
        $hashes = $this->contenidos($emisiones->pluck('pdf_archivo')->all());

        $this->eliminarLoteHttp($lote)->assertSessionHas('error');

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($archivos, $this->archivosCredentialFlow());
        $this->assertSame($hashes, $this->contenidos($emisiones->pluck('pdf_archivo')->all()));
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));
    }

    public function test_e_emision_con_descarga_bloquea_con_mensaje_humano(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        Descarga::create(['emision_id' => $emisiones[2]->id, 'via' => Descarga::VIA_PORTAL]);
        $antes = $this->conteos();

        $this->eliminarLoteHttp($lote)->assertSessionHas('error', EliminacionException::conDescargas()->getMessage());

        $this->assertSame($antes, $this->conteos());
        $this->assertSame(1, DB::table('cf_descargas')->count());
        $this->assertNotNull(Lote::find($lote->id));
    }

    public function test_una_descarga_ligada_solo_al_participante_tambien_bloquea(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $this->conCorreos($p, 1);
        Descarga::create(['certificado_legado_id' => $this->certificadoLegado()->id, 'participante_id' => $p->id, 'via' => Descarga::VIA_ADMIN]);
        $antes = $this->conteos();

        $this->eliminarLoteHttp($lote)->assertSessionHas('error', EliminacionException::conDescargas()->getMessage());

        $this->assertSame($antes, $this->conteos());
    }

    public function test_f_emision_referenciada_por_un_certificado_legado_reemplazado_bloquea(): void
    {
        [$lote, $emisiones] = $this->baseCompleta();
        $legado = $this->certificadoLegado();
        $legado->update(['estado' => CertificadoLegado::ESTADO_REEMPLAZADO, 'reemplazado_por_emision_id' => $emisiones[0]->id]);
        $antes = $this->conteos();

        $this->eliminarLoteHttp($lote)->assertSessionHas('error', EliminacionException::reemplazaHistoricos()->getMessage());

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($emisiones[0]->id, $legado->fresh()->reemplazado_por_emision_id);
    }

    public function test_un_certificado_historico_del_evento_de_la_base_bloquea_incluso_sin_emisiones(): void
    {
        $evento = EventoCertificacion::create(['nombre' => 'Evento X', 'nombre_normalizado' => 'evento x', 'origen' => 'legado']);
        $lote = $this->loteCon(1);
        $lote->update(['evento_id' => $evento->id]);
        $this->certificadoLegado($evento->id);
        $antes = $this->conteos();

        $this->eliminarLoteHttp($lote)->assertSessionHas('error', EliminacionException::certificadosHistoricos()->getMessage());

        $this->assertSame($antes, $this->conteos());
        $this->assertNotNull(Lote::find($lote->id));
    }

    public function test_una_base_de_un_evento_sin_certificados_historicos_se_elimina_y_el_evento_no_se_toca(): void
    {
        $evento = EventoCertificacion::create(['nombre' => 'Evento Y', 'nombre_normalizado' => 'evento y', 'origen' => 'credential_flow']);
        $lote = $this->loteCon(1);
        $lote->update(['evento_id' => $evento->id]);

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($lote->id));
        $this->assertNotNull(EventoCertificacion::find($evento->id));
    }

    public function test_los_mensajes_de_bloqueo_son_humanos_y_sin_jerga_tecnica(): void
    {
        foreach ([EliminacionException::conHistorial(), EliminacionException::conDescargas(), EliminacionException::reemplazaHistoricos(), EliminacionException::certificadosHistoricos()] as $e) {
            $this->assertStringContainsString('no se puede eliminar definitivamente', $e->getMessage());
            $this->assertDoesNotMatchRegularExpression('/constraint|foreign|integrity|sqlstate|cf_|restrict|violation|table/i', $e->getMessage());
        }
        $this->assertStringContainsString('Puedes revocar los certificados', EliminacionException::conHistorial()->getMessage());
    }

    // ── G: atomicidad ────────────────────────────────────────────────────────────────────────────────────

    public function test_g_si_falla_tras_borrar_los_correos_y_antes_de_los_participantes_todo_se_revierte(): void
    {
        $lote = $this->loteCon(2);
        foreach ($lote->participantes as $p) {
            $this->conCorreos($p, 2);
        }
        $antes = $this->conteos();
        $correos = Correo::orderBy('id')->pluck('correo_normalizado')->all();
        $movimientos = Movimiento::count();

        EliminacionDefinitivaService::$despuesDeBorrarCorreos = function () {
            $this->assertSame(0, DB::table('cf_correos')->count(), 'Los correos ya estaban borrados dentro de la transacción');
            throw new RuntimeException('fallo simulado');
        };

        try {
            $this->servicio()->eliminarLote($lote->id);
            $this->fail('Debía lanzar.');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::ERROR_GENERAL, $e->codigo);
        }

        $this->assertSame($antes, $this->conteos());
        $this->assertSame($correos, Correo::orderBy('id')->pluck('correo_normalizado')->all());
        $this->assertNotNull(Lote::find($lote->id));
        $this->assertSame($movimientos, Movimiento::count());
    }

    public function test_si_aparece_un_correo_nuevo_mientras_se_prepara_la_eliminacion_no_se_borra_nada(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $this->conCorreos($p, 1);
        $antes = $this->conteos();

        // Entre «inspeccionar» y «borrar los correos» aparece uno nuevo: el conteo no cuadra y se revierte todo.
        EliminacionDefinitivaService::$despuesDeMover = fn () => Correo::create(['participante_id' => $p->id, 'correo' => 'nuevo@example.test', 'origen' => Correo::ORIGEN_CREDENTIAL_FLOW, 'orden' => 2]);

        try {
            $this->servicio()->eliminarLote($lote->id);
            $this->fail('Debía lanzar.');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::CAMBIO_CONCURRENTE, $e->codigo);
        }

        $this->assertSame($antes, $this->conteos());
    }

    // ── H: el certificado histórico no tiene camino administrativo de borrado ────────────────────────────

    public function test_h_el_certificado_legado_no_se_puede_eliminar_por_modelo_ni_existe_ruta_ni_servicio_que_lo_haga(): void
    {
        $legado = $this->certificadoLegado();
        Correo::create(['certificado_legado_id' => $legado->id, 'correo' => 'historico@example.test', 'origen' => Correo::ORIGEN_LEGADO]);

        try {
            $legado->delete();
            $this->fail('El modelo debía negarse');
        } catch (LogicException) {
            $this->addToAssertionCount(1);
        }
        $this->assertNotNull(CertificadoLegado::find($legado->id));
        $this->assertSame(1, Correo::where('certificado_legado_id', $legado->id)->count());

        // Ninguna ruta de borrado menciona certificados históricos, ni el servicio tiene un método para ellos.
        foreach (app('router')->getRoutes() as $ruta) {
            if (array_intersect(['DELETE'], $ruta->methods())) {
                $this->assertDoesNotMatchRegularExpression('/legado|historic/i', $ruta->uri().' '.($ruta->getName() ?? '').' '.$ruta->getActionName(), 'Hay una ruta DELETE sobre históricos: '.$ruta->uri());
            }
        }
        $metodos = array_map(fn ($m) => strtolower($m->getName()), (new \ReflectionClass(EliminacionDefinitivaService::class))->getMethods());
        $this->assertSame([], array_values(array_filter($metodos, fn ($m) => str_contains($m, 'legado') || str_contains($m, 'historic') || str_contains($m, 'certificado'))));
    }

    public function test_eliminar_una_base_no_borra_su_plantilla_y_luego_se_puede_purgar(): void
    {
        $plantilla = $this->plantillaLista();
        $lote = $this->loteCon(2, $plantilla);
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
        $a = $this->loteCon(2, $plantilla);
        $this->conCorreos($a->participantes()->first(), 2);
        [$b, $emisionesB] = $this->baseCompleta($plantilla);
        $this->conCorreos($b->participantes()->first(), 2);
        $rutasB = $emisionesB->pluck('pdf_archivo')->all();
        $hashesB = $this->contenidos($rutasB);

        $this->eliminarLoteHttp($a)->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($a->id));
        $this->assertNotNull(Lote::find($b->id));
        $this->assertSame(3, Participante::where('lote_id', $b->id)->count());
        $this->assertSame(4, Emision::where('lote_id', $b->id)->count());
        $this->assertSame(2, DB::table('cf_correos')->count(), 'Los correos de la otra base siguen ahí');
        $this->assertSame($hashesB, $this->contenidos($rutasB));
        $this->assertCount(4, collect($this->archivosDeEmisiones())->filter(fn ($f) => str_ends_with($f, '.pdf')));
    }

    public function test_una_base_ya_eliminada_normalmente_con_historial_no_se_purga_y_sin_historial_si(): void
    {
        // Con una emisión (aunque revocada) y ya oculta por el «Eliminar» normal: el historial se conserva.
        $conHistorial = $this->loteCon(1);
        $this->emitirPor($conHistorial, $conHistorial->participantes()->first())->assertCreated();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', Emision::first()), ['motivo' => 'Motivo de prueba'])->assertOk();
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy', $conHistorial->id))->assertSessionHas('success'); // soft delete normal

        $this->eliminarLoteHttp(Lote::withTrashed()->find($conHistorial->id))->assertSessionHas('error', EliminacionException::conHistorial()->getMessage());

        $this->assertNotNull(Lote::withTrashed()->find($conHistorial->id));
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertCount(1, collect($this->archivosDeEmisiones())->filter(fn ($f) => str_ends_with($f, '.pdf')));

        // Sin historial, ya oculta: se purga con sus participantes (también los ocultos) y sus correos.
        $sin = $this->loteCon(2);
        $this->conCorreos($sin->participantes()->first(), 2);
        $sin->delete();

        $this->eliminarLoteHttp(Lote::withTrashed()->find($sin->id))->assertSessionHas('success');

        $this->assertNull(Lote::withTrashed()->find($sin->id));
        $this->assertSame(0, DB::table('cf_correos')->count());
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

    public function test_el_resumen_de_una_base_con_historial_cuenta_todo_y_viene_bloqueado(): void
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
        $this->assertSame(EliminacionException::conHistorial()->getMessage(), $r['bloqueada']);
        $this->assertSame($lote->nombre, $r['nombre']);
    }

    public function test_el_resumen_de_una_base_sin_historial_cuenta_participantes_y_correos_y_no_esta_bloqueado(): void
    {
        $lote = $this->loteCon(3);
        $ps = $lote->participantes()->orderBy('id')->get();
        $this->conCorreos($ps[0], 2);
        $this->conCorreos($ps[1], 1);
        $ps[2]->delete(); // un oculto también cuenta

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->assertOk()->json('resumen');

        $this->assertSame([3, 0, 0, 3, 0, 0], [$r['participantes'], $r['vigentes'], $r['historicos'], $r['correos'], $r['archivos'], $r['bytes']]);
        $this->assertNull($r['bloqueada']);
    }

    public function test_el_resumen_funciona_con_una_base_ya_eliminada_normalmente(): void
    {
        $lote = $this->loteCon(2);
        $lote->delete();

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.eliminacion.resumen', $lote->id))->assertOk()->json('resumen');
        $this->assertSame(2, $r['participantes']);
        $this->assertNull($r['bloqueada']);

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
        $lote = $this->loteCon(3);
        $this->conCorreos($lote->participantes()->orderBy('id')->first(), 2);
        $nombresYDocumentos = array_merge(
            $lote->participantes->pluck('nombre_completo')->all(), $lote->participantes->pluck('documento')->all(), [$lote->nombre], Correo::pluck('correo')->all(),
        );

        $this->eliminarLoteHttp($lote)->assertSessionHas('success');

        $m = Movimiento::where('descripcion', 'like', '%definitivamente%')->sole();
        $this->assertSame('eliminacion', $m->tipo);
        $this->assertSame('credential-flow', $m->modulo);
        $this->assertSame($this->admin()->id, $m->user_id);
        $this->assertSame($lote->id, $m->metadata['lote_id']);
        $this->assertSame(3, $m->metadata['participantes']);
        $this->assertSame(0, $m->metadata['emisiones']);
        $this->assertSame(2, $m->metadata['correos']);
        $this->assertSame(0, $m->metadata['archivos']);
        $this->assertSame(0, $m->metadata['bytes_liberados']);
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
        $lote = $this->loteCon(2);
        $this->conCorreos($lote->participantes()->first(), 2);
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
                && preg_match('/^[\w\\\\]+( \[[A-Z0-9_ ?]+\])? @\w+\.php:\d+$/', $ctx['error']) === 1   // resumen seguro (11A): clase + @archivo:línea, sin el mensaje
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

    // ── Cambios concurrentes ──────────────────────────────────────────────────────────────────────────────────

    public function test_si_aparece_una_emision_mientras_se_prepara_la_eliminacion_no_se_borra_nada(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->first();
        $this->conCorreos($p, 2);
        // Una emisión de OTRA base sirve de molde (datos sintéticos).
        $otra = $this->loteCon(1);
        $this->emitirPor($otra, $otra->participantes()->first())->assertCreated();
        $antes = $this->conteos();

        // Entre «inspeccionar» y «borrar» aparece una emisión nueva en la base: se detecta y se revierte todo (correos incluidos).
        EliminacionDefinitivaService::$despuesDeMover = fn ($lote) => $this->unaEmisionNuevaFalsa($p->id, $lote->id);

        try {
            $this->servicio()->eliminarLote($lote->id);
            $this->fail('Debía lanzar.');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::CAMBIO_CONCURRENTE, $e->codigo);
        }

        $this->assertSame($antes, $this->conteos()); // la emisión nueva se revirtió con la transacción
        $this->assertSame(2, DB::table('cf_correos')->count());
        $this->assertNotNull(Lote::find($lote->id));
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

    // ── Lo que NO se toca ─────────────────────────────────────────────────────────────────────────────────────

    public function test_una_base_bloqueada_no_toca_el_staging_ni_nada_ajeno_y_una_libre_tampoco_toca_archivos_ajenos(): void
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
        $archivos = $this->archivosCredentialFlow();

        // Con emisiones la base está bloqueada: ni sus PDFs ni su staging se mueven.
        $this->eliminarLoteHttp($lote)->assertSessionHas('error');
        $this->assertSame($archivos, $this->archivosCredentialFlow());
        $this->assertSame([], $this->disco()->allFiles(RutasSeguras::PAPELERA));

        // Una base libre (sin emisiones) se elimina sin tocar ningún archivo: ni los de otras bases ni lo ajeno.
        $libre = $this->loteCon(1, $plantilla);
        $this->eliminarLoteHttp($libre)->assertSessionHas('success');

        $this->assertSame($archivos, $this->archivosCredentialFlow());
        $this->assertSame('staging de otra operación', $this->disco()->get($ajeno));
        $this->assertSame('backup', $this->disco()->get('_backups_credential_flow/x.tar.gz'));
        $this->assertSame('otro módulo', $this->disco()->get('uploads/otro.pdf'));
        $this->assertSame('ajeno al módulo de emisiones', $this->disco()->get('credential-flow/otra-cosa/dato.txt'));
        $this->assertNotNull(Plantilla::find($plantilla->id));
    }
}
