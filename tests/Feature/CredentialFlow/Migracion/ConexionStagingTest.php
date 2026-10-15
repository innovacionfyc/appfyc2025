<?php

namespace Tests\Feature\CredentialFlow\Migracion;

use App\Support\CredentialFlow\Migracion\ConexionStaging;
use Illuminate\Support\Facades\Artisan;
use PHPUnit\Framework\Attributes\DataProvider;
use RuntimeException;
use Tests\TestCase;

/**
 * Fase 11F-C5: los comandos delta leen el staging con un usuario propio (contraseña solo por variable de entorno efímera) sin tocar la
 * conexión moderna por defecto. Sin la opción, el comportamiento histórico (heredar usuario/contraseña) no cambia. Sin datos reales.
 */
class ConexionStagingTest extends TestCase
{
    private const SECRETO = 'Zq7-secreto-sintetico-NO-REAL';

    protected function tearDown(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD);
        parent::tearDown();
    }

    private function porDefecto(): array
    {
        return config('database.connections.'.config('database.default'));
    }

    public function test_sin_usuario_hereda_la_conexion_por_defecto_aunque_exista_la_variable(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'='.self::SECRETO);

        foreach ([null, '', '   '] as $usuario) {
            $c = ConexionStaging::configurar('x_staging', $usuario);

            $this->assertSame('x_staging', $c['database']);
            $this->assertSame($this->porDefecto()['username'] ?? null, $c['username'] ?? null);
            $this->assertSame($this->porDefecto()['password'] ?? null, $c['password'] ?? null);
            $this->assertSame($this->porDefecto()['host'] ?? null, $c['host'] ?? null);
        }
    }

    public function test_con_usuario_cambia_solo_usuario_contrasena_y_base_y_no_toca_el_default(): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'='.self::SECRETO);
        $antes = $this->porDefecto();

        $c = ConexionStaging::configurar('x_staging', 'u_staging');

        $this->assertSame('u_staging', $c['username']);
        $this->assertSame(self::SECRETO, $c['password']);
        $this->assertSame('x_staging', $c['database']);
        $this->assertSame($antes['driver'], $c['driver']);
        $this->assertSame($antes['host'] ?? null, $c['host'] ?? null);
        $this->assertSame($antes['port'] ?? null, $c['port'] ?? null);
        $this->assertSame($antes, $this->porDefecto(), 'la conexión moderna por defecto no cambia');
    }

    public function test_con_usuario_y_sin_contrasena_bloquea_antes_de_conectar(): void
    {
        foreach ([false, ''] as $valor) {
            putenv($valor === false ? ConexionStaging::VARIABLE_PASSWORD : ConexionStaging::VARIABLE_PASSWORD.'=');
            try {
                ConexionStaging::configurar('x_staging', 'u_staging');
                $this->fail('Debía bloquear');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(ConexionStaging::VARIABLE_PASSWORD, $e->getMessage());
            }
        }
    }

    /** @return array<string,array{string}> */
    public static function comandos(): array
    {
        return ['legado' => ['credential-flow:legado:delta'], 'encuestas' => ['credential-flow:encuestas:delta']];
    }

    #[DataProvider('comandos')]
    public function test_el_comando_bloquea_sin_contrasena_y_no_imprime_secretos(string $comando): void
    {
        $this->artisan($comando, ['--staging-bd' => 'x_staging', '--staging-usuario' => 'u_staging', '--simular' => true])
            ->expectsOutputToContain('STAGING_EV_STAGING_PASSWORD')
            ->assertExitCode(1);
    }

    #[DataProvider('comandos')]
    public function test_la_contrasena_nunca_aparece_en_la_salida_aunque_la_guardia_o_la_conexion_fallen(string $comando): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'='.self::SECRETO);

        $codigo = Artisan::call($comando, ['--staging-bd' => 'x_staging', '--staging-usuario' => 'u_staging', '--simular' => true]);
        $salida = Artisan::output();

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringNotContainsString(self::SECRETO, $salida);
    }

    #[DataProvider('comandos')]
    public function test_staging_usuario_sin_staging_bd_se_rechaza(string $comando): void
    {
        putenv(ConexionStaging::VARIABLE_PASSWORD.'='.self::SECRETO);

        $this->artisan($comando, ['--staging-usuario' => 'u_staging', '--simular' => true])->assertExitCode(1);
    }
}
