<?php

namespace Tests\Feature\CredentialFlow\StagingEv;

use App\Support\CredentialFlow\StagingEv\OrigenConexion;
use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * La conexión ORIGEN del staging puede usar un usuario propio (contraseña solo por variable de entorno efímera). Sin la
 * opción, el comportamiento histórico (heredar la conexión del proceso) no cambia. SQLite en memoria y datos sintéticos.
 */
class OrigenConexionTest extends StagingEvTestCase
{
    private const SECRETO = 'Sx9-secreto-sintetico-NO-REAL';

    protected function tearDown(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD);
        parent::tearDown();
    }

    private function base(): array
    {
        return config('database.connections.'.config('database.default'));
    }

    public function test_sin_usuario_de_origen_hereda_la_conexion_del_proceso(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD.'='.self::SECRETO);   // aunque exista, sin la opción NO se usa

        foreach ([null, '', '   '] as $usuario) {
            $config = OrigenConexion::configurar('x_snapshot', $usuario);

            $this->assertSame('x_snapshot', $config['database']);
            $this->assertSame($this->base()['username'] ?? null, $config['username'] ?? null);
            $this->assertSame($this->base()['password'] ?? null, $config['password'] ?? null);
            $this->assertSame($this->base()['host'] ?? null, $config['host'] ?? null);
        }
    }

    public function test_con_usuario_de_origen_cambia_solo_usuario_contrasena_y_base(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD.'='.self::SECRETO);

        $config = OrigenConexion::configurar('x_snapshot', 'u_origen');

        $this->assertSame('u_origen', $config['username']);
        $this->assertSame(self::SECRETO, $config['password']);
        $this->assertSame('x_snapshot', $config['database']);
        $this->assertSame($this->base()['driver'], $config['driver']);
        $this->assertSame($this->base()['host'] ?? null, $config['host'] ?? null);
        $this->assertSame($this->base()['port'] ?? null, $config['port'] ?? null);
    }

    public function test_el_destino_sigue_en_la_conexion_por_defecto(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD.'='.self::SECRETO);
        $antes = $this->base();

        OrigenConexion::configurar('x_snapshot', 'u_origen');

        $this->assertSame($antes, $this->base(), 'configurar() no debe modificar la conexión por defecto');
        $this->assertSame(':memory:', DB::connection()->getDatabaseName());
    }

    public function test_con_usuario_y_sin_contrasena_bloquea_antes_de_conectar(): void
    {
        foreach ([false, ''] as $valor) {
            putenv($valor === false ? OrigenConexion::VARIABLE_PASSWORD : OrigenConexion::VARIABLE_PASSWORD.'=');
            try {
                OrigenConexion::configurar('x_snapshot', 'u_origen');
                $this->fail('Debía bloquear');
            } catch (RuntimeException $e) {
                $this->assertStringContainsString(OrigenConexion::VARIABLE_PASSWORD, $e->getMessage());
            }
        }
    }

    public function test_el_comando_bloquea_sin_contrasena_y_no_la_imprime(): void
    {
        $this->artisan('credential-flow:staging-ev:cargar', ['--dump' => 'd.sql', '--origen-bd' => 'x_snapshot', '--origen-usuario' => 'u_origen'])
            ->expectsOutputToContain('STAGING_EV_ORIGEN_PASSWORD')
            ->assertExitCode(1);
    }

    public function test_la_contrasena_nunca_aparece_en_la_salida_aunque_falle_la_conexion(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD.'='.self::SECRETO);
        $dump = tempnam(sys_get_temp_dir(), 'stgdump');
        file_put_contents($dump, '-- dump sintetico');

        try {
            $codigo = \Artisan::call('credential-flow:staging-ev:cargar', ['--dump' => $dump, '--origen-bd' => 'x_snapshot', '--origen-usuario' => 'u_origen']);
            $salida = \Artisan::output();
        } finally {
            unlink($dump);
        }

        $this->assertSame(1, $codigo, $salida);
        $this->assertStringNotContainsString(self::SECRETO, $salida);
    }

    public function test_los_guardias_del_staging_siguen_funcionando_con_usuario_de_origen(): void
    {
        putenv(OrigenConexion::VARIABLE_PASSWORD.'='.self::SECRETO);

        // Origen sin «snapshot»/«origen» en el nombre: lo bloquea GuardiaStaging, antes de usar credenciales.
        $this->artisan('credential-flow:staging-ev:cargar', ['--dump' => 'd.sql', '--origen-bd' => 'cualquier_cosa', '--origen-usuario' => 'u_origen'])
            ->expectsOutputToContain('snapshot')
            ->assertExitCode(1);
    }

    public function test_la_carga_completa_y_su_idempotencia_no_cambian(): void
    {
        $primera = $this->cargar('s1');
        $segunda = $this->cargar('s1');

        $this->assertFalse($primera->yaCargado);
        $this->assertTrue($segunda->yaCargado);
        $this->assertSame(15, $primera->entidades['participante']['nuevas']);
    }
}
