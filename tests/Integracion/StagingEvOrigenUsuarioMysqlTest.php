<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\StagingEv\OrigenConexion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;
use Tests\TestCase;

/**
 * Staging con DOS credenciales reales (MySQL/MariaDB): un usuario que SOLO lee la base de origen y otro que opera SOLO la de
 * destino. Prueba que el comando carga el staging completo y que ninguno de los dos alcanza la base del otro.
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/StagingEvOrigenUsuarioMysqlTest.php
 *
 * Requiere que la conexión «mysql» pueda crear bases y usuarios (servidor LOCAL de pruebas). Datos sintéticos.
 */
class StagingEvOrigenUsuarioMysqlTest extends TestCase
{
    use FixturesStagingEv;

    private const ORIGEN_BD = 'appfyc2025_origen_snapshot_t';

    private const U_ORIGEN = 'cf_u_origen_t';

    private const U_STAGING = 'cf_u_staging_t';

    private string $destino = '';

    private string $passOrigen = '';

    private string $passStaging = '';

    /** @var array<string,mixed> */
    private array $admin = [];

    protected function setUp(): void
    {
        parent::setUp();

        $this->destino = (string) getenv('TEST_MYSQL_DATABASE');
        $this->admin = config('database.connections.mysql');
        if ($this->destino === '' || ! preg_match('/_test$/', $this->destino) || $this->destino === ($this->admin['database'] ?? '')
            || ! in_array($this->admin['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }

        $this->passOrigen = bin2hex(random_bytes(12));
        $this->passStaging = bin2hex(random_bytes(12));

        config(['database.connections.adm' => [...$this->admin, 'database' => null]]);
        DB::purge('adm');
        $this->limpiar();
        $a = DB::connection('adm');
        $a->statement('CREATE DATABASE `'.self::ORIGEN_BD.'`');
        $a->statement('CREATE DATABASE IF NOT EXISTS `'.$this->destino.'`');
        $a->statement("CREATE USER '".self::U_ORIGEN."'@'%' IDENTIFIED BY '{$this->passOrigen}'");
        $a->statement("CREATE USER '".self::U_STAGING."'@'%' IDENTIFIED BY '{$this->passStaging}'");
        $a->statement('GRANT SELECT ON `'.self::ORIGEN_BD.'`.* TO \''.self::U_ORIGEN.'\'@\'%\'');
        $a->statement('GRANT ALL PRIVILEGES ON `'.$this->destino.'`.* TO \''.self::U_STAGING.'\'@\'%\'');

        // El origen: una restauración sintética (cada tabla con id y el resto de columnas como TEXT).
        foreach ($this->datos() as $tabla => $filas) {
            $columnas = collect($filas)->flatMap(fn ($f) => array_keys($f))->unique()->reject(fn ($c) => $c === 'id')->values();
            $ddl = 'CREATE TABLE `'.self::ORIGEN_BD."`.`{$tabla}` (`id` INT NOT NULL PRIMARY KEY".$columnas->map(fn ($c) => ", `{$c}` TEXT NULL")->implode('').')';
            $a->statement($ddl);
            foreach ($filas as $fila) {
                $a->table(self::ORIGEN_BD.'.'.$tabla)->insert($fila);
            }
        }

        // Destino: tablas de staging creadas con el usuario del destino (no con el administrador).
        config([
            'database.connections.stg_u' => [...$this->admin, 'database' => $this->destino, 'username' => self::U_STAGING, 'password' => $this->passStaging],
            'database.default' => 'stg_u',
        ]);
        DB::purge('stg_u');
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
    }

    protected function tearDown(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD);
        if ($this->admin !== [] && $this->destino !== '') {
            config(['database.default' => 'adm']);
            $this->limpiar();
        }
        parent::tearDown();
    }

    private function limpiar(): void
    {
        // Red de seguridad: solo elimina en una base de pruebas.
        $this->assertMatchesRegularExpression('/_test$/', $this->destino);
        $a = DB::connection('adm');
        $a->statement('DROP DATABASE IF EXISTS `'.self::ORIGEN_BD.'`');
        foreach ([self::U_ORIGEN, self::U_STAGING] as $u) {
            $a->statement("DROP USER IF EXISTS '{$u}'@'%'");
        }
        $a->statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['stg_ev_historial', 'stg_ev_participante_correos', 'stg_ev_imagenes', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
            'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots', 'migrations'] as $t) {
            $a->statement('DROP TABLE IF EXISTS `'.$this->destino."`.`{$t}`");
        }
        $a->statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function test_carga_con_dos_usuarios_y_aislamiento_entre_origen_y_destino(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD.'='.$this->passOrigen);
        $dump = tempnam(sys_get_temp_dir(), 'stgdump');
        file_put_contents($dump, '-- dump sintetico');

        try {
            $codigo = Artisan::call('credential-flow:staging-ev:cargar', ['--dump' => $dump, '--origen-bd' => self::ORIGEN_BD, '--origen-usuario' => self::U_ORIGEN]);
            $salida = Artisan::output();
        } finally {
            unlink($dump);
        }

        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('cargado', $salida);
        $this->assertStringNotContainsString($this->passOrigen, $salida);
        $this->assertStringNotContainsString($this->passStaging, $salida);
        $this->assertSame(1, DB::table('stg_ev_snapshots')->count());
        $this->assertSame(count($this->datos()['participante']), DB::table('stg_ev_participante')->count());

        // Aislamiento: el usuario de origen solo LEE su base; el de staging no alcanza el origen.
        config(['database.connections.o_u' => [...$this->admin, 'database' => self::ORIGEN_BD, 'username' => self::U_ORIGEN, 'password' => $this->passOrigen]]);
        DB::purge('o_u');
        $this->assertGreaterThan(0, DB::connection('o_u')->table('evento')->count());
        foreach ([
            fn () => DB::connection('o_u')->table('evento')->insert(['id' => 999, 'nombre' => 'x']),
            fn () => DB::connection('o_u')->statement('DELETE FROM evento'),
            fn () => DB::connection('o_u')->select('SELECT 1 FROM `'.$this->destino.'`.stg_ev_snapshots LIMIT 1'),
        ] as $intento) {
            try {
                $intento();
                $this->fail('El usuario de origen no debía poder hacerlo');
            } catch (\Throwable $e) {
                $this->assertStringContainsString('denied', strtolower($e->getMessage()));
            }
        }
        try {
            DB::connection('stg_u')->select('SELECT 1 FROM `'.self::ORIGEN_BD.'`.evento LIMIT 1');
            $this->fail('El usuario de staging no debía leer el origen');
        } catch (\Throwable $e) {
            $this->assertStringContainsString('denied', strtolower($e->getMessage()));
        }
    }
}
