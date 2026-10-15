<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Migracion\ConexionStaging;
use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use PDO;
use ReflectionClassConstant;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;
use Tests\TestCase;

/**
 * Los comandos delta con DOS credenciales reales (MySQL/MariaDB): el destino moderno se escribe con un usuario (todos los privilegios
 * SOLO sobre la base moderna de prueba) y el staging, que vive en OTRA base, se lee con otro usuario que solo tiene SELECT sobre
 * esa base. Prueba el aislamiento real y que `database.default` no cambia. Solo con servidor LOCAL de pruebas y datos sintéticos:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/DeltaStagingUsuarioMysqlTest.php
 *
 * Crea y elimina sus propias bases (appfyc2025_delta_aut_test y appfyc2025_delta_aut_staging) y usuarios.
 */
class DeltaStagingUsuarioMysqlTest extends TestCase
{
    use FixturesStagingEv;

    private const MODERNA = 'appfyc2025_delta_aut_test';

    private const STAGING = 'appfyc2025_delta_aut_staging';

    private const U_MOD = 'cf_delta_mod_t';

    private const U_STG = 'cf_delta_stg_t';

    private string $passMod = '';

    private string $passStg = '';

    /** @var array<string,mixed> */
    private array $admin = [];

    private string $png = '';

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $this->admin = config('database.connections.mysql');
        if ($base === '' || ! preg_match('/_test$/', $base) || $base === ($this->admin['database'] ?? '')
            || ! in_array($this->admin['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }
        $driver = (string) getenv('TEST_MYSQL_DRIVER');
        $this->admin = [...$this->admin, 'driver' => $driver ?: ($this->admin['driver'] ?? 'mysql')];
        $this->passMod = bin2hex(random_bytes(12));
        $this->passStg = bin2hex(random_bytes(12));

        config(['database.connections.adm' => [...$this->admin, 'database' => null]]);
        DB::purge('adm');
        $this->limpiar();
        $a = DB::connection('adm');
        $a->statement('CREATE DATABASE `'.self::MODERNA.'`');
        $a->statement('CREATE DATABASE `'.self::STAGING.'`');
        $a->statement("CREATE USER '".self::U_MOD."'@'%' IDENTIFIED BY '{$this->passMod}'");
        $a->statement("CREATE USER '".self::U_STG."'@'%' IDENTIFIED BY '{$this->passStg}'");
        $a->statement('GRANT ALL PRIVILEGES ON `'.self::MODERNA.'`.* TO \''.self::U_MOD.'\'@\'%\'');
        $a->statement('GRANT SELECT ON `'.self::STAGING.'`.* TO \''.self::U_STG.'\'@\'%\'');

        // Moderna: esquema completo + staging (para migrar la base desde la misma base, como las demás pruebas).
        $this->usar('mod_adm', self::MODERNA);
        $migraciones = (new ReflectionClassConstant(MigracionDeltaMysqlTest::class, 'MIGRACIONES'))->getValue();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $migraciones, '--force' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
        $this->cargar('s1', null, $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();

        // Staging aparte: los dos snapshots (el base y el final) con el administrador.
        $this->usar('stg_adm', self::STAGING);
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
        $this->cargar('s1', null, $this->imagenes());
        $this->cargar('s2', $this->datosDelta(), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));

        // A partir de aquí el proceso usa el usuario de la base MODERNA (sin permiso alguno sobre el staging).
        config(['database.connections.mod_u' => [...$this->admin, 'database' => self::MODERNA, 'username' => self::U_MOD, 'password' => $this->passMod],
            'database.default' => 'mod_u']);
        DB::purge('mod_u');
    }

    protected function tearDown(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD);
        if ($this->admin !== []) {
            config(['database.default' => 'adm']);
            DB::purge('mod_u');
            DB::purge('staging_ev');
            $this->limpiar();
        }
        parent::tearDown();
    }

    private function usar(string $nombre, string $base): void
    {
        config(['database.connections.'.$nombre => [...$this->admin, 'database' => $base], 'database.default' => $nombre]);
        DB::purge($nombre);
    }

    private function limpiar(): void
    {
        $a = DB::connection('adm');
        foreach ([self::MODERNA, self::STAGING] as $b) {
            $this->assertMatchesRegularExpression('/^appfyc2025_delta_aut_(test|staging)$/', $b);
            $a->statement("DROP DATABASE IF EXISTS `{$b}`");
        }
        foreach ([self::U_MOD, self::U_STG] as $u) {
            $a->statement("DROP USER IF EXISTS '{$u}'@'%'");
        }
    }

    /** @return array<int,array<string,mixed>> */
    private function imagenes(array $extra = []): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'deltaaut_'.bin2hex(random_bytes(6));
        mkdir($dir);
        if ($this->png === '') {
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            imagepng($im);
            $this->png = (string) ob_get_clean();
        }
        file_put_contents($dir.'/ALFA 2024.png', $this->png);
        file_put_contents($dir.'/DELTA 2023', $this->png);
        file_put_contents($dir.'/ORFANA.png', $this->png.'o');
        file_put_contents($dir.'/GAMMA_.png', $this->png.'g');
        foreach ($extra as $n => $c) {
            file_put_contents($dir.'/'.$n, $c);
        }
        $r = (new EscanerImagenes)->escanear($dir);
        File::deleteDirectory($dir);

        return $r;
    }

    private function nuevaImagen(): string
    {
        $im = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** @return array<string,array<int,array<string,mixed>>> */
    private function datosDelta(): array
    {
        $d = $this->datos();
        $d['evento'][] = ['id' => 7, 'nombre' => 'Curso Eta 2026', 'imagen_certificado' => 'ETA 2026.png'];
        for ($i = 0; $i < 4; $i++) {
            $d['participante'][] = ['id' => 16 + $i, 'tipo_documento' => 'CC', 'documento' => (string) (3000001 + $i), 'nombre' => 'PERSONA '.$i, 'correo' => $i === 2 ? null : "p{$i}@example.test", 'id_evento' => 7, 'num_verificacion' => $i === 0 ? 777 : null];
        }
        $d['descargas'][] = ['id' => 6, 'fecha' => '2026-10-14 10:00:00', 'id_evento' => 1, 'id_participante' => 1];
        $d['encuesta'][] = [
            'id' => 4, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => 'Muy bien', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
            'id_evento' => 7, 'documento_participante' => '3000001', 'fecha' => '2026-10-14 12:00:00',
        ];

        return $d;
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return collect(['cf_eventos', 'cf_certificados_legado', 'cf_descargas', 'cf_migraciones_map', 'cf_encuestas_respuestas', 'cf_migraciones_corridas'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function pdo(string $usuario, string $pass, string $base): ?PDO
    {
        try {
            return new PDO(sprintf('mysql:host=%s;port=%s;dbname=%s', $this->admin['host'], $this->admin['port'] ?? 3306, $base), $usuario, $pass, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
        } catch (\Throwable) {
            return null;
        }
    }

    public function test_los_usuarios_estan_realmente_aislados(): void
    {
        // El usuario moderno no ve el staging; el de staging no ve la base moderna ni puede escribir en el staging.
        $this->assertNull($this->pdo(self::U_MOD, $this->passMod, self::STAGING));
        $this->assertNull($this->pdo(self::U_STG, $this->passStg, self::MODERNA));
        $s = $this->pdo(self::U_STG, $this->passStg, self::STAGING);
        $this->assertNotNull($s);
        $this->assertGreaterThan(0, (int) $s->query('SELECT COUNT(*) FROM stg_ev_participante')->fetchColumn());
        $this->expectException(\PDOException::class);
        $s->exec('DELETE FROM stg_ev_participante');
    }

    public function test_los_dos_comandos_delta_funcionan_con_usuarios_separados_y_el_default_no_cambia(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'='.$this->passStg);
        $defaultAntes = config('database.connections.mod_u');
        $antes = $this->conteos();
        $args = ['--staging-bd' => self::STAGING, '--staging-usuario' => self::U_STG];

        // Simulación (no escribe nada) con el usuario de staging.
        $codigo = Artisan::call('credential-flow:legado:delta', $args + ['--simular' => true]);
        $simulacion = Artisan::output();
        $this->assertSame(0, $codigo, $simulacion);
        $this->assertStringContainsString('Simulación', $simulacion);
        $this->assertSame($antes, $this->conteos());

        // Aplicación real: escribe en la moderna con el usuario moderno y LEE el staging con el otro.
        $codigo = Artisan::call('credential-flow:legado:delta', $args);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Delta completado', $salida);
        // Las encuestas delta exigen el delta de certificados ya aplicado: simulación y aplicación con el usuario de staging.
        $this->assertSame(0, Artisan::call('credential-flow:encuestas:delta', $args + ['--simular' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('credential-flow:encuestas:delta', $args), Artisan::output());
        $despues = $this->conteos();
        $this->assertSame(1, $despues['cf_eventos'] - $antes['cf_eventos']);
        $this->assertSame(4, $despues['cf_certificados_legado'] - $antes['cf_certificados_legado']);
        $this->assertSame(1, $despues['cf_encuestas_respuestas'] - $antes['cf_encuestas_respuestas']);
        $this->assertSame($defaultAntes, config('database.connections.mod_u'));
        $this->assertSame('mod_u', config('database.default'));
        $this->assertSame(self::U_MOD, config('database.connections.mod_u.username'));

        // Idempotencia intacta con las mismas credenciales separadas.
        $codigo = Artisan::call('credential-flow:legado:delta', $args);
        $repetida = Artisan::output();
        $this->assertSame(0, $codigo, $repetida);
        $this->assertStringContainsString('ya está incorporado', $repetida);
        $this->assertSame($despues, $this->conteos());
        $this->assertStringNotContainsString($this->passStg, $salida);
        $this->assertStringNotContainsString($this->passMod, $salida);
    }

    public function test_fase_11f_c52_la_simulacion_de_encuestas_valida_el_evento_planificado_antes_del_delta_real(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'='.$this->passStg);
        $args = ['--staging-bd' => self::STAGING, '--staging-usuario' => self::U_STG];
        $antes = $this->conteos();

        // 1. legado simula → 2. encuestas simula (el evento 7 aún NO existe en la moderna): GO y cero escrituras.
        $this->assertSame(0, Artisan::call('credential-flow:legado:delta', $args + ['--simular' => true]), Artisan::output());
        $codigo = Artisan::call('credential-flow:encuestas:delta', $args + ['--simular' => true]);
        $salida = Artisan::output();
        $this->assertSame(0, $codigo, $salida);
        $this->assertStringContainsString('Simulación', $salida);
        $this->assertSame($antes, $this->conteos());
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'evento')->where('origen_id', '7')->count());

        // El modo real conserva la regla estricta mientras el delta de certificados no esté aplicado.
        $this->assertSame(1, Artisan::call('credential-flow:encuestas:delta', $args));
        $this->assertStringContainsString('aún no está migrado', Artisan::output());
        $this->assertSame($antes, $this->conteos());

        // 3. legado real crea el evento → 4. encuestas real crea la respuesta → 5. repetición idempotente.
        $this->assertSame(0, Artisan::call('credential-flow:legado:delta', $args), Artisan::output());
        $this->assertSame(0, Artisan::call('credential-flow:encuestas:delta', $args), Artisan::output());
        $despues = $this->conteos();
        $this->assertSame(1, $despues['cf_eventos'] - $antes['cf_eventos']);
        $this->assertSame(1, $despues['cf_encuestas_respuestas'] - $antes['cf_encuestas_respuestas']);
        $this->assertSame(0, Artisan::call('credential-flow:encuestas:delta', $args), Artisan::output());
        $this->assertSame($despues, $this->conteos());
        $this->assertSame('mod_u', config('database.default'));
        $this->assertStringNotContainsString($this->passStg, $salida);
    }

    public function test_una_contrasena_equivocada_falla_sin_filtrarla_y_sin_escribir(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'=contrasena-equivocada-sintetica');
        $antes = $this->conteos();

        $codigo = Artisan::call('credential-flow:legado:delta', ['--staging-bd' => self::STAGING, '--staging-usuario' => self::U_STG, '--simular' => true]);
        $salida = Artisan::output();

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringNotContainsString('contrasena-equivocada-sintetica', $salida);
        $this->assertStringNotContainsString($this->passStg, $salida);
        $this->assertSame($antes, $this->conteos());
    }
}
