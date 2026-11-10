<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use App\Support\CredentialFlow\Participantes\DobleModalidad;

/**
 * Las MISMAS pruebas de DobleModalidadTest (importación XLSX, identidad, modalidades, QA, PDF, ZIP, informe, programación, reemisión…), pero con el evento
 * autorizado por un super-admin en la base de datos (`cf_eventos_doble_modalidad`) y la variable heredada AUSENTE. El motor no cambia: solo la fuente.
 */
class DobleModalidadBaseDeDatosTest extends DobleModalidadTest
{
    protected function autorizar(?EventoCertificacion $evento = null): void
    {
        $evento ??= $this->evento;
        config(['credential_flow.doble_modalidad.eventos' => null]);   // sin variable heredada: solo la base de datos
        if (DobleModalidad::vigente($evento->id) === null) {
            app(AutorizacionesDobleModalidad::class)->autorizar($evento, $this->superAdmin()->load('perfilOrganizador.rol'), 'Autorización sintética de prueba');
        }
        $this->assertSame(DobleModalidad::ORIGEN_BASE_DE_DATOS, DobleModalidad::origen($evento->id));
    }

    protected function origenEsperado(): string
    {
        return DobleModalidad::ORIGEN_BASE_DE_DATOS;
    }

    /** Plantillas universales («Modalidad y ciudad»): cada participación imprime su modalidad con la ciudad de la base, y sin ciudad no se confirma (PR #66). */
    public function test_plantilla_universal_modalidad_y_ciudad_con_doble_modalidad(): void
    {
        $campos = ['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad_ciudad', 'intensidad_horaria'];
        $this->plantilla = $this->plantillaLista(array_map(fn (string $c, int $i) => $this->elemento(['field' => $c, 'y' => 40 + $i * 50, 'height' => 30, 'fontSize' => 12]), $campos, array_keys($campos)));
        $this->varianteQa();
        $this->autorizar();
        $id = $this->borrador($this->pareja());
        $this->assertFalse($this->validar($id)->json('listo'), 'sin ciudad no se confirma');
        $this->corregir($id, ['datos_comunes' => ['ciudad' => 'Bogotá']])->assertOk();
        $this->assertTrue($this->validar($id)->json('listo'));
        $lote = $this->confirmar($id);
        $this->actingAs($this->admin())->postJson(route('credential-flow.lotes.emitir', $lote))->assertCreated();

        [$presencial, $virtual] = $this->gemelos($lote);
        $tp = implode(' | ', $this->textos($presencial->emisionVigente()->firstOrFail()));
        $tv = implode(' | ', $this->textos($virtual->emisionVigente()->firstOrFail()));
        $this->assertStringContainsString('Presencial, Bogotá', $tp);
        $this->assertStringContainsString('Virtual, Bogotá', $tv);
        $this->assertStringNotContainsString('Virtual', $tp);
    }

    /** Igual que en la clase madre, pero la autorización se retira en la base de datos (como una revocación de otra sesión entre validar y confirmar). */
    public function test_al_confirmar_se_vuelve_a_validar_con_la_regla_vigente(): void
    {
        $this->autorizar();
        $id = $this->borrador($this->pareja());
        $this->assertTrue($this->validar($id)->json('listo'));
        $confirmar = fn () => $this->actingAs($this->admin())->postJson(route('credential-flow.importaciones.confirmar', $id), ['version' => Importacion::findOrFail($id)->version, 'confirmar' => true]);

        // Otra sesión revoca después de validar (la revocación por el servicio la bloquearía este borrador; aquí se simula la carrera directamente).
        AutorizacionDobleModalidad::query()->where('vigente_evento_id', $this->evento->id)->update(['estado' => AutorizacionDobleModalidad::REVOCADA, 'vigente_evento_id' => null,
            'revocada_por' => $this->superAdmin()->id, 'revocada_at' => now(), 'motivo_revocacion' => 'Revocación simultánea simulada']);
        $confirmar()->assertStatus(422);
        $this->assertSame(0, Lote::count());

        // Autorizado de nuevo, pero alguien cambió la virtual a presencial en el borrador después de validar: tampoco.
        $this->autorizar();
        $this->corregir($id, ['participantes' => ['8' => ['modalidad' => 'Presencial']]])->assertOk();
        $confirmar()->assertStatus(422);
        $this->assertSame(0, Lote::count());
    }
}
