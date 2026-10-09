<?php

namespace Tests\Feature\CredentialFlow\Historico;

/** Correos del histórico: un participante con DOS correos válidos (se conservan ambos, sin principal) y el resto de indicadores. */
class HistoricoCorreosTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        // El participante 3 tenía dos correos válidos en el campo original.
        $this->migrarSintetico($this->conCambio($this->datos(), 'participante', 3, ['correo' => 'carla1@example.test; Carla2@example.test']));
    }

    /** @return array<string,mixed> */
    private function cert(int $old): array
    {
        return $this->actingAs($this->admin())->get(route('credential-flow.historico.certificados.show', $this->idCert($old)))->assertOk()->viewData('page')['props']['certificado'];
    }

    public function test_correos_multiples_se_muestran_todos_con_su_etiqueta_y_sin_cambiar_ninguno(): void
    {
        $c = $this->cert(3);

        $this->assertTrue($c['correos_multiples']);
        $this->assertSame('Múltiples correos históricos', $c['correo_estado']);
        $this->assertSame('carla1@example.test; Carla2@example.test', $c['correo_original'], 'El original no se toca');
        $this->assertSame(['carla1@example.test', 'carla2@example.test'], array_column($c['correos'], 'normalizado'));
        $this->assertSame([1, 2], array_column($c['correos'], 'orden'));
        $this->assertSame([false, false], array_column($c['correos'], 'principal'), 'Sin principal automático');
        $this->assertSame(['Histórico', 'Histórico'], array_column($c['correos'], 'origen'));
        $this->assertSame(['Válido', 'Válido'], array_column($c['correos'], 'estado'));
        $this->assertSame('Múltiples correos históricos', collect($c['avisos'])->firstWhere('tipo', 'correos')['titulo']);
    }

    public function test_en_los_listados_solo_aparece_el_indicador_de_correo_nunca_la_direccion(): void
    {
        $evento = $this->idEvento('Curso Beta 2025');
        $r = $this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', $evento))->assertOk();
        $tabla = $r->viewData('page')['props']['certificados'];
        $filas = collect($tabla['data'])->keyBy('nombre');

        $this->assertSame('2 correos válidos', $filas['CARLA TRES']['correo']);
        $this->assertSame('Sin correo', $filas['ELENA CINCO']['correo']);
        $this->assertSame('1 correo válido', $filas['DIEGO CUATRO']['correo']);
        foreach (['carla1@example.test', 'Carla2@example.test', 'example.test', '@'] as $secreto) {
            $this->assertStringNotContainsString($secreto, json_encode($tabla));
        }
    }

    public function test_un_correo_invalido_se_indica_sin_mostrar_la_direccion(): void
    {
        $evento = $this->idEvento('Curso Alfa 2024');
        $filas = collect($this->actingAs($this->admin())->get(route('credential-flow.historico.eventos.show', $evento))->viewData('page')['props']['certificados']['data'])->keyBy('nombre');

        $this->assertSame('Correo inválido', $filas['BETO DOS PRUEBA']['correo']);
        $this->assertSame('Correo inválido', $this->cert(2)['correo_estado'] === 'Inválido' ? 'Correo inválido' : 'otro');
    }
}
