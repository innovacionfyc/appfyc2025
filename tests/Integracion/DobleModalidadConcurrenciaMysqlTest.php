<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Database\Seeders\AreaFormacionSeeder;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\EstadosSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\TipoDocumentosSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Concurrencia REAL (MySQL/MariaDB, procesos PHP en paralelo) de la autorización de doble modalidad por evento. Requiere TEST_MYSQL_DATABASE con una base local
 * *_test (se BORRA y se recrea); sin ella, se omite. Datos 100 % sintéticos.
 *
 *  - Varios super-admin autorizando el mismo evento a la vez dejan UNA sola autorización vigente.
 *  - Revocar un evento mientras otro proceso le mueve una base con participaciones dobles nunca deja esa base en un evento sin autorización: o gana el
 *    traslado (y la revocación se bloquea por las participaciones dobles) o gana la revocación (y el traslado se rechaza).
 */
class DobleModalidadConcurrenciaMysqlTest extends TestCase
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
            'credential_flow.doble_modalidad.eventos' => null,
        ]);
        DB::purge(self::CONEXION);
        // Red de seguridad: lo que sigue BORRA tablas; solo en una base de pruebas.
        $this->assertMatchesRegularExpression('/_(dev|test|testing)$/', (string) DB::connection()->getDatabaseName());
        $this->assertNotSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        foreach ([EstadosSeeder::class, TipoDocumentosSeeder::class, AreaFormacionSeeder::class, EquipoSeeder::class, RolSeeder::class, UsuarioSeeder::class] as $seeder) {
            $this->assertSame(0, Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]));
        }
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'doble_modalidad_mysql_'.bin2hex(random_bytes(6));
        foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $d) {
            mkdir($this->dir.DIRECTORY_SEPARATOR.$d, 0777, true);
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function evento(string $nombre): EventoCertificacion
    {
        return EventoCertificacion::create(['nombre' => $nombre, 'nombre_normalizado' => NombreConservador::normalizar($nombre), 'anio' => 2026,
            'fecha_texto' => '10 DE NOVIEMBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
    }

    /** Base del evento con una persona en dos modalidades (participación doble real). */
    private function baseDoble(EventoCertificacion $evento): Lote
    {
        $p = Plantilla::create(['nombre' => 'Plantilla concurrencia '.uniqid(), 'descripcion' => 'Prueba', 'archivo_pdf' => 'pendiente', 'nombre_archivo_original' => 'base.pdf', 'hash_sha256' => str_repeat('0', 64)]);
        $lote = Lote::create(['plantilla_id' => $p->id, 'nombre' => 'Base concurrencia '.uniqid(), 'evento_id' => $evento->id,
            'datos_comunes' => ['evento' => 'TALLER DE CONCURRENCIA', 'fecha' => '10 de noviembre de 2026', 'intensidad_horaria' => '8 horas']]);
        foreach (['Presencial', 'Virtual'] as $m) {
            $lote->participantes()->create(['nombre_completo' => 'PERSONA FICTICIA DOBLE', 'documento' => 'C.C. 52.000.001', 'documento_clave' => Texto::claveDocumento('C.C. 52.000.001'), 'modalidad' => $m]);
        }

        return $lote;
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
        [, \$modo, \$a, \$b, \$retraso] = \$argv + [null, null, null, null, '0'];
        \$super = App\\Models\\Usuario::with('perfilOrganizador.rol')->where('correo_principal', 'jhoann@fycconsultores.com')->firstOrFail();
        while (! file_exists({$go})) { usleep(2000); }
        usleep(1000 * (int) \$retraso);
        try {
            \$aut = app(App\\Support\\CredentialFlow\\Eventos\\AutorizacionesDobleModalidad::class);
            if (\$modo === 'autorizar') {
                \$r = \$aut->autorizar(App\\Models\\CredentialFlow\\EventoCertificacion::findOrFail((int) \$a), \$super, 'Autorización concurrente sintética');
                echo json_encode(['ok' => true, 'id' => \$r['autorizacion_id']]);
            } elseif (\$modo === 'revocar') {
                \$aut->revocar(App\\Models\\CredentialFlow\\EventoCertificacion::findOrFail((int) \$a), \$super, 'Revocación concurrente sintética');
                echo json_encode(['ok' => true]);
            } else {
                Illuminate\\Support\\Facades\\Auth::setUser(\$super);
                app(App\\Support\\CredentialFlow\\Eventos\\ServicioEventos::class)->asociar(App\\Models\\CredentialFlow\\Lote::findOrFail((int) \$a), App\\Models\\CredentialFlow\\EventoCertificacion::findOrFail((int) \$b));
                echo json_encode(['ok' => true]);
            }
        } catch (App\\Support\\CredentialFlow\\Eventos\\AutorizacionDobleModalidadException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e->codigo, 'bloqueos' => array_column(\$e->bloqueos, 'code')]);
        } catch (InvalidArgumentException \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'RECHAZADO']);
        } catch (Throwable \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 400)], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /**
     * @param  list<list<string>>  $argumentos
     * @return list<array<string,mixed>>
     */
    private function enParalelo(array $argumentos): array
    {
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->dir.DIRECTORY_SEPARATOR.'storage',
        ]);
        unset($entorno['CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS']);
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

    public function test_cinco_super_admin_autorizando_a_la_vez_dejan_una_sola_autorizacion_vigente(): void
    {
        $evento = $this->evento('Evento sintético de concurrencia');
        $salidas = $this->enParalelo(array_map(fn () => ['autorizar', (string) $evento->id, '', '0'], range(1, 5)));

        $this->assertCount(1, array_filter($salidas, fn ($s) => $s['ok']), json_encode($salidas));
        foreach (array_filter($salidas, fn ($s) => ! $s['ok']) as $s) {
            $this->assertSame('YA_AUTORIZADO', $s['codigo'], json_encode($s));
        }
        $this->assertSame(1, AutorizacionDobleModalidad::where('evento_id', $evento->id)->count());
        $this->assertTrue(DobleModalidad::autorizado($evento->id));
    }

    public function test_revocar_y_mover_una_base_doble_a_la_vez_nunca_la_deja_en_un_evento_sin_autorizacion(): void
    {
        $super = Usuario::with('perfilOrganizador.rol')->where('correo_principal', 'jhoann@fycconsultores.com')->firstOrFail();
        $aut = app(AutorizacionesDobleModalidad::class);
        $resultados = [];
        // [retraso del traslado, retraso de la revocación] en ms: los dos órdenes y el arranque simultáneo.
        foreach ([[0, 0], [0, 30], [0, 0], [120, 0], [300, 0], [0, 0]] as $ronda => [$retrasoMover, $retrasoRevocar]) {
            $origen = $this->evento("Evento origen {$ronda}");
            $destino = $this->evento("Evento destino {$ronda}");
            $aut->autorizar($origen, $super, 'Autorización sintética del origen');
            $aut->autorizar($destino, $super, 'Autorización sintética del destino');
            $lote = $this->baseDoble($origen);

            [$mover, $revocar] = $this->enParalelo([['asociar', (string) $lote->id, (string) $destino->id, (string) $retrasoMover], ['revocar', (string) $destino->id, '', (string) $retrasoRevocar]]);
            $lote->refresh();
            $destinoAutorizado = DobleModalidad::autorizado($destino->id);
            $resultados[] = [$mover['ok'], $revocar['ok']];

            // Invariante: la base con participaciones dobles solo está en un evento autorizado.
            $this->assertTrue(DobleModalidad::autorizado((int) $lote->evento_id), "ronda {$ronda}: ".json_encode([$mover, $revocar]));
            // Exactamente uno de los dos gana, y el que pierde lo hace por la razón esperada.
            $this->assertNotSame($mover['ok'], $revocar['ok'], "ronda {$ronda}: ".json_encode([$mover, $revocar]));
            if ($mover['ok']) {
                $this->assertSame($destino->id, (int) $lote->evento_id);
                $this->assertTrue($destinoAutorizado);
                $this->assertContains('PARTICIPACIONES_DOBLES', $revocar['bloqueos'] ?? [], json_encode($revocar));
            } else {
                $this->assertSame('RECHAZADO', $mover['codigo'], json_encode($mover));
                $this->assertSame($origen->id, (int) $lote->evento_id);
                $this->assertFalse($destinoAutorizado);
            }
        }
        fwrite(STDERR, PHP_EOL.'rondas [traslado, revocación]: '.json_encode($resultados).PHP_EOL);
        $this->assertContains([true, false], $resultados, 'alguna ronda la gana el traslado');
        $this->assertContains([false, true], $resultados, 'alguna ronda la gana la revocación');
    }
}
