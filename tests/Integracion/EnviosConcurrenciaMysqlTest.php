<?php

namespace Tests\Integracion;

use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Idempotencia del envío (Fase 9) con CONCURRENCIA REAL: varios procesos PHP independientes intentan enviar el MISMO desafío OTP a la vez
 * contra MySQL real. Debe quedar un único envío lógico, un único intento y un único correo entregado al transporte (que aquí es el
 * transporte `array` de Laravel: nunca SMTP real ni red). Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/EnviosConcurrenciaMysqlTest.php
 */
class EnviosConcurrenciaMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private const MIGRACIONES = [
        'database/migrations/2026_10_09_100000_cf_accesos_otp_table.php',
        'database/migrations/2026_10_09_100200_add_grupo_hash_to_cf_accesos_otp_table.php',
        'database/migrations/2026_10_11_100000_cf_envios_tables.php',
        'database/migrations/2026_10_11_100100_ajusta_cf_accesos_otp_para_envios.php',
    ];

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
            'database.connections.'.self::CONEXION => [...$config, 'driver' => (string) (getenv('TEST_MYSQL_DRIVER') ?: ($config['driver'] ?? 'mysql')), 'database' => $this->base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);

        $this->limpiar();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRACIONES, '--force' => true]), Artisan::output());
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'envios_mysql_'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        if (DB::getDefaultConnection() === self::CONEXION) {
            $this->limpiar();
        }
        parent::tearDown();
    }

    private function limpiar(): void
    {
        // Red de seguridad: este método ELIMINA tablas; solo en una base de pruebas.
        $this->assertMatchesRegularExpression('/_(dev|test|testing)$/', (string) DB::connection()->getDatabaseName());
        $this->assertNotSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['cf_envios_intentos', 'cf_envios', 'cf_accesos_otp', 'migrations'] as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
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
        \$otp = (int) \$argv[1];
        while (! file_exists({$go})) { usleep(2000); }
        \$envio = app(App\\Support\\CredentialFlow\\Envios\\ServicioEnvios::class)->enviarOtp(\$otp, 'ana.uno@example.test', '482915');
        \$mensajes = app('mail.manager')->mailer('array')->getSymfonyTransport()->messages()->count();
        echo json_encode(['estado' => \$envio?->estado, 'entregados_al_transporte' => \$mensajes]);
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    public function test_varios_procesos_reales_enviando_el_mismo_desafio_producen_un_unico_envio_y_un_unico_correo(): void
    {
        $ahora = now();
        $otp = DB::table('cf_accesos_otp')->insertGetId([
            'documento_hash' => str_repeat('a', 64), 'correo_hash' => str_repeat('b', 64), 'otp_hash' => 'hash-de-prueba', 'expires_at' => $ahora->copy()->addMinutes(10),
            'intentos' => 0, 'solicitado_at' => $ahora, 'enviado_at' => null, 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array']);

        $procesos = [];
        foreach (range(1, 5) as $_) {
            $p = proc_open([PHP_BINARY, $script, (string) $otp], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $entorno);
            $this->assertIsResource($p);
            $procesos[] = [$p, $pipes];
        }
        usleep(1_200_000);   // todos arrancados y esperando la señal
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'go', '1');

        $salidas = [];
        foreach ($procesos as [$p, $pipes]) {
            $salida = stream_get_contents($pipes[1]);
            $err = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            proc_close($p);
            $json = json_decode((string) $salida, true);
            $this->assertIsArray($json, "El trabajador no devolvió JSON. stdout={$salida} stderr={$err}");
            $salidas[] = $json;
        }

        $this->assertSame(1, DB::table('cf_envios')->count(), 'Un único envío lógico');
        $this->assertSame(1, DB::table('cf_envios_intentos')->count(), 'Un único intento');
        $this->assertSame(1, array_sum(array_column($salidas, 'entregados_al_transporte')), 'Un único correo entregado al transporte entre todos los procesos');
        $envio = DB::table('cf_envios')->first();
        $this->assertSame(['aceptado_por_transporte', 1, 'otp_acceso:otp:'.$otp], [$envio->estado, (int) $envio->intentos, $envio->clave_idempotencia]);
        $this->assertNotNull(DB::table('cf_accesos_otp')->where('id', $otp)->value('enviado_at'));
    }
}
