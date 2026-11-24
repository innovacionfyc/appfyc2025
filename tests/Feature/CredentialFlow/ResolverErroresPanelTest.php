<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use Illuminate\Support\Facades\DB;

/**
 * Resolver errores desde el panel (primera entrega), con datos sintéticos:
 *  1. Corregir datos y reemitir son acciones separadas: guardar una corrección no revoca, no emite ni envía; la reemisión explica qué certificado
 *     reemplaza y conserva el motivo y la auditoría.
 *  3. «Bases eliminadas anteriormente»: una base con historial se presenta como «conservada por historial» y no ofrece «Eliminar definitivamente».
 */
class ResolverErroresPanelTest extends EmisionesTestCase
{
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
        ], '--force' => true])->assertExitCode(0);
    }

    private function filaDe(Lote $lote, int $participanteId): array
    {
        $datos = $this->actingAs($this->admin())->get(route('credential-flow.lotes.show', $lote))->assertOk()->viewData('page')['props']['participantes']['data'];

        return collect($datos)->firstWhere('id', $participanteId);
    }

    // ── 1. Corregir y reemitir ─────────────────────────────────────────────

    public function test_la_base_indica_que_certificado_vigente_se_reemplazaria_al_reemitir(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();
        $vigente = Emision::where('participante_id', $p->id)->firstOrFail();

        $fila = $this->filaDe($lote, $p->id);

        $this->assertSame('emitida', $fila['estado_emision']);
        $this->assertSame($vigente->id, $fila['emision']['id']);
        $this->assertSame($vigente->codigo, $fila['emision']['codigo']);
        $this->assertSame(1, $fila['emision']['version']);
        $this->assertNotEmpty($fila['emision']['emitido_at']);
    }

    public function test_guardar_una_correccion_no_revoca_ni_emite_y_explica_como_reemitir(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();
        $antes = Emision::where('participante_id', $p->id)->firstOrFail();
        $movimientosEmision = DB::table('movimientos')->where('descripcion', 'like', '%reemitió%')->orWhere('descripcion', 'like', '%revocó%')->count();

        $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))
            ->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => 'Persona Corregida', 'documento' => $p->documento])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => str_contains($m, 'Su certificado vigente no cambió') && str_contains($m, '«Reemitir certificado»'));

        $this->assertSame(1, Emision::where('participante_id', $p->id)->count(), 'guardar no emite');
        $this->assertSame(Emision::EMITIDA, $antes->fresh()->estado, 'guardar no revoca');
        $this->assertSame($antes->datos_snapshot, $antes->fresh()->datos_snapshot, 'el certificado vigente no cambia');
        $this->assertSame($movimientosEmision, DB::table('movimientos')->where('descripcion', 'like', '%reemitió%')->orWhere('descripcion', 'like', '%revocó%')->count());
        $this->assertSame('PERSONA CORREGIDA', $p->fresh()->nombre_completo);
    }

    public function test_corregir_sin_certificado_vigente_no_menciona_la_reemision(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();

        $this->actingAs($this->admin())->from(route('credential-flow.lotes.show', $lote))
            ->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => 'Otra Persona', 'documento' => $p->documento])
            ->assertSessionHasNoErrors()
            ->assertSessionHas('success', fn ($m) => ! str_contains($m, 'Reemitir'));
    }

    public function test_reemitir_tras_corregir_reemplaza_el_vigente_conserva_el_motivo_y_deja_auditoria(): void
    {
        $lote = $this->loteCon(1);
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();
        $anterior = Emision::where('participante_id', $p->id)->firstOrFail();
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => 'Persona Corregida', 'documento' => $p->documento]);

        $r = $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.reemitir', $anterior), ['motivo' => 'Nombre mal escrito en el Excel'])->assertCreated();

        $nueva = Emision::findOrFail($r->json('emision.id'));
        $this->assertSame(2, $nueva->version);
        $this->assertSame($anterior->id, $nueva->reemplaza_id);
        $this->assertNotSame($anterior->codigo, $nueva->codigo, 'el certificado nuevo tiene otro código');
        $this->assertSame('PERSONA CORREGIDA', $nueva->datos_snapshot['nombre_completo'] ?? $nueva->datos_snapshot['nombre'] ?? null);
        $this->assertSame(Emision::REVOCADA, $anterior->fresh()->estado);
        $this->assertSame('Reemplazada por la versión 2. Motivo: Nombre mal escrito en el Excel', $anterior->fresh()->motivo_revocacion);
        $this->assertTrue(DB::table('movimientos')->where('descripcion', 'Se reemitió una credencial de Credential Flow')->exists());
    }

    // ── 3. Bases eliminadas conservadas por historial ──────────────────────

    public function test_las_bases_eliminadas_con_historial_se_muestran_conservadas_y_sin_eliminacion_definitiva(): void
    {
        $conHistorial = $this->loteCon(1);
        $p = $conHistorial->participantes()->firstOrFail();
        $this->emitirPor($conHistorial, $p)->assertCreated();
        $e = Emision::where('participante_id', $p->id)->firstOrFail();
        $this->actingAs($this->admin())->postJson(route('credential-flow.emisiones.revocar', $e), ['motivo' => 'Revocación sintética'])->assertOk();
        $conHistorial->delete();

        $sinHistorial = $this->loteCon(1);
        $sinHistorial->delete();

        $eliminadas = collect($this->actingAs($this->admin())->get(route('credential-flow.lotes.index'))->assertOk()->viewData('page')['props']['eliminadas'])->keyBy('id');

        $this->assertTrue($eliminadas[$conHistorial->id]['conservada_por_historial']);
        $this->assertFalse($eliminadas[$conHistorial->id]['eliminable']);
        $this->assertFalse($eliminadas[$sinHistorial->id]['conservada_por_historial']);
        $this->assertTrue($eliminadas[$sinHistorial->id]['eliminable']);

        // El servidor sigue siendo quien decide: la acción se niega igual para la base con historial.
        $this->actingAs($this->admin())->delete(route('credential-flow.lotes.destroy-definitivo', $conHistorial->id), ['confirmacion' => 'ELIMINAR'])
            ->assertSessionHas('error');
        $this->assertSoftDeleted($conHistorial);
    }
}
