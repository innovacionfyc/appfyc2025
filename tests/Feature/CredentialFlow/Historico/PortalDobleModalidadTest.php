<?php

namespace Tests\Feature\CredentialFlow\Historico;

use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Participantes\ImportadorParticipantes;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

/**
 * Portal con doble certificación por modalidad (evento autorizado): la persona ve y descarga SUS dos certificados del mismo evento, y nada de otra persona.
 * Datos 100 % sintéticos.
 */
class PortalDobleModalidadTest extends PortalTestCase
{
    private const COMUNES = ['evento' => 'Seminario sintético de gestión de cartera', 'fecha' => '29 y 30 de octubre de 2026', 'intensidad_horaria' => '18 horas'];

    /** Base del evento autorizado: la persona doble (presencial y virtual) y otra persona. Devuelve [participante_id => emisión]. */
    private function baseEmitida(): array
    {
        $evento = EventoCertificacion::create(['nombre' => self::COMUNES['evento'], 'nombre_normalizado' => NombreConservador::normalizar(self::COMUNES['evento']), 'anio' => 2026,
            'fecha_texto' => '29 Y 30 DE OCTUBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
        config(['credential_flow.doble_modalidad.eventos' => (string) $evento->id]);
        $csv = "nombre_completo,documento,correo\nPERSONA DOBLE FICTICIA,9.300.001,doble@example.test\nPersona Doble Ficticia,9.300.001,doble@example.test\nOTRA PERSONA FICTICIA,9.300.002,otra@example.test\n";
        $lote = app(ImportadorParticipantes::class)->importar(UploadedFile::fake()->createWithContent('base.csv', $csv), $this->plantillaLista(), 'Base doble', null, self::COMUNES,
            $evento->id, null, null, [2 => 'presencial', 3 => 'virtual', 4 => 'presencial']);
        foreach ($lote->participantes()->orderBy('id')->get() as $i => $p) {
            $p->update(['modalidad' => ['Presencial', 'Virtual', 'Presencial'][$i]]);
        }

        return $this->emitirTodos($lote->fresh());
    }

    private function emitirTodos(Lote $lote): array
    {
        $r = [];
        foreach ($lote->participantes()->orderBy('id')->get() as $p) {
            $this->emitirPor($lote, $p)->assertCreated();
            $r[$p->id] = DB::table('cf_emisiones')->where('participante_id', $p->id)->first();
        }

        return $r;
    }

    public function test_la_persona_ve_y_descarga_sus_dos_certificados_del_mismo_evento_y_nada_ajeno(): void
    {
        [$presencial, $virtual, $otra] = array_values($this->baseEmitida());

        $this->entrar('9.300.001', 'doble@example.test');
        $panel = $this->get(route('portal.panel'))->assertOk();
        $panel->assertSee($presencial->codigo)->assertSee($virtual->codigo)->assertDontSee($otra->codigo);
        $this->assertSame(2, substr_count($panel->getContent(), 'data-moderno'), 'dos tarjetas modernas: una por participación');

        $this->get(route('portal.descargar-moderno', $presencial->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('portal.descargar-moderno', $virtual->id))->assertOk()->assertHeader('content-type', 'application/pdf');
        $this->get(route('portal.descargar-moderno', $otra->id))->assertNotFound();   // ajeno: igual que «no existe»
        $this->assertSame(2, DB::table('cf_descargas')->whereIn('emision_id', [$presencial->id, $virtual->id])->count());
        $this->assertSame(0, DB::table('cf_descargas')->where('emision_id', $otra->id)->count());
    }

    public function test_la_otra_persona_no_ve_los_certificados_de_la_doble(): void
    {
        [$presencial, $virtual, $otra] = array_values($this->baseEmitida());

        $this->entrar('9.300.002', 'otra@example.test');
        $this->get(route('portal.panel'))->assertOk()->assertSee($otra->codigo)->assertDontSee($presencial->codigo)->assertDontSee($virtual->codigo);
        $this->get(route('portal.descargar-moderno', $presencial->id))->assertNotFound();
        $this->get(route('portal.descargar-moderno', $virtual->id))->assertNotFound();
    }
}
