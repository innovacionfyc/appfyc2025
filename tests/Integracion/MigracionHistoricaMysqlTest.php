<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\RollbackCorrida;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;
use Tests\TestCase;

/**
 * Migración histórica (Fase 4) contra MySQL/MariaDB REAL con el staging SINTÉTICO: UNIQUE binarios, FK RESTRICT, JSON, borrado con
 * subconsultas, lotes de inserción y rollback con restricciones reales. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/MigracionHistoricaMysqlTest.php
 */
class MigracionHistoricaMysqlTest extends TestCase
{
    use FixturesStagingEv;

    private const CONEXION = 'mysql_pruebas';

    private const MIGRACIONES = [
        'database/migrations/2026_09_29_160000_cf_plantillas_table.php',
        'database/migrations/2026_09_30_100000_cf_lotes_table.php',
        'database/migrations/2026_09_30_100100_cf_participantes_table.php',
        'database/migrations/2026_09_30_200000_cf_emisiones_table.php',
        'database/migrations/2026_10_06_100000_cf_eventos_table.php',
        'database/migrations/2026_10_06_100100_add_evento_id_to_cf_lotes_table.php',
        'database/migrations/2026_10_06_100200_add_correo_columns_to_cf_participantes_table.php',
        'database/migrations/2026_10_06_100300_cf_plantillas_legado_table.php',
        'database/migrations/2026_10_06_100400_cf_certificados_legado_table.php',
        'database/migrations/2026_10_06_100500_cf_descargas_table.php',
        'database/migrations/2026_10_06_100600_cf_correos_table.php',
        'database/migrations/2026_10_06_100700_add_plantilla_legado_to_cf_eventos_table.php',
        'database/migrations/2026_10_08_100000_cf_migraciones_corridas_table.php',
        'database/migrations/2026_10_08_100100_add_corrida_id_to_tablas_historicas.php',
    ];

    private const TABLAS = [
        'movimientos', 'cf_migraciones_map', 'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos',
        'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_migraciones_corridas', 'cf_plantillas',
        'stg_ev_historial', 'stg_ev_participante_correos', 'stg_ev_imagenes', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
        'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots', 'migrations',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');
        if ($base === '' || ! preg_match('/_(dev|test|testing)$/', $base) || $base === ($config['database'] ?? '')
            || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }
        $driver = (string) getenv('TEST_MYSQL_DRIVER');
        config([
            'database.connections.'.self::CONEXION => [...$config, 'driver' => $driver ?: ($config['driver'] ?? 'mysql'), 'database' => $base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);

        $this->limpiar();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRACIONES, '--force' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
        // Mínima y sin FK a usuarios (el módulo de usuarios no interviene).
        Schema::create('movimientos', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tipo');
            $t->string('modulo');
            $t->text('descripcion');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
    }

    protected function tearDown(): void
    {
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
        foreach (self::TABLAS as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    private function cargarStaging(?array $datos = null): void
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'mig_mysql_'.bin2hex(random_bytes(6));
        mkdir($dir);
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($dir.'/ALFA 2024.png', $png);
        file_put_contents($dir.'/DELTA 2023', $png);
        file_put_contents($dir.'/ORFANA.png', $png.'o');
        file_put_contents($dir.'/GAMMA_.png', $png.'g');
        $this->cargar('s1', $datos, (new EscanerImagenes)->escanear($dir));
        File::deleteDirectory($dir);
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return collect(['cf_eventos', 'cf_plantillas_legado_contenidos', 'cf_plantillas_legado', 'cf_certificados_legado', 'cf_correos', 'cf_descargas', 'cf_migraciones_map'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    public function test_migracion_idempotencia_y_rollback_con_restricciones_reales(): void
    {
        $this->cargarStaging();

        $r = (new MigradorHistorico)->ejecutar();
        $esperado = ['cf_eventos' => 6, 'cf_plantillas_legado_contenidos' => 3, 'cf_plantillas_legado' => 7, 'cf_certificados_legado' => 15, 'cf_correos' => 12, 'cf_descargas' => 4, 'cf_migraciones_map' => 35];

        $this->assertFalse($r->noOp);
        $this->assertSame($esperado, $this->conteos());
        foreach ($r->totales['validaciones'] as $v) {
            $this->assertTrue($v['ok'], $v['clave']);
        }
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('visible_portal', true)->count());

        // Idempotencia: NO-OP.
        $segunda = (new MigradorHistorico)->ejecutar();
        $this->assertTrue($segunda->noOp);
        $this->assertSame($esperado, $this->conteos());
        $this->assertSame(1, MigracionCorrida::count());

        // El modelo CertificadoLegado se niega a borrar; el rollback técnico usa acceso directo y respeta las FK RESTRICT.
        $borrado = (new RollbackCorrida)->revertir($r->corridaId);
        $this->assertSame(array_fill_keys(array_keys($esperado), 0), $this->conteos());
        $this->assertSame(15, $borrado['certificados']);
        $this->assertSame('revertida', MigracionCorrida::findOrFail($r->corridaId)->estado);
        $this->assertSame(15, DB::table('stg_ev_participante')->count(), 'El staging no se toca');

        // Una corrida nueva reproduce exactamente lo mismo.
        $tercera = (new MigradorHistorico)->ejecutar();
        $this->assertFalse($tercera->noOp);
        $this->assertSame($esperado, $this->conteos());
    }

    public function test_el_rollback_se_niega_ante_actividad_posterior_en_el_motor_real(): void
    {
        $this->cargarStaging();
        $r = (new MigradorHistorico)->ejecutar();
        DB::table('cf_descargas')->insert(['certificado_legado_id' => DB::table('cf_certificados_legado')->value('id'), 'via' => 'portal', 'created_at' => now(), 'updated_at' => now()]);
        $antes = $this->conteos();

        try {
            (new RollbackCorrida)->revertir($r->corridaId);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::DESCARGAS_NUEVAS, $e->codigo);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_una_cifra_que_no_cuadra_revierte_toda_la_corrida_en_el_motor_real(): void
    {
        // Un código compartido por dos personas distintas.
        $this->cargarStaging($this->conCambio($this->datos(), 'participante', 11, ['num_verificacion' => 100]));

        try {
            (new MigradorHistorico)->ejecutar();
            $this->fail('Debía fallar');
        } catch (\RuntimeException $e) {
            $this->assertStringContainsString('codigos_compartidos_entre_pares_distintos', $e->getMessage());
        }

        $this->assertSame(array_fill_keys(array_keys($this->conteos()), 0), $this->conteos(), 'InnoDB revirtió todo');
        $this->assertSame('fallida', MigracionCorrida::sole()->estado);
    }

    public function test_la_ruta_original_binaria_distingue_nombres_que_solo_difieren_en_tildes(): void
    {
        $this->cargarStaging();
        (new MigradorHistorico)->ejecutar();
        $corrida = MigracionCorrida::sole()->id;
        $fila = fn (string $ruta) => [
            'ruta_original' => $ruta, 'nombre_original' => basename($ruta), 'nombre_normalizado' => 'x', 'estado' => 'huerfana', 'renderizable' => false,
            'corrida_id' => $corrida, 'created_at' => now(), 'updated_at' => now(),
        ];

        DB::table('cf_plantillas_legado')->insert($fila('document/certImages/AGO - Ética.png'));
        DB::table('cf_plantillas_legado')->insert($fila('document/certImages/AGO - ETICA.png'));

        $this->assertSame(9, DB::table('cf_plantillas_legado')->count());
    }
}
