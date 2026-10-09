<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\RollbackConciliaciones;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Conciliaciones (Fase 10A) contra MySQL/MariaDB REAL: CHECK de tipo y estado, UNIQUE de la clave de idempotencia y del pivote, FK RESTRICT,
 * detector idempotente (también con varios procesos a la vez) y rollback técnico. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/ConciliacionesMysqlTest.php
 */
class ConciliacionesMysqlTest extends TestCase
{
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
        'database/migrations/2026_10_12_100000_cf_conciliaciones_tables.php',
        'database/migrations/2026_10_13_100000_add_aprobada_por_conciliacion_to_cf_plantillas_legado.php',
        'database/migrations/2026_10_13_100100_widen_accion_in_cf_conciliaciones_eventos.php',
    ];

    private const TABLAS = ['cf_conciliaciones_eventos', 'cf_conciliaciones_certificados', 'cf_conciliaciones', 'movimientos', 'cf_migraciones_map', 'cf_migraciones_corridas', 'cf_correos', 'cf_descargas', 'cf_certificados_legado',
        'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

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
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concil_mysql_'.bin2hex(random_bytes(6));
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
        foreach (self::TABLAS as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** Dos variantes conflictivas de un grupo y un certificado con documento en revisión, en un evento sintético. */
    private function sembrar(): array
    {
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético', 'nombre_normalizado' => 'evento sintetico', 'created_at' => now(), 'updated_at' => now()]);
        $cert = fn (string $doc, string $nombre, string $estado, ?string $grupo, array $snap) => DB::table('cf_certificados_legado')->insertGetId([
            'evento_id' => $evento, 'documento' => $doc, 'documento_clave' => $doc, 'nombre_completo' => $nombre, 'conciliacion_estado' => $estado, 'grupo_duplicado' => $grupo,
            'snapshot_legado' => json_encode($snap), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $a = $cert('9000001', 'VARIANTE UNO', 'pendiente_conciliacion', 'dup-1', ['duplicado' => ['clasificacion' => 'conflictivo', 'etiquetas' => 'DIF_VERIF']]);
        $b = $cert('9000001', 'VARIANTE UNO', 'pendiente_conciliacion', 'dup-1', ['duplicado' => ['clasificacion' => 'conflictivo', 'etiquetas' => 'DIF_VERIF']]);
        $c = $cert('ABC9000', 'DOCUMENTO LETRAS', 'revision_documento', null, ['motivos' => 'DOC_LETRAS']);

        return [$evento, $a, $b, $c];
    }

    public function test_los_check_de_tipo_y_estado_los_rechaza_la_base_aunque_se_salte_el_modelo(): void
    {
        $fila = ['tipo' => 'revision_documento', 'estado' => 'abierto', 'referencia_tipo' => 'certificado', 'referencia_clave' => '1', 'clave_idempotencia' => 'k1', 'created_at' => now(), 'updated_at' => now()];
        DB::table('cf_conciliaciones')->insert($fila);

        foreach (['tipo' => 'inventado', 'estado' => 'inventado'] as $campo => $valor) {
            try {
                DB::table('cf_conciliaciones')->insert([$campo => $valor, 'clave_idempotencia' => 'k-'.$campo] + $fila);
                $this->fail("El CHECK debía rechazar {$campo} = {$valor}");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/cf_concil_(tipo|estado)_chk|check constraint/i', $e->getMessage());
            }
        }
        try {
            DB::table('cf_conciliaciones')->where('clave_idempotencia', 'k1')->update(['estado' => 'inventado']);
            $this->fail('El CHECK debía rechazar el UPDATE');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(1, DB::table('cf_conciliaciones')->count());
    }

    public function test_unique_y_fk_restrict(): void
    {
        [$evento, $a] = $this->sembrar();
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'revision_documento', 'estado' => 'abierto', 'evento_id' => $evento, 'referencia_tipo' => 'certificado', 'referencia_clave' => (string) $a,
            'clave_idempotencia' => 'revision_documento:certificado:'.$a, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $a, 'created_at' => now(), 'updated_at' => now()]);
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'detectado', 'created_at' => now()]);

        $debeFallar = function (callable $f, string $que) {
            try {
                $f();
                $this->fail($que);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };
        $debeFallar(fn () => DB::table('cf_conciliaciones')->insert(['tipo' => 'revision_documento', 'estado' => 'abierto', 'referencia_tipo' => 'certificado', 'referencia_clave' => 'z', 'clave_idempotencia' => 'revision_documento:certificado:'.$a, 'created_at' => now(), 'updated_at' => now()]), 'clave de idempotencia repetida');
        $debeFallar(fn () => DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $a, 'created_at' => now(), 'updated_at' => now()]), 'par repetido en el pivote');
        $debeFallar(fn () => DB::table('cf_certificados_legado')->where('id', $a)->delete(), 'borrar un certificado con caso');
        $debeFallar(fn () => DB::table('cf_conciliaciones')->where('id', $caso)->delete(), 'borrar un caso con relaciones');
        $debeFallar(fn () => DB::table('cf_eventos')->where('id', $evento)->delete(), 'borrar un evento con casos');
    }

    public function test_el_detector_es_idempotente_y_el_rollback_revierte_o_se_niega(): void
    {
        [$evento, $a, $b, $c] = $this->sembrar();
        $detector = app(DetectorConciliaciones::class);

        $uno = $detector->ejecutar();
        $this->assertSame(['detectados' => 2, 'nuevos' => 2, 'existentes' => 0, 'relaciones_agregadas' => 3], $uno['total']);
        $this->assertSame(['conflicto_variantes', 'revision_documento'], DB::table('cf_conciliaciones')->orderBy('id')->pluck('tipo')->all());
        $this->assertSame([$a, $b], DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', DB::table('cf_conciliaciones')->where('tipo', 'conflicto_variantes')->value('id'))->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($x) => (int) $x)->all());
        $this->assertSame('DOC_LETRAS', DB::table('cf_conciliaciones')->where('tipo', 'revision_documento')->value('motivo_origen'));

        $dos = $detector->ejecutar();
        $this->assertSame(0, $dos['total']['nuevos']);
        $this->assertSame(0, $dos['total']['relaciones_agregadas']);
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->count());

        // Con una decisión posterior, el rollback se niega y no toca nada.
        DB::table('cf_conciliaciones')->where('tipo', 'revision_documento')->update(['estado' => 'resuelto']);
        try {
            app(RollbackConciliaciones::class)->revertir();
            $this->fail('Debió negarse.');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CASOS_CON_DECISIONES, $e->codigo);
        }
        $this->assertSame(2, DB::table('cf_conciliaciones')->count());

        DB::table('cf_conciliaciones')->update(['estado' => 'abierto']);
        $this->assertSame(['eventos' => 2, 'certificados' => 3, 'casos' => 2], app(RollbackConciliaciones::class)->revertir());
        $this->assertSame(3, DB::table('cf_certificados_legado')->count());
    }

    public function test_varios_procesos_reales_detectando_a_la_vez_no_duplican_casos_ni_eventos(): void
    {
        $this->sembrar();
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing']);

        $procesos = [];
        foreach (range(1, 4) as $_) {
            $p = new Process([PHP_BINARY, $script], base_path(), $entorno);
            $p->start();
            $procesos[] = $p;
        }
        touch($this->dir.DIRECTORY_SEPARATOR.'go');
        $salidas = [];
        foreach ($procesos as $p) {
            $p->wait();
            $salidas[] = trim($p->getOutput());
        }

        foreach ($salidas as $s) {
            $this->assertStringStartsWith('{', $s, 'Un proceso falló: '.$s);
        }
        $this->assertSame(2, DB::table('cf_conciliaciones')->count());
        $this->assertSame(3, DB::table('cf_conciliaciones_certificados')->count());
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->count());
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->where('accion', 'detectado')->distinct()->count('conciliacion_id'));
        // Exactamente un proceso creó cada caso.
        $this->assertSame(2, array_sum(array_map(fn ($s) => json_decode($s, true)['nuevos'], $salidas)));
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
        while (! file_exists({$go})) { usleep(2000); }
        for (\$i = 0; \$i < 5; \$i++) {
            try {
                \$r = app(App\\Support\\CredentialFlow\\Conciliaciones\\DetectorConciliaciones::class)->ejecutar();
                echo json_encode(['nuevos' => \$r['total']['nuevos'], 'reintentos' => \$i]);
                exit(0);
            } catch (Throwable \$e) {
                usleep(20000);
            }
        }
        echo 'FALLO '.get_class(\$e);
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }
}
