<?php

namespace Tests\Feature\CredentialFlow;

use App\Models\CredentialFlow\Emision;
use App\Support\CredentialFlow\Participantes\DatosDeParticipante;
use App\Support\CredentialFlow\Participantes\Texto;
use Tests\Feature\CredentialFlow\Support\InspectorPdf;

/**
 * La cédula se IMPRIME con puntos de miles («C.C. 40.343.234») sin cambiar el dato guardado, su clave ni otros tipos de documento. Datos sintéticos.
 */
class DocumentoImpresoTest extends EmisionesTestCase
{
    public function test_formato_de_impresion_solo_para_cedulas_escritas_con_digitos(): void
    {
        $casos = [
            'C.C. 40343234' => 'C.C. 40.343.234',
            'C.C. 1052312957' => 'C.C. 1.052.312.957',
            'C.C. 0123456' => 'C.C. 0.123.456',          // conserva el cero inicial
            'C.C.40343234' => 'C.C. 40.343.234',
            'C.C. 40.343.234' => 'C.C. 40.343.234',      // ya formateada: igual
            'C.C. 123' => 'C.C. 123',                    // muy corta: igual
            '79.131.000' => '79.131.000',                // históricos (solo número): igual
            '40343234' => '40343234',                    // sin tipo: no se presume cédula
            'C.E. 1234567' => 'C.E. 1234567',
            'PASAPORTE AB123456' => 'PASAPORTE AB123456',
            'PPT 1234567' => 'PPT 1234567',
        ];
        foreach ($casos as $guardado => $impreso) {
            $this->assertSame($impreso, DatosDeParticipante::documentoParaImprimir($guardado), $guardado);
        }
    }

    public function test_el_certificado_emitido_lleva_los_puntos_y_el_dato_guardado_no_cambia(): void
    {
        $lote = $this->loteCon(0);
        $p = $this->participante($lote, 'Ana Sintética Prueba', 'C.C. 40343234');
        $clave = $p->documento_clave;

        $this->emitirPor($lote, $p)->assertCreated();

        $e = Emision::where('participante_id', $p->id)->firstOrFail();
        $this->assertSame('C.C. 40.343.234', $e->datos_snapshot['documento']);
        $pdf = $this->actingAs($this->admin())->get(route('credential-flow.emisiones.descargar', $e))->assertOk()->getContent();
        $this->assertContains('C.C. 40.343.234', array_column((new InspectorPdf($pdf))->textos(), 'texto'));

        $p->refresh();
        $this->assertSame('C.C. 40343234', $p->documento, 'el dato guardado no cambia');
        $this->assertSame($clave, $p->documento_clave);
        $this->assertSame(Texto::claveDocumento('C.C. 40.343.234'), $clave, 'la clave del portal es la misma con o sin puntos');
    }
}
