<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * Elegibilidad por participante (Elegible / Pendiente de validación / No elegible) e intensidad horaria: solo los elegibles entran a la emisión oficial,
 * nada se interpreta en silencio (p. ej. «Cupos» NO es asistencia completa) y una excepción de intensidad aprobada por participante manda sobre la del evento.
 * Datos 100 % sintéticos.
 */
class ElegibilidadTest extends EmisionesTestCase
{
    private const COMUNES = ['evento' => 'MÓDULO DE PRUEBA', 'fecha' => '16 de octubre de 2026', 'intensidad_horaria' => '5 horas'];

    private function importador(): ImportadorParticipantes
    {
        return app(ImportadorParticipantes::class);
    }

    private function csv(string $contenido): UploadedFile
    {
        return UploadedFile::fake()->createWithContent('base.csv', $contenido);
    }

    private function importar(string $csv): Lote
    {
        return $this->importador()->importar($this->csv($csv), $this->plantillaLista(), 'Base elegibilidad', null, self::COMUNES);
    }

    private function estados(Lote $lote): array
    {
        return $lote->participantes()->orderBy('id')->pluck('elegibilidad', 'nombre_completo')->all();
    }

    public function test_la_columna_elegibilidad_se_lee_tal_cual_y_sin_columna_todos_son_elegibles(): void
    {
        $lote = $this->importar("nombre_completo,documento,elegibilidad\nUNO,C.C. 9.100.001,elegible\nDOS,C.C. 9.100.002,Pendiente de validación\nTRES,C.C. 9.100.003,no elegible\nCUATRO,C.C. 9.100.004,\n");
        $this->assertSame(['UNO' => 'elegible', 'DOS' => 'pendiente', 'TRES' => 'no_elegible', 'CUATRO' => 'pendiente'], $this->estados($lote));
        $this->assertNotNull($lote->participantes()->where('nombre_completo', 'DOS')->value('elegibilidad_motivo'), 'Queda registrado por qué no es elegible todavía');
        $this->assertNull($lote->participantes()->where('nombre_completo', 'UNO')->value('elegibilidad_motivo'));

        $sin = $this->importar("nombre_completo,documento\nCINCO,C.C. 9.100.005\nSEIS,C.C. 9.100.006\n");
        $this->assertSame(['CINCO' => 'elegible', 'SEIS' => 'elegible'], $this->estados($sin));
    }

    public function test_un_texto_como_cupos_no_se_interpreta_ni_rechaza_la_carga(): void
    {
        $archivo = $this->csv('nombre_completo,documento,elegibilidad
UNO,C.C. 9.100.011,elegible
DOS,C.C. 9.100.012,Cupos
TRES,C.C. 9.100.013,Completo
');

        $resultado = $this->importador()->validar($archivo, $this->plantillaLista(), self::COMUNES);
        $this->assertTrue($resultado->valido(), 'La carga NO se rechaza');
        $this->assertSame(2, count($resultado->avisos), 'Se avisa de «Cupos» y de «Completo»');

        $lote = $this->importador()->importar($archivo, $this->plantillaLista(), 'Con cupos', null, self::COMUNES);

        // Nada se interpreta como asistencia completa: lo que no es un estado conocido queda PENDIENTE (nunca elegible) y con su motivo.
        $this->assertSame(['UNO' => 'elegible', 'DOS' => 'pendiente', 'TRES' => 'pendiente'], $this->estados($lote));
        $this->assertStringContainsString('Cupos', (string) $lote->participantes()->where('nombre_completo', 'DOS')->value('elegibilidad_motivo'));
        $this->assertNull($lote->participantes()->where('nombre_completo', 'DOS')->value('intensidad_horaria'), 'No se inventan horas');
    }

    public function test_una_formula_en_el_estado_si_es_un_error(): void
    {
        $resultado = $this->importador()->validar($this->csv('nombre_completo,documento,elegibilidad
UNO,C.C. 9.100.014,=1+1
'), $this->plantillaLista(), self::COMUNES);

        $this->assertFalse($resultado->valido());
        $this->assertSame(['ELEGIBILIDAD_INVALIDA'], array_values(array_unique(array_map(fn ($e) => $e->codigo, $resultado->errores))));
    }

    public function test_pendientes_y_no_elegibles_nunca_entran_a_la_emision_oficial_en_masa(): void
    {
        $lote = $this->importar("nombre_completo,documento,elegibilidad\nUNO,C.C. 9.100.021,elegible\nDOS,C.C. 9.100.022,pendiente\nTRES,C.C. 9.100.023,no elegible\nCUATRO,C.C. 9.100.024,elegible\n");

        $r = $this->actingAs($this->admin())->getJson(route('credential-flow.lotes.emision.resumen', $lote))->assertOk()->json('resumen');
        $this->assertSame([4, 2, 1, 1], [$r['participantes'], $r['pendientes'], $r['en_validacion'], $r['no_elegibles']]);

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated()->assertJsonPath('resultado.total', 2);

        $emitidos = Emision::where('lote_id', $lote->id)->pluck('participante_id');
        $this->assertSame(['UNO', 'CUATRO'], Participante::whereIn('id', $emitidos)->orderBy('id')->pluck('nombre_completo')->all());
        $this->assertSame(['DOS', 'TRES'], $lote->participantes()->whereDoesntHave('emisiones')->orderBy('id')->pluck('nombre_completo')->all());
    }

    public function test_la_emision_individual_rechaza_a_un_pendiente_y_se_habilita_al_confirmarlo(): void
    {
        $lote = $this->importar("nombre_completo,documento,elegibilidad\nDOS,C.C. 9.100.032,pendiente\n");
        $p = $lote->participantes()->firstOrFail();

        $this->emitirPor($lote, $p)->assertStatus(409)->assertJsonPath('error.code', 'NO_ELEGIBLE');
        $this->assertSame(0, Emision::count());

        // Confirmada su asistencia, el administrador lo marca elegible (el motivo ya no hace falta) y entonces sí se emite.
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento, 'elegibilidad' => 'elegible'])->assertSessionHasNoErrors();
        $this->assertSame(['elegible', null], [$p->fresh()->elegibilidad, $p->fresh()->elegibilidad_motivo]);
        $this->emitirPor($lote, $p->fresh())->assertCreated();
    }

    public function test_pendiente_o_no_elegible_exigen_un_motivo_desde_el_formulario(): void
    {
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.041\n");
        $p = $lote->participantes()->firstOrFail();
        $base = ['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento];

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), $base + ['elegibilidad' => 'pendiente'])->assertSessionHasErrors('elegibilidad_motivo');
        $this->assertSame('elegible', $p->fresh()->elegibilidad);

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), $base + ['elegibilidad' => 'no_elegible', 'elegibilidad_motivo' => 'Asistió menos del mínimo exigido.'])->assertSessionHasNoErrors();
        $this->assertSame(['no_elegible', 'Asistió menos del mínimo exigido.'], [$p->fresh()->elegibilidad, $p->fresh()->elegibilidad_motivo]);

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), $base + ['elegibilidad' => 'cualquier-cosa'])->assertSessionHasErrors('elegibilidad');
    }

    public function test_una_peticion_sin_los_campos_no_toca_la_elegibilidad_ni_la_intensidad(): void
    {
        $lote = $this->importar("nombre_completo,documento,elegibilidad\nUNO,C.C. 9.100.051,pendiente\n");
        $p = $lote->participantes()->firstOrFail();
        $p->update(['intensidad_horaria' => '3 horas']);

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => 'Uno Corregido', 'documento' => $p->documento])->assertSessionHasNoErrors();

        $f = $p->fresh();
        $this->assertSame(['UNO CORREGIDO', 'pendiente', '3 horas'], [$f->nombre_completo, $f->elegibilidad, $f->intensidad_horaria]);
    }

    public function test_la_excepcion_de_intensidad_se_imprime_y_las_demas_conservan_la_del_evento(): void
    {
        $lote = $this->importar("nombre_completo,documento\nCON EXCEPCION,C.C. 9.100.061\nSIN EXCEPCION,C.C. 9.100.062\n");
        $conExcepcion = $lote->participantes()->where('nombre_completo', 'CON EXCEPCION')->firstOrFail();
        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $conExcepcion->id]), ['nombre_completo' => $conExcepcion->nombre_completo, 'documento' => $conExcepcion->documento, 'intensidad_horaria' => '3 horas'])->assertSessionHasNoErrors();

        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();

        $horas = function (string $nombre) use ($lote): array {
            $e = Emision::where('lote_id', $lote->id)->where('participante_id', $lote->participantes()->where('nombre_completo', $nombre)->value('id'))->firstOrFail();
            $pdf = $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent();

            return [$e->datos_snapshot['intensidad_horaria'] ?? null, array_column((new InspectorPdf($pdf))->textos(), 'texto')];
        };
        [$snapA, $textosA] = $horas('CON EXCEPCION');
        [$snapB, $textosB] = $horas('SIN EXCEPCION');

        $this->assertSame(['3 horas', '5 horas'], [$snapA, $snapB]);
        $this->assertContains('3 horas', $textosA);
        $this->assertNotContains('5 horas', $textosA);
        $this->assertContains('5 horas', $textosB);
    }

    public function test_cambiar_la_elegibilidad_o_la_intensidad_despues_no_altera_lo_ya_emitido(): void
    {
        $lote = $this->importar("nombre_completo,documento\nUNO,C.C. 9.100.071\n");
        $p = $lote->participantes()->firstOrFail();
        $this->emitirPor($lote, $p)->assertCreated();
        $antes = DB::table('cf_emisiones')->where('participante_id', $p->id)->first();

        $this->actingAs($this->admin())->put(route('credential-flow.participantes.update', [$lote->id, $p->id]), ['nombre_completo' => $p->nombre_completo, 'documento' => $p->documento, 'elegibilidad' => 'no_elegible', 'elegibilidad_motivo' => 'Corrección posterior de asistencia.', 'intensidad_horaria' => '1 hora'])->assertSessionHasNoErrors();

        $this->assertEquals($antes, DB::table('cf_emisiones')->where('participante_id', $p->id)->first(), 'La emisión inmutable (snapshot, PDF, hash, código) no cambia');
    }
}
