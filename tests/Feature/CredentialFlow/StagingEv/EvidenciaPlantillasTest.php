<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\Legado\EvidenciaPlantillas;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Evidencia técnica de plantillas pendientes, candidatas y huérfanas sobre los datos sintéticos del staging. */
class EvidenciaPlantillasTest extends StagingEvTestCase
{
    private string $png;

    /** @return array<string,mixed> */
    private function evidencia(): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stg_ev_evid_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $this->png = (string) ob_get_clean();
        file_put_contents($dir.'/ALFA 2024.png', $this->png);
        file_put_contents($dir.'/DELTA 2023', $this->png);
        file_put_contents($dir.'/ORFANA.png', $this->png.'o');
        file_put_contents($dir.'/GAMMA_.png', $this->png.'g');
        $this->cargar('s1', null, (new EscanerImagenes)->escanear($dir));
        File::deleteDirectory($dir);

        return (new EvidenciaPlantillas)->generar();
    }

    public function test_eventos_pendientes_candidatas_y_huerfanas(): void
    {
        $e = $this->evidencia();

        // Pendientes: los eventos con archivo faltante (2, 3 y 6) y con extensión inválida (4). El 5 no referencia imagen.
        $this->assertSame(4, $e['eventos_pendientes']['total']);
        $this->assertSame(['archivo_faltante' => 3, 'extension_invalida' => 1], $e['eventos_pendientes']['por_estado']);
        $this->assertSame([2, 3, 4, 6], array_column($e['eventos_pendientes']['detalle'], 'old_evento_id'));
        $this->assertStringContainsString('no se inventa plantilla', $e['eventos_pendientes']['detalle'][0]['tratamiento']);
        $this->assertStringContainsString('no renderizable automático', $e['eventos_pendientes']['detalle'][2]['tratamiento']);

        // Una candidata, sin decisión: solo evidencia.
        $this->assertCount(1, $e['candidatas']);
        $c = $e['candidatas'][0];
        $this->assertSame(3, $c['old_evento_id']);
        $this->assertSame(hash('sha256', $this->png.'g'), $c['sha256']);
        $this->assertSame('image/png', $c['mime_real']);
        $this->assertSame([2, 2], [$c['ancho_px'], $c['alto_px']]);
        $this->assertTrue($c['similitud_nombre']['igual_normalizado']);
        $this->assertSame(100.0, $c['similitud_nombre']['similar_text_pct']);
        $this->assertStringStartsWith('NINGUNA', $c['decision']);

        // Huérfanas: se conservan catalogadas (no se eliminan).
        $this->assertSame(2, $e['huerfanas']['total']);
        $this->assertSame(1, $e['huerfanas']['que_ademas_son_candidata']);
        $this->assertSame(['sha256', 'mime_real', 'ancho_px', 'alto_px', 'bytes', 'renderizable_fpdf', 'tratamiento'], array_keys($e['huerfanas']['detalle'][0]));
    }

    public function test_no_enlaza_nada_ni_expone_nombres_de_archivo(): void
    {
        $e = $this->evidencia();
        $json = json_encode($e);
        $firma = fn () => md5(json_encode([DB::table('stg_ev_evento')->orderBy('id')->get(), DB::table('stg_ev_imagenes')->orderBy('id')->get()]));
        $antes = $firma();

        foreach (['ALFA', 'DELTA', 'ORFANA', 'GAMMA', '.png'] as $nombre) {
            $this->assertStringNotContainsString($nombre, $json);
        }
        // Solo lectura: generar la evidencia no cambia ni un solo dato y la candidata NO queda enlazada al evento.
        (new EvidenciaPlantillas)->generar();
        $this->assertSame($antes, $firma());
        $this->assertNull(DB::table('stg_ev_evento')->where('old_id', 3)->value('imagen_ref_id'));
    }

    public function test_el_comando_funciona_y_en_json(): void
    {
        $this->evidencia();

        $this->artisan('credential-flow:legado:evidencia')->assertExitCode(0)
            ->expectsOutputToContain('Eventos pendientes: 4')->expectsOutputToContain('Candidatas por normalización (SIN decisión): 1');
    }
}
