<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Support\CredentialFlow\Migracion\GuardiaMigracion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 11F-B: los comandos DELTA (y sus rollbacks) usan la MISMA excepción de producción que legado:migrar / encuestas:migrar
 * (doble confirmación, CLI, portal OFF, base exacta «appfyc») y ningún otro comando la obtiene. Sin BD: solo configuración.
 */
class GuardiaMigracionDeltaTest extends TestCase
{
    private const VAR = GuardiaMigracion::VARIABLE_PRODUCCION;

    protected function setUp(): void
    {
        parent::setUp();
        GuardiaMigracion::olvidarRegistroDeAutorizaciones();
    }

    protected function tearDown(): void
    {
        putenv(self::VAR);
        GuardiaMigracion::olvidarRegistroDeAutorizaciones();
        parent::tearDown();
    }

    private function produccion(): string
    {
        config(['database.connections.guardia' => array_merge(config('database.connections.mysql'), ['database' => 'appfyc', 'host' => '127.0.0.1'])]);
        DB::purge('guardia');

        return 'guardia';
    }

    /** @return array<string,array{string}> */
    public static function comandosDelta(): array
    {
        return [
            'legado:delta' => ['credential-flow:legado:delta'],
            'encuestas:delta' => ['credential-flow:encuestas:delta'],
            'legado:delta-rollback' => ['credential-flow:legado:delta-rollback'],
            'encuestas:delta-rollback' => ['credential-flow:encuestas:delta-rollback'],
        ];
    }

    #[DataProvider('comandosDelta')]
    public function test_delta_sin_doble_confirmacion_queda_bloqueado_en_produccion(string $comando): void
    {
        putenv(self::VAR.'=appfyc');
        foreach ([null, '', 'otra'] as $opcion) {
            try {
                GuardiaMigracion::exigirDestinoSeguro($this->produccion(), $opcion, $comando);
                $this->fail('Debía bloquear con opción '.var_export($opcion, true));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no se migra', $e->getMessage());
            }
        }
        putenv(self::VAR);
        $this->expectException(RuntimeException::class);
        GuardiaMigracion::exigirDestinoSeguro($this->produccion(), 'appfyc', $comando);
    }

    #[DataProvider('comandosDelta')]
    public function test_delta_con_doble_confirmacion_y_portal_apagado_queda_permitido(string $comando): void
    {
        putenv(self::VAR.'=appfyc');
        config(['credential_flow.portal_enabled' => false]);

        GuardiaMigracion::exigirDestinoSeguro($this->produccion(), 'appfyc', $comando);

        $this->addToAssertionCount(1);
    }

    #[DataProvider('comandosDelta')]
    public function test_delta_con_portal_encendido_queda_bloqueado(string $comando): void
    {
        putenv(self::VAR.'=appfyc');
        config(['credential_flow.portal_enabled' => true]);
        $this->expectException(RuntimeException::class);

        GuardiaMigracion::exigirDestinoSeguro($this->produccion(), 'appfyc', $comando);
    }

    public function test_un_comando_fuera_de_la_lista_no_obtiene_la_excepcion_aunque_confirme(): void
    {
        putenv(self::VAR.'=appfyc');
        config(['credential_flow.portal_enabled' => false]);
        foreach (['credential-flow:conciliaciones:detectar', 'credential-flow:legado:rollback-corrida', 'credential-flow:otro', ''] as $comando) {
            try {
                GuardiaMigracion::exigirDestinoSeguro($this->produccion(), 'appfyc', $comando);
                $this->fail("«{$comando}» no debía obtener la excepción de producción");
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('no se migra', $e->getMessage());
            }
        }
    }

    /** @return array<string,array{string,string}> */
    public static function rollbacks(): array
    {
        return [
            'legado' => ['credential-flow:legado:delta-rollback', 'revertirLegado'],
            'encuestas' => ['credential-flow:encuestas:delta-rollback', 'revertirEncuestas'],
        ];
    }

    #[DataProvider('rollbacks')]
    public function test_los_rollbacks_delta_solo_pasan_la_guardia_con_la_doble_confirmacion(string $comando, string $metodo): void
    {
        config([
            'database.connections.prod_sim' => array_merge(config('database.connections.mysql'), ['database' => 'appfyc', 'host' => '127.0.0.1', 'port' => '1', 'username' => 'x', 'password' => 'x']),
            'database.default' => 'prod_sim',
            'credential_flow.portal_enabled' => false,
        ]);
        DB::purge('prod_sim');
        putenv(self::VAR.'=appfyc');

        // Sin la opción: la guardia lo bloquea antes de consultar nada.
        $this->artisan($comando, ['id' => 1, '--confirmar' => true])->expectsOutputToContain('(RuntimeException)')->assertExitCode(1);
        // Con la doble confirmación pasa la guardia y falla después (no hay MySQL): ya no es la guardia.
        $this->artisan($comando, ['id' => 1, '--confirmar' => true, '--confirmo-produccion' => 'appfyc'])->doesntExpectOutputToContain('(RuntimeException)')->assertExitCode(1);
        // Sin --confirmar no hace nada.
        $this->artisan($comando, ['id' => 1])->assertExitCode(1);
    }

    public function test_la_opcion_de_confirmacion_solo_existe_en_los_comandos_de_migracion_y_delta(): void
    {
        $conOpcion = [];
        foreach (Artisan::all() as $nombre => $cmd) {
            if (str_starts_with($nombre, 'credential-flow:') && $cmd->getDefinition()->hasOption('confirmo-produccion')) {
                $conOpcion[] = $nombre;
            }
        }
        sort($conOpcion);
        $this->assertSame([
            'credential-flow:encuestas:delta', 'credential-flow:encuestas:delta-rollback', 'credential-flow:encuestas:migrar',
            'credential-flow:legado:delta', 'credential-flow:legado:delta-rollback', 'credential-flow:legado:migrar',
        ], $conOpcion);
    }
}
