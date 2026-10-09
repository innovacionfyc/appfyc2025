<?php

namespace Tests\Feature\CredentialFlow\Rehearsal;

use App\Support\CredentialFlow\Legado\ImportadorImagenesLegado;
use App\Support\CredentialFlow\Legado\RutasLegado;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Feature\CredentialFlow\Historico\HistoricoTestCase;

/**
 * Fase 11A: importador de las imágenes históricas al storage privado (por SHA-256), con verificador y reversión. Las imágenes sintéticas de la migración de prueba son el
 * «material controlado»: `ruta_almacenada` queda NULL tras migrar, igual que en el histórico real.
 */
class ImportadorImagenesLegadoTest extends HistoricoTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
        $this->migrarSintetico($this->datos());
    }

    protected function tearDown(): void
    {
        ImportadorImagenesLegado::$despuesDeRenombrar = null;

        parent::tearDown();
    }

    private function imp(): ImportadorImagenesLegado
    {
        return new ImportadorImagenesLegado('local');
    }

    private function total(): int
    {
        return (int) DB::table('cf_plantillas_legado_contenidos')->count();
    }

    private function fisico(string $ruta): string
    {
        return Storage::disk('local')->path($ruta);
    }

    public function test_la_migracion_deja_las_rutas_en_null_y_el_importador_las_completa_por_sha(): void
    {
        $this->assertGreaterThan(0, $this->total());
        $this->assertSame($this->total(), DB::table('cf_plantillas_legado_contenidos')->whereNull('ruta_almacenada')->count());

        $r = $this->imp()->importar($this->dirImagenes);

        $this->assertSame([$this->total(), $this->total(), 0, 0, [], []], [$r['total'], $r['importados'], $r['reutilizados'], $r['verificados'], $r['faltantes'], $r['errores']]);
        foreach (DB::table('cf_plantillas_legado_contenidos')->get() as $c) {
            $this->assertSame(RutasLegado::plantilla($c->sha256, RutasLegado::extensionPorMime($c->mime_real)), $c->ruta_almacenada);
            $this->assertSame($c->sha256, hash_file('sha256', $this->fisico($c->ruta_almacenada)));
            $this->assertSame((int) $c->bytes, filesize($this->fisico($c->ruta_almacenada)));
        }
        $v = $this->imp()->verificar();
        $this->assertSame([$this->total(), $this->total(), []], [$v['total'], $v['ok'], $v['fallos']]);
        $this->assertSame(0, $this->imp()->huellaStorage()['temporales']);
    }

    public function test_la_segunda_corrida_no_cambia_nada_ni_la_bd_ni_el_storage(): void
    {
        $this->imp()->importar($this->dirImagenes);
        $manifiesto = $this->imp()->manifiesto()['huella'];
        $storage = $this->imp()->huellaStorage();
        $bd = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->get(['id', 'ruta_almacenada', 'updated_at'])->all();

        $r = $this->imp()->importar($this->dirImagenes);

        $this->assertSame([0, 0, $this->total(), null], [$r['importados'], $r['reutilizados'], $r['verificados'], $r['diario']]);
        $this->assertSame($manifiesto, $this->imp()->manifiesto()['huella']);
        $this->assertSame($storage, $this->imp()->huellaStorage());
        $this->assertEquals($bd, DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->get(['id', 'ruta_almacenada', 'updated_at'])->all(), 'ni siquiera se tocó updated_at');
    }

    public function test_una_corrida_interrumpida_se_reanuda_con_el_mismo_resultado_que_una_continua(): void
    {
        // Referencia: corrida continua.
        $this->imp()->importar($this->dirImagenes);
        $referencia = [$this->imp()->manifiesto()['huella'], $this->imp()->huellaStorage()['huella']];
        $this->imp()->revertir($this->imp()->importar($this->dirImagenes)['diario'] ?? $this->ultimoDiario());

        // Interrumpida: cae justo tras el rename del 2.º archivo (antes de guardar la ruta).
        Storage::fake('local');
        DB::table('cf_plantillas_legado_contenidos')->update(['ruta_almacenada' => null]);
        $n = 0;
        ImportadorImagenesLegado::$despuesDeRenombrar = function () use (&$n) {
            if (++$n === 2) {
                throw new RuntimeException('caída simulada');
            }
        };
        try {
            $this->imp()->importar($this->dirImagenes);
            $this->fail('Debía interrumpirse.');
        } catch (RuntimeException) {
            $this->assertSame(1, DB::table('cf_plantillas_legado_contenidos')->whereNotNull('ruta_almacenada')->count(), 'solo el primero quedó registrado');
        }
        ImportadorImagenesLegado::$despuesDeRenombrar = null;

        $r = $this->imp()->importar($this->dirImagenes);

        $this->assertSame(1, $r['verificados'], 'el primero ya estaba');
        $this->assertSame(1, $r['reutilizados'], 'el archivo del segundo existía sin ruta: se verifica y se registra');
        $this->assertSame($this->total() - 2, $r['importados']);
        $this->assertSame($referencia, [$this->imp()->manifiesto()['huella'], $this->imp()->huellaStorage()['huella']], 'idéntico a la corrida continua; sin duplicados');
        $this->assertSame($this->total(), $this->imp()->verificar()['ok']);
    }

    private function ultimoDiario(): string
    {
        $archivos = Storage::disk('local')->files(ImportadorImagenesLegado::DIARIOS);

        return basename((string) end($archivos), '.json');
    }

    public function test_el_limite_permite_corridas_parciales_y_completarlas(): void
    {
        $r = $this->imp()->importar($this->dirImagenes, null, false, 2);
        $this->assertSame([2, $this->total() - 2], [$r['importados'], $r['pendientes']]);
        $this->assertSame(2, $this->imp()->verificar()['ok']);

        $r = $this->imp()->importar($this->dirImagenes);
        $this->assertSame([$this->total() - 2, 2], [$r['importados'], $r['verificados']]);
    }

    public function test_simular_no_escribe_nada(): void
    {
        $r = $this->imp()->importar($this->dirImagenes, null, true);

        $this->assertSame([$this->total(), true], [$r['importados'], $r['simulado']]);
        $this->assertSame($this->total(), DB::table('cf_plantillas_legado_contenidos')->whereNull('ruta_almacenada')->count());
        $this->assertSame(0, $this->imp()->huellaStorage()['archivos']);
    }

    public function test_un_contenido_faltante_se_reporta_sin_inventar_y_no_deja_ruta(): void
    {
        $c = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->first();
        foreach (glob($this->dirImagenes.'/*') ?: [] as $f) {
            if (hash_file('sha256', $f) === $c->sha256) {
                unlink($f);
            }
        }
        // Si otro archivo comparte contenido (mismo SHA) sigue sirviendo: el contenido solo falta si NO queda ningún archivo con ese SHA.
        $queda = collect(glob($this->dirImagenes.'/*') ?: [])->contains(fn ($f) => hash_file('sha256', $f) === $c->sha256);
        $this->assertFalse($queda);

        $r = $this->imp()->importar($this->dirImagenes);

        $this->assertSame([(int) $c->id], $r['faltantes']);
        $this->assertNull(DB::table('cf_plantillas_legado_contenidos')->where('id', $c->id)->value('ruta_almacenada'));
        $this->assertFileDoesNotExist($this->fisico(RutasLegado::plantilla($c->sha256, RutasLegado::extensionPorMime($c->mime_real))));
        $this->assertSame($this->total() - 1, $r['importados']);
        $v = $this->imp()->verificar();
        $this->assertSame(['RUTA_NULA' => [(int) $c->id]], $v['fallos']);
    }

    public function test_un_destino_con_otro_contenido_nunca_se_sobrescribe(): void
    {
        $c = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->first();
        $ruta = RutasLegado::plantilla($c->sha256, RutasLegado::extensionPorMime($c->mime_real));
        Storage::disk('local')->put($ruta, 'contenido ajeno con otro SHA');

        $r = $this->imp()->importar($this->dirImagenes);

        $this->assertSame([(int) $c->id], $r['errores']['DESTINO_SHA_DISTINTO']);
        $this->assertSame('contenido ajeno con otro SHA', Storage::disk('local')->get($ruta), 'intacto');
        $this->assertNull(DB::table('cf_plantillas_legado_contenidos')->where('id', $c->id)->value('ruta_almacenada'), 'la ruta no apunta a un archivo inválido');
        $this->assertSame($this->total() - 1, $r['importados']);
    }

    public function test_un_origen_alterado_se_rechaza_y_no_deja_ni_archivo_ni_ruta(): void
    {
        $c = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->first();
        // Manifiesto que declara el SHA correcto para un archivo cuyo contenido fue alterado.
        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mf_'.bin2hex(random_bytes(4));
        mkdir($carpeta);
        file_put_contents($carpeta.'/falso.png', 'no es la imagen');
        $manifiesto = $carpeta.'/m.json';
        file_put_contents($manifiesto, json_encode(['archivos' => [['sha256' => $c->sha256, 'nombre_local' => 'falso.png']]]));

        $r = $this->imp()->importar($carpeta, $manifiesto);

        $this->assertSame([(int) $c->id], $r['errores']['ORIGEN_SHA_DISTINTO']);
        $this->assertNull(DB::table('cf_plantillas_legado_contenidos')->where('id', $c->id)->value('ruta_almacenada'));
        $this->assertFileDoesNotExist($this->fisico(RutasLegado::plantilla($c->sha256, RutasLegado::extensionPorMime($c->mime_real))));
        $this->assertSame(0, $this->imp()->huellaStorage()['temporales'], 'el temporal se borró');
        array_map('unlink', glob($carpeta.'/*'));
        rmdir($carpeta);
    }

    public function test_con_manifiesto_se_importa_igual_y_un_nombre_con_puntos_dobles_es_valido(): void
    {
        $carpeta = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mf_'.bin2hex(random_bytes(4));
        mkdir($carpeta);
        $entradas = [];
        foreach (glob($this->dirImagenes.'/*') ?: [] as $i => $f) {
            $nombre = "imagen {$i}..final.png";   // «..» dentro del nombre (como en el material real)
            copy($f, $carpeta.'/'.$nombre);
            $entradas[] = ['sha256' => hash_file('sha256', $f), 'nombre_local' => $nombre];
        }
        $entradas[] = ['sha256' => str_repeat('a', 64), 'nombre_local' => '../escape.png'];   // una ruta nunca se acepta
        file_put_contents($carpeta.'/m.json', json_encode(['archivos' => $entradas]));

        $r = $this->imp()->importar($carpeta, $carpeta.'/m.json');

        $this->assertSame([$this->total(), []], [$r['importados'], $r['faltantes']]);
        $this->assertSame($this->total(), $this->imp()->verificar()['ok']);
        array_map('unlink', glob($carpeta.'/*'));
        rmdir($carpeta);
    }

    public function test_revertir_borra_solo_lo_que_creo_la_corrida_y_restaura_las_rutas(): void
    {
        // Un archivo que YA existía (mismo SHA) y se reutiliza: la reversión no lo borra.
        $primero = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->first();
        $rutaPrevia = RutasLegado::plantilla($primero->sha256, RutasLegado::extensionPorMime($primero->mime_real));
        Storage::disk('local')->put($rutaPrevia, file_get_contents(collect(glob($this->dirImagenes.'/*'))->first(fn ($f) => hash_file('sha256', $f) === $primero->sha256)));

        $r = $this->imp()->importar($this->dirImagenes);
        $this->assertSame(1, $r['reutilizados']);

        $rev = $this->imp()->revertir($r['diario']);

        $this->assertSame($this->total() - 1, $rev['borrados']);
        $this->assertSame($this->total(), $rev['restauradas']);
        $this->assertSame($this->total(), DB::table('cf_plantillas_legado_contenidos')->whereNull('ruta_almacenada')->count());
        $this->assertFileExists($this->fisico($rutaPrevia), 'lo que ya existía se conserva');
        $this->assertSame(1, $this->imp()->huellaStorage()['archivos']);
        $this->expectException(\InvalidArgumentException::class);
        $this->imp()->revertir($r['diario']);   // ya revertido: el diario cambió de nombre
    }

    public function test_el_verificador_detecta_ruta_nula_archivo_ausente_bytes_sha_y_mime(): void
    {
        $this->imp()->importar($this->dirImagenes);
        // El material sintético trae pocos contenidos: se clonan filas ya importadas (archivo propio por SHA) hasta tener 5.
        $base = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->first();
        $n = 0;
        while (DB::table('cf_plantillas_legado_contenidos')->count() < 5) {
            $png = base64_decode('iVBORw0KGgoAAAANSUhEUgAAAAIAAAACCAYAAABytg0kAAAAFElEQVR4nGP8z8Dwn4EIwESMolGFoHAAADpmAQkbG5Z5AAAAAElFTkSuQmCC').str_repeat("\0", ++$n);
            $sha = hash('sha256', $png);
            $fila = (array) $base;
            unset($fila['id']);
            $fila['sha256'] = $sha;
            $fila['bytes'] = strlen($png);
            $fila['ruta_almacenada'] = RutasLegado::plantilla($sha, RutasLegado::extensionPorMime($base->mime_real));
            Storage::disk('local')->put($fila['ruta_almacenada'], $png);
            DB::table('cf_plantillas_legado_contenidos')->insert($fila);
        }
        $ids = DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->pluck('id')->all();
        $filas = DB::table('cf_plantillas_legado_contenidos')->whereIn('id', array_slice($ids, 0, 5))->orderBy('id')->get();
        DB::table('cf_plantillas_legado_contenidos')->where('id', $filas[0]->id)->update(['ruta_almacenada' => null]);
        unlink($this->fisico($filas[1]->ruta_almacenada));
        file_put_contents($this->fisico($filas[2]->ruta_almacenada), 'otra cosa');
        DB::table('cf_plantillas_legado_contenidos')->where('id', $filas[3]->id)->update(['mime_real' => 'image/jpeg']);
        DB::table('cf_plantillas_legado_contenidos')->where('id', $filas[4]->id)->update(['sha256' => str_repeat('b', 64)]);

        $f = $this->imp()->verificar()['fallos'];

        $this->assertSame([(int) $filas[0]->id], $f['RUTA_NULA']);
        $this->assertSame([(int) $filas[1]->id], $f['ARCHIVO_INEXISTENTE']);
        $this->assertSame([(int) $filas[2]->id], $f['BYTES_DISTINTOS']);
        $this->assertSame([(int) $filas[3]->id], $f['MIME_DISTINTO']);
        $this->assertSame([(int) $filas[4]->id], $f['SHA_DISTINTO']);
    }

    public function test_los_temporales_huerfanos_viejos_se_limpian_y_los_recientes_no(): void
    {
        $dir = Storage::disk('local')->path(RutasLegado::RAIZ.'/plantillas/'.str_repeat('c', 64));
        mkdir($dir, 0775, true);
        file_put_contents($dir.'/.original.png.aaaaaaaaaaaa.tmp', 'x');
        file_put_contents($dir.'/.original.png.bbbbbbbbbbbb.tmp', 'x');
        touch($dir.'/.original.png.aaaaaaaaaaaa.tmp', time() - 3600);

        $this->imp()->importar($this->dirImagenes);

        $this->assertFileDoesNotExist($dir.'/.original.png.aaaaaaaaaaaa.tmp');
        $this->assertFileExists($dir.'/.original.png.bbbbbbbbbbbb.tmp', 'podría ser de una corrida en curso');
    }

    public function test_el_manifiesto_es_determinista_y_no_tiene_datos_personales(): void
    {
        $this->imp()->importar($this->dirImagenes);
        $a = $this->imp()->manifiesto();
        $b = $this->imp()->manifiesto();

        $this->assertSame($a['huella'], $b['huella']);
        $this->assertSame(['contenido_id', 'sha256', 'bytes', 'mime', 'ruta'], array_keys($a['archivos'][0]));
        $this->assertStringNotContainsString('@', json_encode($a));
    }

    public function test_el_comando_devuelve_no_go_ante_faltantes_y_ok_en_la_segunda_corrida(): void
    {
        $this->artisan('credential-flow:legado:importar-imagenes', ['--origen' => $this->dirImagenes])->assertExitCode(0);
        $this->artisan('credential-flow:legado:importar-imagenes', ['--origen' => $this->dirImagenes, '--json' => true])->expectsOutputToContain('"importados":0')->assertExitCode(0);
        $this->artisan('credential-flow:legado:importar-imagenes', ['--verificar' => true])->assertExitCode(0);
        DB::table('cf_plantillas_legado_contenidos')->orderBy('id')->limit(1)->update(['ruta_almacenada' => null]);
        $this->artisan('credential-flow:legado:importar-imagenes', ['--verificar' => true])->assertExitCode(1);
        $this->artisan('credential-flow:legado:importar-imagenes')->assertExitCode(1);   // sin --origen
    }
}
