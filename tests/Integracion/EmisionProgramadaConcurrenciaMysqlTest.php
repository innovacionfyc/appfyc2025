<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Programacion\ServicioProgramacion;
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
 * Emisión programada con CONCURRENCIA REAL: procesos PHP independientes contra MySQL/MariaDB real (el índice único `lote_activa` y los bloqueos de fila solo se
 * prueban de verdad aquí). Datos ficticios; storage en una carpeta temporal; nunca SMTP ni red. Solo con una base local de pruebas, por ejemplo la MariaDB
 * 10.6 desechable del puerto 3307:
 *
 *   DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=root DB_PASSWORD= TEST_MYSQL_DATABASE=cf_programada_test php artisan test tests/Integracion/EmisionProgramadaConcurrenciaMysqlTest.php
 */
class EmisionProgramadaConcurrenciaMysqlTest extends TestCase
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
        foreach ([EstadosSeeder::class, TipoDocumentosSeeder::class, AreaFormacionSeeder::class,
            EquipoSeeder::class, RolSeeder::class, UsuarioSeeder::class] as $seeder) {
            $this->assertSame(0, Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]));
        }

        // Storage propio (el de los trabajadores es el mismo): nunca el de desarrollo.
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'programada_mysql_'.bin2hex(random_bytes(6));
        foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $d) {
            mkdir($this->dir.DIRECTORY_SEPARATOR.$d, 0777, true);
        }
        config(['filesystems.disks.local.root' => $this->dir.'/storage/app/private', 'credential_flow.emision.exige_vista_previa' => false, 'credential_flow.programacion.activa' => true]);
        Storage::forgetDisk('local');
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    /** Base lista para emitir (sin QA): plantilla real con PDF base y N participantes ficticios. */
    private function baseLista(int $participantes): Lote
    {
        $bytes = PdfBase::crear();
        $p = Plantilla::create(['nombre' => 'Plantilla concurrencia '.uniqid(), 'descripcion' => 'Prueba', 'archivo_pdf' => 'pendiente', 'nombre_archivo_original' => 'base.pdf', 'hash_sha256' => hash('sha256', $bytes)]);
        Storage::disk('local')->put($p->rutaPdfEsperada(), $bytes);
        $elementos = [];
        foreach (['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria'] as $i => $campo) {
            $elementos[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i + 1), 'type' => 'text', 'field' => $campo, 'text' => '', 'x' => 96, 'y' => 60 + $i * 60,
                'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 22, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center'];
        }
        $p->update(['archivo_pdf' => $p->rutaPdfEsperada(), 'diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos], 'schema_version' => 1]);
        // La emisión programada exige siempre la variante de pruebas internas de la plantilla.
        $this->assertSame(0, Artisan::call('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true]), Artisan::output());

        $lote = Lote::create(['plantilla_id' => $p->id, 'nombre' => 'Base concurrencia '.uniqid(),
            'datos_comunes' => ['evento' => 'TALLER DE CONCURRENCIA', 'fecha' => '17 de septiembre de 2026', 'intensidad_horaria' => '8 horas']]);
        for ($i = 1; $i <= $participantes; $i++) {
            $doc = 'C.C. '.number_format(50_000_000 + $i, 0, ',', '.');
            $lote->participantes()->create(['nombre_completo' => "PERSONA FICTICIA {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc)]);
        }

        return $lote->fresh();
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
        [, \$modo, \$loteId, \$fecha, \$retraso] = \$argv + [null, null, null, null, '0'];
        \$admin = App\\Models\\Usuario::where('correo_principal', 'erik@fycconsultores.com')->firstOrFail();
        \$lote = App\\Models\\CredentialFlow\\Lote::findOrFail((int) \$loteId);
        while (! file_exists({$go})) { usleep(2000); }
        usleep(1000 * (int) \$retraso);
        try {
            if (\$modo === 'programar') {
                \$p = app(App\\Support\\CredentialFlow\\Programacion\\ServicioProgramacion::class)->programar(\$lote, \$fecha, \$admin);
                echo json_encode(['ok' => true, 'id' => \$p->id]);
            } elseif (\$modo === 'ejecutor') {
                echo json_encode(['ok' => true, 'lineas' => app(App\\Support\\CredentialFlow\\Programacion\\EjecutorProgramado::class)->ejecutar()], JSON_THROW_ON_ERROR);
            } else {
                \$r = app(App\\Support\\CredentialFlow\\Emisiones\\EmisorLote::class)->emitirTodo(\$lote, \$admin->id);
                echo json_encode(['ok' => true, 'emitidos' => \$r['oficiales']['total'] ?? 0]);
            }
        } catch (App\\Support\\CredentialFlow\\Programacion\\ProgramacionException|App\\Support\\CredentialFlow\\Emisiones\\EmisionException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e->codigo]);
        } catch (Throwable \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 400)], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /**
     * Arranca los procesos, espera a que todos estén listos, los suelta a la vez y devuelve lo que respondió cada uno.
     *
     * @param  list<list<string>>  $argumentos
     * @return list<array<string,mixed>>
     */
    private function enParalelo(array $argumentos): array
    {
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->dir.DIRECTORY_SEPARATOR.'storage', 'CREDENTIAL_FLOW_PROGRAMACION_ACTIVA' => 'true', 'CREDENTIAL_FLOW_EXIGE_VISTA_PREVIA' => 'false',
        ]);
        $procesos = [];
        foreach ($argumentos as $args) {
            $p = proc_open([PHP_BINARY, $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $entorno);
            $this->assertIsResource($p);
            $procesos[] = [$p, $pipes];
        }
        usleep(2_500_000);   // todos arrancados y esperando la señal
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

    private function fecha(int $minutos): string
    {
        return now('America/Bogota')->addDay()->setTime(8, 0)->addMinutes($minutos)->format('Y-m-d H:i');
    }

    public function test_seis_administradores_programando_la_misma_base_a_la_vez_dejan_una_sola_programacion(): void
    {
        $lote = $this->baseLista(3);
        $salidas = $this->enParalelo(array_map(fn (int $i) => ['programar', (string) $lote->id, $this->fecha($i * 5)], range(0, 5)));

        $ganadores = array_values(array_filter($salidas, fn ($s) => $s['ok']));
        $this->assertCount(1, $ganadores, json_encode($salidas));
        foreach (array_filter($salidas, fn ($s) => ! $s['ok']) as $s) {
            $this->assertSame('YA_EXISTE_ACTIVA', $s['codigo']);
        }
        $this->assertSame(1, DB::table('cf_emisiones_programadas')->where('lote_id', $lote->id)->count());
        $this->assertSame($ganadores[0]['id'], DB::table('cf_emisiones_programadas')->where('lote_activa', $lote->id)->value('id'));
    }

    public function test_tres_ejecutores_a_la_vez_sobre_la_misma_programacion_vencida_emiten_una_sola_vez(): void
    {
        $lote = $this->baseLista(12);
        $p = app(ServicioProgramacion::class)->programar($lote, $this->fecha(0), Usuario::where('correo_principal', 'erik@fycconsultores.com')->firstOrFail());
        // Ya es la hora (dentro de la tolerancia).
        DB::table('cf_emisiones_programadas')->where('id', $p->id)->update(['programada_para' => now('America/Bogota')->subMinute()->format('Y-m-d H:i:s')]);

        $salidas = $this->enParalelo(array_fill(0, 3, ['ejecutor', (string) $lote->id, '', '0']));

        $this->assertSame([true, true, true], array_column($salidas, 'ok'), json_encode($salidas, JSON_UNESCAPED_UNICODE));
        $lineas = array_merge(...array_map(fn ($s) => $s['lineas'], $salidas));
        $this->assertCount(1, array_filter($lineas, fn ($l) => str_contains($l, "#{$p->id}: completada")), json_encode($lineas, JSON_UNESCAPED_UNICODE));
        $this->assertSame('completada', DB::table('cf_emisiones_programadas')->where('id', $p->id)->value('estado'));
        $this->assertSame(1, (int) DB::table('cf_emisiones_programadas')->where('id', $p->id)->value('intentos'), 'un solo proceso la reservó');
        $this->assertSame(12, DB::table('cf_emisiones')->where('lote_id', $lote->id)->count());
        $this->assertSame(12, DB::table('cf_emisiones')->where('lote_id', $lote->id)->distinct()->count('participante_id'), 'nadie recibió dos certificados');
    }

    public function test_programar_y_emitir_ahora_a_la_vez_nunca_dejan_una_programacion_pendiente_con_emisiones_manuales(): void
    {
        // Cada ronda retrasa un poco más al que programa: así se dan los dos órdenes (y el cruce a mitad de camino); en TODOS debe cumplirse lo mismo.
        $vistos = [];
        foreach ([0, 150, 400, 1200] as $ronda => $retraso) {
            $lote = $this->baseLista(4);
            [$prog, $emit] = $this->enParalelo([['programar', (string) $lote->id, $this->fecha($ronda), (string) $retraso], ['emitir', (string) $lote->id, '', '0']]);

            $emisiones = DB::table('cf_emisiones')->where('lote_id', $lote->id)->count();
            $activa = DB::table('cf_emisiones_programadas')->where('lote_activa', $lote->id)->count();
            if ($prog['ok']) {
                // Ganó la programación: la emisión manual se rechazó por el candado y no salió nada.
                $this->assertSame([false, 'EMISION_PROGRAMADA_PENDIENTE', 0, 1], [$emit['ok'], $emit['codigo'] ?? null, $emisiones, $activa], "ronda {$ronda}");
                $vistos[] = "{$retraso} ms: ganó la programación";
            } else {
                // Ganó la emisión manual: la programación no se guardó (ya no hay pendientes, o la base cambió mientras se programaba).
                $this->assertTrue($emit['ok'], "ronda {$ronda}: ".json_encode([$prog, $emit]));
                $this->assertContains($prog['codigo'], ['SIN_PENDIENTES', 'BASE_CAMBIO_DURANTE_PROGRAMACION'], "ronda {$ronda}");
                $this->assertSame([4, 0], [$emisiones, $activa], "ronda {$ronda}");
                $vistos[] = "{$retraso} ms: ganó la emisión ({$prog['codigo']})";
            }
        }
        $this->assertCount(4, $vistos);
    }
}
