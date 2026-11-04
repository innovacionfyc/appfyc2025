<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Plantillas\ProteccionDiseno;
use Database\Seeders\AreaFormacionSeeder;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\EstadosSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\TipoDocumentosSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\PdfBase;
use Tests\TestCase;

/**
 * C2 · Protección del diseño con CONCURRENCIA REAL: procesos PHP independientes contra MariaDB (los bloqueos de fila solo existen aquí). El guardado del
 * diseño (bloqueo exclusivo de la plantilla + nueva comprobación) y lo que crea dependencias (emisiones con `sharedLock`, bases nuevas con la clave
 * foránea, asociación de variantes) siguen el MISMO protocolo: nunca termina guardado un diseño que, al confirmarse, ya tenía emisiones o más de una base.
 * Datos ficticios; storage en una carpeta temporal; nunca SMTP ni red. Solo con una base local de pruebas, por ejemplo la MariaDB 10.6 desechable del 3307:
 *
 *   DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=root DB_PASSWORD= TEST_MYSQL_DATABASE=cf_proteccion_test php vendor/bin/pest tests/Integracion/ProteccionDisenoConcurrenciaMysqlTest.php
 */
class ProteccionDisenoConcurrenciaMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private string $base = '';

    private string $dir = '';

    protected function setUp(): void
    {
        parent::setUp();

        $this->base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');
        if ($this->base === '' || ! preg_match('/_(dev|test|testing)$/', $this->base) || $this->base === ($config['database'] ?? '')
            || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }
        config([
            'database.connections.'.self::CONEXION => [...$config, 'database' => $this->base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);
        // Red de seguridad: lo que sigue BORRA tablas; solo en una base de pruebas.
        $this->assertMatchesRegularExpression('/_(dev|test|testing)$/', (string) DB::connection()->getDatabaseName());
        $this->assertNotSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        foreach ([EstadosSeeder::class, TipoDocumentosSeeder::class, AreaFormacionSeeder::class, EquipoSeeder::class, RolSeeder::class, UsuarioSeeder::class] as $seeder) {
            $this->assertSame(0, Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]));
        }

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'proteccion_mysql_'.bin2hex(random_bytes(6));
        foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $d) {
            mkdir($this->dir.DIRECTORY_SEPARATOR.$d, 0777, true);
        }
        config(['filesystems.disks.local.root' => $this->dir.'/storage/app/private', 'credential_flow.emision.exige_vista_previa' => false, 'credential_flow.qa.exigir_catalogo' => false]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        ProteccionDiseno::$antesDeGuardar = null;
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function plantilla(bool $qa = false): Plantilla
    {
        $bytes = PdfBase::crear();
        $p = Plantilla::create(['nombre' => 'Plantilla '.uniqid(), 'descripcion' => 'Prueba', 'archivo_pdf' => 'pendiente', 'nombre_archivo_original' => 'base.pdf', 'hash_sha256' => hash('sha256', $bytes)]);
        Storage::disk('local')->put($p->rutaPdfEsperada(), $bytes);
        $elementos = [];
        foreach (['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria', null] as $i => $campo) {
            $elementos[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i + 1), 'type' => 'text', 'field' => $campo, 'text' => $campo === null ? ($qa ? 'PRUEBA INTERNA — SIN VALIDEZ ACADÉMICA' : 'por su asistencia a la') : '',
                'x' => 96.5, 'y' => 60 + $i * 60, 'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 16, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center'];
        }
        $p->update(['archivo_pdf' => $p->rutaPdfEsperada(), 'diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos], 'schema_version' => 1]);
        if ($qa) {
            $p->forceFill(['es_plantilla_qa' => true])->save();
        }

        return $p->fresh();
    }

    private function lote(Plantilla $p, int $personas = 1): Lote
    {
        $lote = Lote::create(['plantilla_id' => $p->id, 'nombre' => 'Base concurrencia '.uniqid(),
            'datos_comunes' => ['evento' => 'JORNADA DE CONCURRENCIA', 'fecha' => '4 de noviembre de 2026', 'intensidad_horaria' => '2 horas']]);
        for ($i = 1; $i <= $personas; $i++) {
            $doc = 'C.C. '.number_format(60_000_000 + $lote->id * 1000 + $i, 0, ',', '.');
            $lote->participantes()->create(['nombre_completo' => "PERSONA FICTICIA {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc)]);
        }

        return $lote->fresh();
    }

    private function y(Plantilla $p): int
    {
        return (int) Plantilla::findOrFail($p->id)->diseno['elements'][0]['y'];
    }

    private function escribirTrabajador(): string
    {
        $raiz = var_export(base_path(), true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $codigo = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        [, \$modo, \$a, \$b, \$retraso, \$pausa] = \$argv + [null, null, '', '', '0', '0'];
        \$admin = App\\Models\\Usuario::where('correo_principal', 'erik@fycconsultores.com')->firstOrFail();
        while (! file_exists({$go})) { usleep(2000); }
        usleep(1000 * (int) \$retraso);
        \$inicio = microtime(true);
        try {
            if (\$modo === 'guardar') {
                // Pausa con la plantilla YA bloqueada y comprobada, justo antes de escribir (la ventana de la carrera).
                App\\Support\\CredentialFlow\\Plantillas\\ProteccionDiseno::\$antesDeGuardar = fn () => usleep(1000 * (int) \$pausa);
                \$r = App\\Support\\CredentialFlow\\Plantillas\\ProteccionDiseno::guardarSiEditable((int) \$a, function (\$p) {
                    \$d = \$p->diseno; \$d['elements'][0]['y'] = 333; \$p->update(['diseno' => \$d]);
                });
                echo json_encode(['ok' => \$r === null, 'codigo' => \$r === null ? null : (str_contains(\$r, 'dependencias') ? 'PROTEGIDA' : 'OTRO'), 'ms' => (int) ((microtime(true) - \$inicio) * 1000)]);
            } elseif (\$modo === 'emitir') {
                // Emisión individual con una pausa DENTRO de su fase crítica (ya leída la plantilla, antes del insert).
                App\\Support\\CredentialFlow\\Emisiones\\AlmacenEmisiones::\$antesDeEscribir = fn () => usleep(1000 * (int) \$pausa);
                \$lote = App\\Models\\CredentialFlow\\Lote::findOrFail((int) \$a);
                \$e = app(App\\Support\\CredentialFlow\\Emisiones\\EmisorCredencial::class)->emitir(\$lote->participantes()->orderBy('id')->firstOrFail(), \$lote, \$admin->id);
                echo json_encode(['ok' => true, 'id' => \$e->id, 'ms' => (int) ((microtime(true) - \$inicio) * 1000)]);
            } elseif (\$modo === 'emitir_lote') {
                \$lote = App\\Models\\CredentialFlow\\Lote::findOrFail((int) \$a);
                \$r = app(App\\Support\\CredentialFlow\\Emisiones\\EmisorLote::class)->emitirTodo(\$lote, \$admin->id);
                echo json_encode(['ok' => true, 'ms' => (int) ((microtime(true) - \$inicio) * 1000)]);
            } elseif (\$modo === 'base') {
                // Una base nueva con esta plantilla (lo mismo que hacen la importación y el cambio de plantilla: escribir cf_lotes.plantilla_id), con pausa antes del commit.
                Illuminate\\Support\\Facades\\DB::transaction(function () use (\$a, \$pausa) {
                    App\\Models\\CredentialFlow\\Lote::create(['plantilla_id' => (int) \$a, 'nombre' => 'Base concurrente '.uniqid(), 'datos_comunes' => ['evento' => 'X', 'fecha' => 'Y', 'intensidad_horaria' => 'Z']]);
                    usleep(1000 * (int) \$pausa);
                });
                echo json_encode(['ok' => true]);
            } elseif (\$modo === 'variante') {
                Illuminate\\Support\\Facades\\DB::transaction(function () use (\$a, \$b, \$pausa) {
                    app(App\\Support\\CredentialFlow\\Qa\\ServicioQa::class)->asignarVariante(App\\Models\\CredentialFlow\\Plantilla::findOrFail((int) \$a), App\\Models\\CredentialFlow\\Plantilla::findOrFail((int) \$b));
                    usleep(1000 * (int) \$pausa);
                });
                echo json_encode(['ok' => true]);
            }
        } catch (App\\Support\\CredentialFlow\\Emisiones\\EmisionException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e->codigo, 'ms' => (int) ((microtime(true) - \$inicio) * 1000)]);
        } catch (Throwable \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 400)], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /**
     * @param  list<list<string>>  $argumentos  [modo, a, b, retraso_ms, pausa_ms]
     * @return list<array<string,mixed>>
     */
    private function enParalelo(array $argumentos): array
    {
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->dir.DIRECTORY_SEPARATOR.'storage', 'CREDENTIAL_FLOW_EXIGE_VISTA_PREVIA' => 'false',
        ]);
        $procesos = [];
        foreach ($argumentos as $args) {
            $p = proc_open([PHP_BINARY, $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $entorno);
            $this->assertIsResource($p);
            $procesos[] = [$p, $pipes];
        }
        usleep(2_500_000);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'go', '1');

        $salidas = [];
        foreach ($procesos as [$p, $pipes]) {
            $salida = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $codigo = proc_close($p);
            $json = json_decode((string) $salida, true);
            $this->assertIsArray($json, "salida inesperada de un trabajador (código de salida {$codigo}): ".substr($salida.' | '.$error, 0, 1500));
            $salidas[] = $json;
        }

        return $salidas;
    }

    public function test_si_la_emision_entra_primero_el_guardado_espera_y_queda_protegido(): void
    {
        $p = $this->plantilla();
        $lote = $this->lote($p);
        $antes = $this->y($p);

        // La emisión pasa la huella y se detiene 1,5 s antes del insert; el guardado llega a los 0,4 s.
        [$emision, $guardado] = $this->enParalelo([['emitir', (string) $lote->id, '', '0', '1500'], ['guardar', (string) $p->id, '', '400', '0']]);

        $this->assertTrue($emision['ok'], json_encode([$emision, $guardado]));
        $this->assertFalse($guardado['ok'], json_encode([$emision, $guardado]));
        $this->assertSame('PROTEGIDA', $guardado['codigo']);
        $this->assertGreaterThan(800, $guardado['ms'], 'el guardado esperó a que la emisión confirmara');
        $this->assertSame($antes, $this->y($p), 'el diseño no cambió');
        $this->assertSame(1, Emision::where('plantilla_id', $p->id)->count());
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_si_el_guardado_entra_primero_la_emision_no_sale_con_el_diseno_viejo(): void
    {
        $p = $this->plantilla();
        $lote = $this->lote($p);

        // El guardado bloquea y comprueba (una base, sin emisiones: legítimo) y se detiene 1,5 s; la emisión llega a los 0,4 s con su PDF del diseño viejo.
        [$guardado, $emision] = $this->enParalelo([['guardar', (string) $p->id, '', '0', '1500'], ['emitir', (string) $lote->id, '', '400', '0']]);

        $this->assertTrue($guardado['ok'], json_encode([$guardado, $emision]));
        $this->assertFalse($emision['ok'], json_encode([$guardado, $emision]));
        $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $emision['codigo']);
        $this->assertSame(333, $this->y($p));
        $this->assertSame(0, Emision::count(), 'ningún certificado con el diseño anterior');
    }

    public function test_emision_masiva_y_guardado_a_la_vez_nunca_terminan_ambos(): void
    {
        foreach ([0, 150, 400] as $ronda => $retraso) {
            $p = $this->plantilla();
            $lote = $this->lote($p, 6);
            $antes = $this->y($p);

            [$emision, $guardado] = $this->enParalelo([['emitir_lote', (string) $lote->id, '', '0', '0'], ['guardar', (string) $p->id, '', (string) $retraso, '300']]);
            $traza = "ronda {$ronda}: ".json_encode([$emision, $guardado]);

            $this->assertNotSame($emision['ok'], $guardado['ok'], 'exactamente uno gana · '.$traza);
            if ($emision['ok']) {
                $this->assertSame('PROTEGIDA', $guardado['codigo'], $traza);
                $this->assertSame($antes, $this->y($p), $traza);
                $this->assertSame(6, Emision::where('lote_id', $lote->id)->count(), $traza);
            } else {
                $this->assertSame('DATOS_CAMBIARON_DURANTE_EMISION', $emision['codigo'], $traza);
                $this->assertSame(333, $this->y($p), $traza);
                $this->assertSame(0, Emision::where('lote_id', $lote->id)->count(), $traza);
            }
        }
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_una_base_nueva_sin_confirmar_hace_esperar_al_guardado_y_lo_bloquea(): void
    {
        $p = $this->plantilla();
        $this->lote($p);
        $antes = $this->y($p);

        // La segunda base se inserta (clave foránea → bloqueo compartido de la plantilla) y tarda 1,5 s en confirmar; el guardado llega a los 0,4 s.
        [$base, $guardado] = $this->enParalelo([['base', (string) $p->id, '', '0', '1500'], ['guardar', (string) $p->id, '', '400', '0']]);

        $this->assertTrue($base['ok'], json_encode([$base, $guardado]));
        $this->assertSame([false, 'PROTEGIDA'], [$guardado['ok'], $guardado['codigo']], json_encode([$base, $guardado]));
        $this->assertSame($antes, $this->y($p));
        $this->assertSame(2, Lote::where('plantilla_id', $p->id)->count());
    }

    public function test_asociar_una_variante_a_una_oficial_con_varias_bases_protege_el_diseno_de_la_variante(): void
    {
        $oficial = $this->plantilla();
        $this->lote($oficial);
        $this->lote($oficial);
        $variante = $this->plantilla(true);
        $antes = $this->y($variante);
        $this->assertFalse(ProteccionDiseno::protegida($variante), 'sin asociar, la variante aún no tiene bases');

        [$asociar, $guardado] = $this->enParalelo([['variante', (string) $oficial->id, (string) $variante->id, '0', '1500'], ['guardar', (string) $variante->id, '', '400', '0']]);

        $this->assertTrue($asociar['ok'], json_encode([$asociar, $guardado]));
        $this->assertSame([false, 'PROTEGIDA'], [$guardado['ok'], $guardado['codigo']], json_encode([$asociar, $guardado]));
        $this->assertSame($antes, $this->y($variante));
        $this->assertSame($variante->id, (int) $oficial->fresh()->variante_qa_id);
    }

    public function test_control_negativo_plantillas_distintas_no_se_esperan_ni_se_bloquean(): void
    {
        // Una emisión de la plantilla A no debe frenar ni bloquear el guardado de una plantilla B nueva (sin dependencias).
        $a = $this->plantilla();
        $loteA = $this->lote($a);
        $b = $this->plantilla();

        [$emision, $guardado] = $this->enParalelo([['emitir', (string) $loteA->id, '', '0', '1500'], ['guardar', (string) $b->id, '', '400', '0']]);

        $this->assertTrue($emision['ok'], json_encode([$emision, $guardado]));
        $this->assertTrue($guardado['ok'], json_encode([$emision, $guardado]));
        $this->assertLessThan(1000, $guardado['ms'], 'no esperó al bloqueo de otra plantilla');
        $this->assertSame(333, $this->y($b));
        $this->assertSame(1, Emision::where('plantilla_id', $a->id)->count());
    }
}
