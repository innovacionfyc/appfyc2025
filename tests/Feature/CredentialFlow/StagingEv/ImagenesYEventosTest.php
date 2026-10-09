<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;

/** Inventario de imágenes, validación de eventos, huérfanas y candidatas. Imágenes sintéticas de 1×1 píxel. */
class ImagenesYEventosTest extends StagingEvTestCase
{
    private string $dir;

    protected function setUp(): void
    {
        parent::setUp();
        if (! function_exists('imagecreatetruecolor')) {
            $this->markTestSkipped('Se necesita GD para crear las imágenes sintéticas.');
        }
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'stg_ev_img_'.bin2hex(random_bytes(6));
        mkdir($this->dir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->dir);
        parent::tearDown();
    }

    private function png(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    private function jpg(): string
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagejpeg($im);

        return (string) ob_get_clean();
    }

    private function escribir(string $nombre, string $contenido): void
    {
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.$nombre, $contenido);
    }

    /** @return array<int,array<string,mixed>> */
    private function escanear(): array
    {
        return (new EscanerImagenes)->escanear($this->dir);
    }

    private function porNombre(array $imagenes, string $nombre): array
    {
        foreach ($imagenes as $i) {
            if ($i['nombre_original'] === $nombre) {
                return $i;
            }
        }
        $this->fail("No se inventarió $nombre");
    }

    public function test_el_escaner_describe_cada_archivo_sin_modificarlo(): void
    {
        $png = $this->png();
        $this->escribir('ALFA 2024.png', $png);
        $this->escribir('BETA 2025.jpg', $this->jpg());
        $antes = hash_file('sha256', $this->dir.'/ALFA 2024.png');

        $imgs = $this->escanear();

        $a = $this->porNombre($imgs, 'ALFA 2024.png');
        $this->assertSame('document/certImages/ALFA 2024.png', $a['ruta_relativa']);
        $this->assertSame($antes, $a['sha256']);
        $this->assertSame(strlen($png), $a['bytes']);
        $this->assertSame([2, 2, 'image/png', 'png', true, null], [$a['ancho_px'], $a['alto_px'], $a['mime_real'], $a['extension'], $a['renderizable_fpdf'], $a['motivo_no_renderizable']]);
        $this->assertSame('image/jpeg', $this->porNombre($imgs, 'BETA 2025.jpg')['mime_real']);
        $this->assertSame($antes, hash_file('sha256', $this->dir.'/ALFA 2024.png'), 'El escáner no modifica nada');
    }

    public function test_reglas_de_renderizado_replicadas_de_fpdf(): void
    {
        $png = $this->png();
        $interlazado = $png;
        $interlazado[28] = "\x01";
        $dieciseis = $png;
        $dieciseis[24] = "\x10";

        $this->escribir('ok.png', $png);
        $this->escribir('ok.JPG', $this->jpg());
        $this->escribir('ok.jpeg', $this->jpg());
        $this->escribir('sin extension', $png);
        $this->escribir('extension rara.7 cuipo', $png);
        $this->escribir('texto.png', 'esto no es una imagen');
        $this->escribir('jpg disfrazado.png', $this->jpg());
        $this->escribir('png disfrazado.jpg', $png);
        $this->escribir('entrelazado.png', $interlazado);
        $this->escribir('16 bits.png', $dieciseis);
        $this->escribir('otro.webp', $png);

        $imgs = $this->escanear();
        $motivo = fn (string $n) => $this->porNombre($imgs, $n)['motivo_no_renderizable'];
        $ok = fn (string $n) => $this->porNombre($imgs, $n)['renderizable_fpdf'];

        foreach (['ok.png', 'ok.JPG', 'ok.jpeg'] as $n) {
            $this->assertTrue($ok($n), $n);
        }
        $this->assertSame('SIN_EXTENSION', $motivo('sin extension'));
        $this->assertSame('TIPO_NO_SOPORTADO', $motivo('extension rara.7 cuipo'));
        $this->assertSame('NO_ES_PNG', $motivo('texto.png'));
        $this->assertSame('NO_ES_PNG', $motivo('jpg disfrazado.png'));
        $this->assertSame('NO_ES_JPEG', $motivo('png disfrazado.jpg'));
        $this->assertSame('PNG_ENTRELAZADO', $motivo('entrelazado.png'));
        $this->assertSame('PNG_16_BITS', $motivo('16 bits.png'));
        $this->assertSame('TIPO_NO_SOPORTADO', $motivo('otro.webp'));
    }

    public function test_cruce_con_el_inventario_del_servidor(): void
    {
        $this->escribir('a.png', $this->png());
        $this->escribir('b.png', $this->png().'x');
        $imgs = $this->escanear();
        $a = $this->porNombre($imgs, 'a.png');
        $inventario = $this->dir.'.txt';
        file_put_contents($inventario, "ruta_relativa|bytes|sha256\n{$a['ruta_relativa']}|{$a['bytes']}|{$a['sha256']}\ndocument/certImages/b.png|1|".str_repeat('0', 64)."\ndocument/certImages/falta.png|1|".str_repeat('1', 64)."\n");

        $r = EscanerImagenes::compararConInventario($imgs, $inventario);
        unlink($inventario);

        $this->assertSame(['coinciden' => 1, 'distintas' => 1, 'solo_en_inventario' => 1, 'solo_en_carpeta' => 0], array_diff_key($r, ['sha256_inventario' => 1]));
    }

    public function test_eventos_con_imagen_sin_imagen_faltante_y_extension_invalida(): void
    {
        $this->escribir('ALFA 2024.png', $this->png());
        $this->escribir('BETA 2025.jpg', $this->jpg());
        $this->escribir('DELTA 2023', $this->png());               // existe, pero sin extensión (como el evento 1019)
        $this->escribir('ORFANA.png', $this->png().'o');          // nadie la usa
        $this->escribir('GAMMA_.png', $this->png().'g');          // parece la de «GAMMA.png» (que no existe)
        $this->cargar('s1', null, $this->escanear());

        $estado = fn (int $id) => DB::table('stg_ev_evento')->where('old_id', $id)->first();
        $this->assertSame('ok', $estado(1)->imagen_estado);
        $this->assertSame('ok', $estado(2)->imagen_estado);
        $this->assertSame('archivo_faltante', $estado(3)->imagen_estado);
        $this->assertSame('extension_invalida', $estado(4)->imagen_estado);
        $this->assertSame('sin_imagen', $estado(5)->imagen_estado);
        $this->assertSame('archivo_faltante', $estado(6)->imagen_estado);

        $this->assertNotNull($estado(4)->imagen_ref_id, 'El archivo del evento con extensión inválida SÍ existe');
        $this->assertNull($estado(3)->imagen_ref_id);

        foreach ([3, 4, 5] as $id) {
            $this->assertSame('error', $estado($id)->validacion, "evento $id");
        }
        $this->assertContains('IMAGEN_FALTANTE', explode(',', $estado(3)->motivo));
        $this->assertContains('IMAGEN_EXTENSION_INVALIDA', explode(',', $estado(4)->motivo));
        $this->assertContains('SIN_IMAGEN', explode(',', $estado(5)->motivo));
    }

    public function test_anio_deducible_ambiguo_y_no_deducible(): void
    {
        $this->cargar('s1');

        $e = fn (int $id) => DB::table('stg_ev_evento')->where('old_id', $id)->first();
        $this->assertSame(2024, (int) $e(1)->anio_deducido);
        $this->assertSame('nombre', $e(1)->anio_origen);
        $this->assertNull($e(3)->anio_deducido);
        $this->assertNull($e(3)->anio_origen);
        $this->assertContains('ANIO_NO_DEDUCIBLE', explode(',', $e(3)->motivo));
        $this->assertNull($e(6)->anio_deducido, 'Dos años distintos: no se elige ninguno');
        $this->assertSame('ambiguo', $e(6)->anio_origen);
        $this->assertContains('ANIO_AMBIGUO', explode(',', $e(6)->motivo));
    }

    public function test_huerfanas_candidatas_y_no_renderizables(): void
    {
        $this->escribir('ALFA 2024.png', $this->png());
        $this->escribir('ORFANA.png', $this->png().'o');
        $this->escribir('GAMMA_.png', $this->png().'g');
        $this->escribir('falsa.png', 'no es png');
        $this->cargar('s1', null, $this->escanear());

        $i = fn (string $n) => DB::table('stg_ev_imagenes')->where('nombre_original', $n)->first();
        $this->assertFalse((bool) $i('ALFA 2024.png')->huerfana);
        $this->assertSame(1, (int) $i('ALFA 2024.png')->old_evento_id);
        $this->assertSame(1, (int) $i('ALFA 2024.png')->eventos_enlazados);

        $this->assertTrue((bool) $i('ORFANA.png')->huerfana);
        $this->assertFalse((bool) $i('ORFANA.png')->candidata_revision);

        $this->assertTrue((bool) $i('GAMMA_.png')->huerfana);
        $this->assertTrue((bool) $i('GAMMA_.png')->candidata_revision);
        $this->assertSame(3, (int) $i('GAMMA_.png')->candidata_old_evento_id, 'Parece ser la imagen del evento 3, pero NO se enlaza sola');
        $this->assertNull($i('GAMMA_.png')->old_evento_id);
        $this->assertContains('CANDIDATA_REVISION', explode(',', $i('GAMMA_.png')->motivo));

        $this->assertFalse((bool) $i('falsa.png')->renderizable_fpdf);
        $this->assertSame('error', $i('falsa.png')->validacion);
        $this->assertContains('NO_RENDERIZABLE', explode(',', $i('falsa.png')->motivo));
    }

    public function test_una_candidata_ambigua_no_se_propone(): void
    {
        $this->escribir('GAMMA_.png', $this->png().'g');
        $this->escribir('GAMMA-.png', $this->png().'h');   // dos huérfanas con la misma clave
        $this->cargar('s1', null, $this->escanear());

        $this->assertSame(0, DB::table('stg_ev_imagenes')->where('candidata_revision', true)->count());
    }

    public function test_recargar_imagenes_idempotente_y_cambio_de_contenido(): void
    {
        $this->escribir('ALFA 2024.png', $this->png());
        $a = $this->cargar('s1', null, $this->escanear());
        $b = $this->cargar('s2', null, $this->escanear());
        $this->assertSame(1, $b->entidades['imagenes']['iguales']);
        $this->assertSame(0, $b->entidades['imagenes']['nuevas']);

        $this->escribir('ALFA 2024.png', $this->png().'cambio');
        $c = $this->cargar('s3', null, $this->escanear());
        $this->assertSame(1, $c->entidades['imagenes']['cambiadas']);
        $h = DB::table('stg_ev_historial')->where('entidad', 'imagenes')->first();
        $this->assertSame('document/certImages/ALFA 2024.png', $h->clave);
        $this->assertNull($h->old_id);
        $this->assertSame($a->snapshotId, (int) DB::table('stg_ev_imagenes')->value('primera_snapshot_id'));

        $this->cargar('s4', null, []);
        $this->assertSame('ausente_en_origen', DB::table('stg_ev_imagenes')->value('estado_fila'));
        $this->assertSame(1, DB::table('stg_ev_imagenes')->count(), 'Una imagen ausente no se borra');
    }

    public function test_sin_inventario_de_imagenes_el_estado_de_imagen_queda_sin_evaluar(): void
    {
        $this->cargar('s1');

        $this->assertSame(6, DB::table('stg_ev_evento')->whereNull('imagen_estado')->count());
        $this->assertSame(0, DB::table('stg_ev_imagenes')->count());
    }
}
