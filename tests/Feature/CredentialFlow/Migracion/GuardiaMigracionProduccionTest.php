<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Support\CredentialFlow\Migracion\GuardiaMigracion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 11E-A1: excepción de producción de GuardiaMigracion. No requiere BD: las conexiones se describen por configuración
 * y la guardia nunca consulta (solo lee driver, base y host). El comportamiento previo (bases `_test`, `migracion_legado`,
 * `rehearsal`, host local, origen staging) queda cubierto además por MigracionHistoricaTest.
 */
class GuardiaMigracionProduccionTest extends TestCase
{
    private const VAR = GuardiaMigracion::VARIABLE_PRODUCCION;

    protected function setUp(): void
    {
        parent::setUp();
        GuardiaMigracion::olvidarRegistroDeAutorizaciones();
    }

    protected function tearDown(): void
    {
        putenv(self::VAR); // elimina la variable de proceso
        GuardiaMigracion::olvidarRegistroDeAutorizaciones();
        parent::tearDown();
    }

    private function conexion(string $base, array $extra = []): string
    {
        $mysql = config('database.connections.mysql');
        config(['database.connections.guardia' => array_merge($mysql, ['database' => $base, 'host' => '127.0.0.1'], $extra)]);
        DB::purge('guardia');

        return 'guardia';
    }

    private function bloqueada(string $base, ?string $opcion, ?string $variable, string $fragmento = 'no se migra'): void
    {
        $variable === null ? putenv(self::VAR) : putenv(self::VAR.'='.$variable);
        try {
            GuardiaMigracion::exigirDestinoSeguro($this->conexion($base), $opcion, 'credential-flow:legado:migrar');
            $this->fail("Debía bloquear {$base} (opción ".var_export($opcion, true).', variable '.var_export($variable, true).')');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString($fragmento, $e->getMessage());
        }
    }

    // 1. producción sin opción
    public function test_produccion_sin_opcion_queda_bloqueada(): void
    {
        $this->bloqueada('appfyc', null, 'appfyc');
        $this->bloqueada('appfyc', '', 'appfyc');
    }

    // 2. con opción pero sin variable
    public function test_produccion_con_opcion_pero_sin_variable_queda_bloqueada(): void
    {
        $this->bloqueada('appfyc', 'appfyc', null);
    }

    // 3. con variable pero sin opción
    public function test_produccion_con_variable_pero_sin_opcion_queda_bloqueada(): void
    {
        $this->bloqueada('appfyc', null, 'appfyc');
    }

    // 4. opción incorrecta (parcial, comodín, genérica, otro nombre, mayúsculas)
    #[DataProvider('valoresIncorrectos')]
    public function test_opcion_incorrecta_queda_bloqueada(string $valor): void
    {
        $this->bloqueada('appfyc', $valor, 'appfyc');
    }

    // 5. variable incorrecta
    #[DataProvider('valoresIncorrectos')]
    public function test_variable_incorrecta_queda_bloqueada(string $valor): void
    {
        $this->bloqueada('appfyc', 'appfyc', $valor);
    }

    /** @return array<string,array{0:string}> */
    public static function valoresIncorrectos(): array
    {
        return [
            'uno' => ['1'], 'true' => ['true'], 'si' => ['yes'], 'comodin' => ['appf*'], 'parcial' => ['app'], 'otra bd' => ['appfyc2025'],
            'mayusculas' => ['APPFYC'], 'espacio' => ['appfyc '], 'prefijo' => ['admin_appfyc'],
        ];
    }

    // 6. destino distinto de appfyc (aunque ambas confirmaciones nombren otra base)
    #[DataProvider('otrasBases')]
    public function test_destino_distinto_de_appfyc_queda_bloqueado(string $base): void
    {
        $this->bloqueada($base, $base, $base);
        $this->bloqueada($base, 'appfyc', 'appfyc');
    }

    /** @return array<string,array{0:string}> */
    public static function otrasBases(): array
    {
        return ['dev' => ['appfyc2025'], 'staging' => ['appfyc2025_staging_ev'], 'legacy' => ['sala_certificacion'], 'otra prod' => ['inscritos_db'], 'prefijada' => ['admin_appfyc'], 'auxiliar' => ['admin_cf_staging_ev_11e']];
    }

    // 7. portal ON: siempre bloqueado
    public function test_con_el_portal_encendido_siempre_queda_bloqueada(): void
    {
        config(['credential_flow.portal_enabled' => true]);
        $this->bloqueada('appfyc', 'appfyc', 'appfyc');
    }

    // 8. appfyc + opción exacta + variable exacta + portal OFF: PERMITIDA (y se registra sin secretos)
    public function test_appfyc_con_ambas_confirmaciones_y_portal_apagado_queda_permitida(): void
    {
        config(['credential_flow.portal_enabled' => false]);
        putenv(self::VAR.'=appfyc');
        Log::spy();

        GuardiaMigracion::exigirDestinoSeguro($this->conexion('appfyc'), 'appfyc', 'credential-flow:legado:migrar');
        $this->addToAssertionCount(1);

        Log::shouldHaveReceived('warning')->once()->withArgs(function (string $mensaje, array $ctx) {
            return $mensaje === 'MIGRACION_PRODUCCION_AUTORIZADA'
                && $ctx['base'] === 'appfyc' && $ctx['comando'] === 'credential-flow:legado:migrar' && isset($ctx['timestamp'])
                && array_keys($ctx) === ['base', 'comando', 'timestamp'];
        });
    }

    public function test_el_host_local_sigue_siendo_obligatorio_aun_con_la_doble_confirmacion(): void
    {
        putenv(self::VAR.'=appfyc');
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no es local');
        GuardiaMigracion::exigirDestinoSeguro($this->conexion('appfyc', ['host' => 'db.ejemplo.com']), 'appfyc', 'x');
    }

    public function test_si_la_variable_esta_en_dotenv_la_confirmacion_efimera_se_rechaza(): void
    {
        $original = base_path();
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'guardia_env_'.bin2hex(random_bytes(5));
        mkdir($dir);
        file_put_contents($dir.DIRECTORY_SEPARATOR.'.env', "APP_ENV=production\n".self::VAR."=appfyc\n");
        app()->setBasePath($dir);
        try {
            $this->bloqueada('appfyc', 'appfyc', 'appfyc');
        } finally {
            app()->setBasePath($original);
            @unlink($dir.DIRECTORY_SEPARATOR.'.env');
            @rmdir($dir);
        }
    }

    public function test_el_mensaje_de_bloqueo_no_contiene_secretos_y_explica_la_doble_confirmacion(): void
    {
        try {
            GuardiaMigracion::exigirDestinoSeguro($this->conexion('appfyc', ['password' => 'super-secreto', 'username' => 'usuario-x']), null, 'x');
            $this->fail('Debía bloquear');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('--confirmo-produccion=appfyc', $e->getMessage());
            $this->assertStringContainsString(self::VAR.'=appfyc', $e->getMessage());
            $this->assertStringNotContainsString('super-secreto', $e->getMessage());
            $this->assertStringNotContainsString('usuario-x', $e->getMessage());
        }
    }

    // 9. destinos locales existentes siguen permitidos (sin confirmaciones)
    #[DataProvider('destinosLocalesPermitidos')]
    public function test_los_destinos_locales_existentes_siguen_permitidos(string $base): void
    {
        putenv(self::VAR); // sin variable
        GuardiaMigracion::exigirDestinoSeguro($this->conexion($base));
        $this->addToAssertionCount(1);
    }

    /** @return array<string,array{0:string}> */
    public static function destinosLocalesPermitidos(): array
    {
        return ['migracion_legado' => ['appfyc2025_migracion_legado_test'], 'test' => ['appfyc2025_fase1_test'], 'testing' => ['x_testing'], 'rehearsal' => ['appfyc2025_rehearsal_11b']];
    }

    // 10. destinos peligrosos existentes siguen bloqueados (también con confirmaciones que no corresponden)
    #[DataProvider('destinosPeligrosos')]
    public function test_los_destinos_peligrosos_existentes_siguen_bloqueados(string $base): void
    {
        $this->bloqueada($base, null, null, 'no es una base local de pruebas');
        $this->bloqueada($base, 'appfyc', 'appfyc', 'no es una base local de pruebas');
    }

    /** @return array<string,array{0:string}> */
    public static function destinosPeligrosos(): array
    {
        return ['dev' => ['appfyc2025'], 'staging' => ['appfyc2025_staging_ev'], 'produccion' => ['produccion'], 'legacy' => ['sala_certificacion']];
    }

    public function test_el_origen_staging_conserva_su_regla(): void
    {
        putenv(self::VAR.'=appfyc');
        GuardiaMigracion::exigirOrigenStaging($this->conexion('admin_cf_staging_ev_11e'), 'appfyc', 'x');
        $this->addToAssertionCount(1);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('no es una base local de staging');
        GuardiaMigracion::exigirOrigenStaging($this->conexion('appfyc'), 'appfyc', 'x');
    }

    // 11 y 12. Los dos comandos aplican la misma protección (conexión por defecto simulada como producción: nunca se conecta)
    #[DataProvider('comandos')]
    public function test_los_comandos_bloquean_produccion_sin_la_doble_confirmacion(string $comando): void
    {
        $this->produccionPorDefecto();
        putenv(self::VAR);
        $this->artisan($comando)->expectsOutputToContain('no es una base local de pruebas')->assertExitCode(1);

        $this->artisan($comando, ['--confirmo-produccion' => 'appfyc'])->expectsOutputToContain('no es una base local de pruebas')->assertExitCode(1);

        putenv(self::VAR.'=appfyc');
        $this->artisan($comando)->expectsOutputToContain('no es una base local de pruebas')->assertExitCode(1);

        $this->artisan($comando, ['--confirmo-produccion' => 'APPFYC'])->expectsOutputToContain('no es una base local de pruebas')->assertExitCode(1);
    }

    #[DataProvider('comandos')]
    public function test_los_comandos_dejan_pasar_la_guardia_solo_con_la_doble_confirmacion(string $comando): void
    {
        $this->produccionPorDefecto();
        putenv(self::VAR.'=appfyc');
        Log::spy();

        // La guardia pasa; el comando falla después (no hay MySQL en la prueba), pero nunca por la guardia.
        $this->artisan($comando, ['--confirmo-produccion' => 'appfyc'])->doesntExpectOutputToContain('no es una base local de pruebas');

        Log::shouldHaveReceived('warning')->withArgs(fn (string $m, array $c) => $m === 'MIGRACION_PRODUCCION_AUTORIZADA' && $c['comando'] === $comando)->once();
    }

    /** @return array<string,array{0:string}> */
    public static function comandos(): array
    {
        return [
            'legado:migrar' => ['credential-flow:legado:migrar'], 'encuestas:migrar' => ['credential-flow:encuestas:migrar'],
            'legado:delta' => ['credential-flow:legado:delta'], 'encuestas:delta' => ['credential-flow:encuestas:delta'],
        ];
    }

    public function test_la_excepcion_no_alcanza_a_otros_comandos_de_credential_flow(): void
    {
        foreach (array_keys(Artisan::all()) as $nombre) {
            if (! str_starts_with($nombre, 'credential-flow:')) {
                continue;
            }
            $tiene = Artisan::all()[$nombre]->getDefinition()->hasOption(GuardiaMigracion::OPCION_PRODUCCION);
            $this->assertSame(
                in_array($nombre, GuardiaMigracion::COMANDOS_PRODUCCION, true),
                $tiene,
                "El comando {$nombre} no debe declarar --".GuardiaMigracion::OPCION_PRODUCCION.' salvo los de la lista cerrada (migrar y delta)'
            );
        }
    }

    private function produccionPorDefecto(): void
    {
        config([
            'database.connections.prod_sim' => array_merge(config('database.connections.mysql'), ['database' => 'appfyc', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'x', 'password' => 'x']),
            'database.default' => 'prod_sim',
            'credential_flow.portal_enabled' => false,
        ]);
        DB::purge('prod_sim');
    }
}
