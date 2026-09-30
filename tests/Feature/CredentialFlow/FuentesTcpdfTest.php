<?php

namespace Tests\Feature\CredentialFlow;

use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\Generacion\FuentesTcpdf;
use RuntimeException;
use Tests\TestCase;

/** F&C Credential Flow · Fase 5: definiciones TCPDF versionadas de Outfit. */
class FuentesTcpdfTest extends TestCase
{
    public function test_verify_de_las_definiciones_tcpdf_es_correcto(): void
    {
        $this->artisan('credential-flow:fuentes-tcpdf', ['--verify' => true])->assertSuccessful();
    }

    public function test_hay_una_definicion_por_cada_peso_reproducible_y_ninguna_de_mas(): void
    {
        $this->assertSame(FuentesCredential::pesosReproducibles(), array_keys(FuentesTcpdf::DEFINICIONES));

        $archivos = array_map('basename', glob(FuentesTcpdf::directorio().'/*'));
        $esperados = [];
        foreach (FuentesTcpdf::DEFINICIONES as [$nombre]) {
            array_push($esperados, "$nombre.php", "$nombre.z", "$nombre.ctg.z");
        }
        sort($archivos);
        sort($esperados);
        $this->assertSame($esperados, $archivos);
        $this->assertSame([], glob(FuentesTcpdf::directorio().'/*neutra*'));
    }

    public function test_mapeo_familia_peso_a_definicion(): void
    {
        $this->assertSame('outfitlight', FuentesTcpdf::nombre('outfit', 300));
        $this->assertSame('outfit', FuentesTcpdf::nombre('outfit', 400));
        $this->assertSame('outfitmedium', FuentesTcpdf::nombre('outfit', 500));
        $this->assertSame('outfitsemib', FuentesTcpdf::nombre('outfit', 600));
        $this->assertSame('outfitb', FuentesTcpdf::nombre('outfit', 700));
        $this->assertSame('outfitextrab', FuentesTcpdf::nombre('outfit', 800));
    }

    public function test_no_hay_definicion_para_fuentes_heredadas_ni_pesos_inexistentes(): void
    {
        foreach ([['Figtree', 700], ['Arial', 400], ['sans-serif', 400], ['outfit', 350]] as [$f, $p]) {
            try {
                FuentesTcpdf::nombre($f, $p);
                $this->fail("No debería existir definición para $f $p");
            } catch (RuntimeException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_los_widths_de_cada_definicion_coinciden_con_metricas_json(): void
    {
        foreach (FuentesTcpdf::DEFINICIONES as $peso => [$nombre]) {
            $cw = (static function (string $ruta) {
                include $ruta;

                return $cw;
            })(FuentesTcpdf::directorio()."/$nombre.php");

            foreach (FuentesCredential::metricasDe('outfit', $peso)['anchos'] as $cp => $ancho) {
                $this->assertSame($ancho, $cw[$cp], "peso $peso U+".dechex($cp));
            }
        }
    }
}
