<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use InvalidArgumentException;

/**
 * Imágenes cargadas desde un MANIFIESTO: el nombre original (que puede no ser válido como nombre de archivo en Windows)
 * y el SHA-256 salen del manifiesto, y el archivo físico puede llamarse distinto.
 */
class ManifiestoImagenesTest extends StagingEvTestCase
{
    private string $dir;

    private string $manifiesto;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Se necesita GD para crear las imágenes sintéticas.');
        }
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stg_ev_man_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
        $this->manifiesto = $this->dir.'.json';
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        if (is_file($this->manifiesto)) {
            unlink($this->manifiesto);
        }
        parent::tearDown();
    }

    private function png(string $extra = ''): string
    {
        $im = imagecreatetruecolor(3, 2);
        ob_start();
        imagepng($im);

        return ob_get_clean().$extra;
    }

    /** @param array<int,array<string,mixed>> $archivos */
    private function escribirManifiesto(array $archivos): void
    {
        file_put_contents($this->manifiesto, json_encode(['archivos' => $archivos], JSON_UNESCAPED_UNICODE));
    }

    /** @return array<string,mixed> */
    private function entrada(string $original, string $local, string $contenido, bool $renderizable = true): array
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$local, $contenido);

        return [
            'nombre_original' => $original, 'nombre_local' => $local, 'bytes' => strlen($contenido), 'sha256' => hash('sha256', $contenido),
            'extension' => 'png', 'mime_real' => 'image/png', 'ancho_px' => 3, 'alto_px' => 2, 'renderizable_fpdf' => $renderizable, 'motivo' => null,
        ];
    }

    public function test_un_nombre_no_portable_se_carga_con_su_nombre_original_y_el_sha_del_manifiesto(): void
    {
        $original = 'Curso ¿Efectiva? "2024" .png';   // no válido como nombre de archivo en Windows
        $a = $this->entrada($original, '__nombre_no_portable_001.bin', $this->png('a'));
        $b = $this->entrada('Normal 2025.png', 'Normal 2025.png', $this->png('b'));
        $this->escribirManifiesto([$a, $b]);

        $imagenes = EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir, verificarSha: true);

        $this->assertCount(2, $imagenes);
        $descriptor = collect($imagenes)->firstWhere('nombre_original', $original);
        $this->assertSame('document/certImages/'.$original, $descriptor['ruta_relativa'], 'La ruta usa el nombre ORIGINAL, no el físico');
        $this->assertSame($a['sha256'], $descriptor['sha256']);
        $this->assertSame($a['bytes'], $descriptor['bytes']);
        $this->assertSame('image/png', $descriptor['mime_real']);

        // Un evento que referencia ese nombre original queda enlazado aunque el archivo físico se llame distinto.
        $datos = $this->datos();
        $datos['evento'][0]['imagen_certificado'] = $original;
        $this->cargar('s1', $datos, $imagenes);

        $fila = DB::table('stg_ev_imagenes')->where('nombre_original', $original)->first();
        $this->assertNotNull($fila);
        $this->assertSame('document/certImages/'.$original, $fila->ruta_relativa);
        $this->assertSame($a['sha256'], $fila->sha256);
        $this->assertSame('png', $fila->extension);
        $this->assertFalse((bool) $fila->huerfana);
        $this->assertSame(1, (int) $fila->old_evento_id);
        $this->assertSame('ok', DB::table('stg_ev_evento')->where('old_id', 1)->value('imagen_estado'));
        $this->assertSame(0, DB::table('stg_ev_imagenes')->where('nombre_original', 'like', '__nombre_no_portable%')->count(), 'El nombre físico nunca llega al staging');
    }

    public function test_nombres_que_solo_difieren_en_mayusculas_o_tildes_son_imagenes_distintas(): void
    {
        // Caso real: en Linux «Ética» y «ETICA» son archivos distintos; la base de datos no debe fundirlos.
        $this->escribirManifiesto([
            $this->entrada('AGO 2026 - Ética.png', 'fisico_1.bin', $this->png('1')),
            $this->entrada('AGO 2026 - ETICA.png', 'fisico_2.bin', $this->png('2')),
            $this->entrada('ago 2026 - ética.png', 'fisico_3.bin', $this->png('3')),
        ]);

        $this->cargar('s1', null, EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir));

        $this->assertSame(3, DB::table('stg_ev_imagenes')->count());
        $this->assertSame(3, DB::table('stg_ev_imagenes')->distinct()->count('ruta_relativa'));
        $this->assertSame(1, DB::table('stg_ev_imagenes')->where('ruta_relativa', 'document/certImages/AGO 2026 - ETICA.png')->count());

        // Una segunda carga igual no confunde unas con otras.
        $b = $this->cargar('s2', null, EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir));
        $this->assertSame(3, $b->entidades['imagenes']['iguales']);
        $this->assertSame(3, DB::table('stg_ev_imagenes')->count());
    }

    public function test_el_hash_de_la_fila_depende_del_manifiesto_y_no_del_nombre_fisico(): void
    {
        $original = 'Con?signo.png';
        $this->escribirManifiesto([$this->entrada($original, 'fisico_a.bin', $this->png('x'))]);
        $a = EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir);
        $this->escribirManifiesto([$this->entrada($original, 'otro_nombre_fisico.bin', $this->png('x'))]);
        $b = EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir);

        $this->assertSame($a, $b);
    }

    public function test_falla_si_un_archivo_fisico_no_coincide_con_el_manifiesto(): void
    {
        $e = $this->entrada('a.png', 'a.png', $this->png());
        $this->escribirManifiesto([$e]);
        file_put_contents($this->dir.'/a.png', $this->png('alterado'));

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('no existen o no coinciden');
        EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir);
    }

    public function test_falla_si_el_contenido_cambia_con_el_mismo_tamano_y_se_pide_verificar_sha(): void
    {
        $contenido = $this->png('AAAA');
        $e = $this->entrada('a.png', 'a.png', $contenido);
        $this->escribirManifiesto([$e]);
        file_put_contents($this->dir.'/a.png', $this->png('BBBB'));   // mismo tamaño, distinto contenido

        $this->assertCount(1, EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir), 'Sin --verificar-sha solo se comprueba el tamaño');
        $this->expectException(InvalidArgumentException::class);
        EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir, verificarSha: true);
    }

    public function test_falla_si_falta_el_archivo_fisico(): void
    {
        $this->escribirManifiesto([$this->entrada('a.png', 'a.png', $this->png())]);
        unlink($this->dir.'/a.png');

        $this->expectException(InvalidArgumentException::class);
        EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir);
    }

    public function test_rechaza_manifiestos_invalidos(): void
    {
        foreach ([
            'no json' => 'esto no es json',
            'sin archivos' => json_encode(['otra' => 1]),
            'vacío' => json_encode(['archivos' => []]),
            'sha inválido' => json_encode(['archivos' => [['nombre_original' => 'a.png', 'nombre_local' => 'a', 'bytes' => 1, 'sha256' => 'xx', 'renderizable_fpdf' => true]]]),
            'nombre con barra' => json_encode(['archivos' => [['nombre_original' => 'a/b.png', 'nombre_local' => 'a', 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'renderizable_fpdf' => true]]]),
            'entrada incompleta' => json_encode(['archivos' => [['nombre_original' => 'a.png']]]),
            'nombre repetido' => json_encode(['archivos' => [
                ['nombre_original' => 'a.png', 'nombre_local' => 'a', 'bytes' => 1, 'sha256' => str_repeat('a', 64), 'renderizable_fpdf' => true],
                ['nombre_original' => 'a.png', 'nombre_local' => 'b', 'bytes' => 1, 'sha256' => str_repeat('b', 64), 'renderizable_fpdf' => true],
            ]]),
        ] as $caso => $contenido) {
            file_put_contents($this->manifiesto, $contenido);
            try {
                EscanerImagenes::desdeManifiesto($this->manifiesto);
                $this->fail("El manifiesto «{$caso}» debía rechazarse");
            } catch (InvalidArgumentException) {
                $this->addToAssertionCount(1);
            }
        }
    }

    public function test_un_manifiesto_con_imagen_no_renderizable_conserva_el_motivo(): void
    {
        $e = $this->entrada('Rara.7 cuipo', 'Rara.7 cuipo', $this->png('r'), renderizable: false);
        $e['motivo'] = 'TIPO_NO_SOPORTADO';
        $e['extension'] = '7 cuipo';
        $this->escribirManifiesto([$e]);

        $this->cargar('s1', null, EscanerImagenes::desdeManifiesto($this->manifiesto, $this->dir));

        $fila = DB::table('stg_ev_imagenes')->first();
        $this->assertFalse((bool) $fila->renderizable_fpdf);
        $this->assertSame('TIPO_NO_SOPORTADO', $fila->motivo_no_renderizable);
        $this->assertSame('error', $fila->validacion);
    }

    public function test_el_comando_exige_origen_y_dump(): void
    {
        $this->artisan('credential-flow:staging-ev:cargar', ['--manifest' => $this->manifiesto])->assertExitCode(1);
    }
}
