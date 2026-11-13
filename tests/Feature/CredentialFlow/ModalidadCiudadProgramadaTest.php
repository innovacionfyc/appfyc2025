<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EmisionProgramada;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Participantes\ModalidadImpresa;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Carbon\CarbonImmutable;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/** «modalidad_ciudad» en la emisión PROGRAMADA: el ejecutor automático (sin sesión) imprime la misma composición que la emisión manual. Datos ficticios. */
class ModalidadCiudadProgramadaTest extends ProgramacionTestCase
{
    public function test_el_ejecutor_programado_emite_oficiales_y_qa_con_modalidad_y_ciudad(): void
    {
        $el = fn (array $c) => $this->elemento($c + ['x' => 86, 'width' => 620, 'fontSize' => 14, 'height' => 20]);
        $p = $this->plantillaLista([
            $el(['field' => 'nombre_completo', 'y' => 205, 'fontSize' => 22, 'height' => 28]),
            $el(['field' => 'documento', 'y' => 232, 'fontSize' => 18, 'height' => 24]),
            $el(['field' => 'evento', 'y' => 282]),
            $el(['field' => 'fecha', 'y' => 326]),
            $el(['field' => 'modalidad_ciudad', 'y' => 346]),
            $el(['field' => 'intensidad_horaria', 'y' => 368]),
        ]);
        $p->update(['schema_version' => DisenoSchema::versionPara($p->diseno)]);
        $this->variante($p->fresh());
        $lote = $this->loteCon(0, $p->fresh());
        $lote->update(['datos_comunes' => $lote->datos_comunes + ['modalidad' => 'Híbrido', 'ciudad' => 'Bogotá']]);
        foreach (['Presencial', 'Virtual', 'Virtual sincrónico'] as $i => $mod) {
            $this->participante($lote, "Persona Programada {$i}", 'C.C. '.(60_000_000 + $i))->update(['modalidad' => $mod]);
        }
        app(ServicioQa::class)->incorporar($lote->fresh());
        $prog = $this->programar($lote->fresh(), '2026-10-27 10:30');

        $this->travelTo(CarbonImmutable::create(2026, 10, 27, 10, 30, 5, 'America/Bogota'));
        app('auth')->forgetGuards();
        $this->assertSame(0, Artisan::call('credential-flow:emisiones-programadas'));
        $prog->refresh();
        $this->assertSame([EmisionProgramada::COMPLETADA, 3, 6], [$prog->estado, $prog->resultado['oficiales_emitidos'], $prog->resultado['qa_emitidos']], Artisan::output());

        $lineas = Emision::query()->where('lote_id', $lote->id)->get()->map(function (Emision $e) {
            $textos = array_column((new InspectorPdf(Storage::disk('local')->get($e->pdf_archivo)))->textos(), 'texto');
            $this->assertContains($e->datos_snapshot['modalidad_ciudad'], $textos);
            $this->assertSame(hash('sha256', Storage::disk('local')->get($e->pdf_archivo)), $e->pdf_hash);

            return $e->datos_snapshot['modalidad_ciudad'];
        })->countBy()->sortKeys()->all();
        $this->assertSame(['Presencial, Bogotá' => 3, ModalidadImpresa::VIRTUAL => 6], $lineas, '1 oficial + 2 QA por modalidad; las dos grafías virtuales con la redacción oficial');
    }
}
