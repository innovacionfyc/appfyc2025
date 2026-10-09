<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Legado\CodigoHistorico;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Códigos históricos (Fase 10B-1.5) con CONCURRENCIA REAL contra MySQL/MariaDB: procesos PHP independientes piden código a la vez.
 * Un mismo par → un solo registro y un solo código para todos. Pares distintos → códigos distintos, sin colisiones y contador consistente.
 * Frontera 99999 con carrera. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/CodigosHistoricosMysqlTest.php
 */
class CodigosHistoricosMysqlTest extends TestCase
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
        'database/migrations/2026_10_14_100000_cf_codigos_historicos_tables.php',
    ];

    private const TABLAS = ['cf_codigos_historicos', 'cf_codigo_historico_contador', 'movimientos', 'cf_migraciones_map', 'cf_migraciones_corridas', 'cf_correos', 'cf_descargas', 'cf_certificados_legado',
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
        // `movimientos` apunta a `usuarios` (fuera del alcance): se crea sin esa FK.
        Schema::create('movimientos', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tipo');
            $t->string('modulo');
            $t->text('descripcion');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'codigos_mysql_'.bin2hex(random_bytes(6));
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

    /** @return list<int> ids de certificados, uno por par distinto (documento propio) */
    private function sembrar(int $n): array
    {
        $ahora = now();
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético', 'nombre_normalizado' => 'evento sintetico', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $ids = [];
        foreach (range(1, $n) as $i) {
            $ids[] = DB::table('cf_certificados_legado')->insertGetId([
                'evento_id' => $evento, 'documento' => (string) (9100000 + $i), 'documento_clave' => (string) (9100000 + $i), 'nombre_completo' => 'PERSONA '.$i, 'codigo_legado' => null,
                'conciliacion_estado' => 'ok', 'snapshot_legado' => '{}', 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }

        return $ids;
    }

    private function trabajador(): string
    {
        $raiz = var_export(base_path(), true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $codigo = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        \$cert = App\\Models\\CredentialFlow\\CertificadoLegado::findOrFail((int) \$argv[1]);
        while (! file_exists({$go})) { usleep(2000); }
        try {
            echo json_encode(['codigo' => (new App\\Support\\CredentialFlow\\Legado\\CodigoHistorico)->resolverOAsignar(\$cert)]);
        } catch (App\\Support\\CredentialFlow\\Legado\\CodigoHistoricoException \$e) {
            echo json_encode(['error' => \$e->codigo]);
        } catch (Throwable \$e) {
            echo json_encode(['error' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 160)]);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /** @param list<int> $certificados un proceso por elemento @return list<array<string,mixed>> */
    private function correr(array $certificados): array
    {
        $script = $this->trabajador();
        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing']);
        $procesos = [];
        foreach ($certificados as $id) {
            $p = new Process([PHP_BINARY, $script, (string) $id], base_path(), $entorno);
            $p->start();
            $procesos[] = $p;
        }
        touch($this->dir.DIRECTORY_SEPARATOR.'go');
        $salidas = [];
        foreach ($procesos as $p) {
            $p->wait();
            $salidas[] = json_decode(trim($p->getOutput()), true) ?? ['error' => 'SIN_SALIDA', 'stderr' => $p->getErrorOutput()];
        }

        return $salidas;
    }

    public function test_diez_procesos_pidiendo_el_mismo_par_reciben_el_mismo_codigo_y_hay_un_solo_registro(): void
    {
        [$id] = $this->sembrar(1);

        $r = $this->correr(array_fill(0, 10, $id));

        $this->assertSame(['50000'], array_values(array_unique(array_column($r, 'codigo'))), json_encode($r));
        $this->assertSame(10, count(array_filter($r, fn ($x) => isset($x['codigo']))));
        $this->assertSame(1, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(50001, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'codigo_historico')->count());
    }

    public function test_diez_pares_distintos_a_la_vez_reciben_codigos_distintos_y_el_contador_queda_consistente(): void
    {
        $ids = $this->sembrar(10);

        $r = $this->correr($ids);

        $codigos = collect($r)->pluck('codigo');
        $this->assertSame(10, $codigos->filter()->count(), json_encode($r));
        $this->assertSame(10, $codigos->unique()->count());
        $this->assertEqualsCanonicalizing(array_map('strval', range(50000, 50009)), $codigos->all());
        $this->assertSame(10, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(50010, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        $this->assertSame(10, DB::table('cf_codigos_historicos')->distinct()->count('par_hash'));
    }

    public function test_la_frontera_99999_con_carrera_asigna_los_libres_y_falla_el_resto_sin_pasar_a_seis_digitos(): void
    {
        $ids = $this->sembrar(5);
        DB::table('cf_codigo_historico_contador')->update(['siguiente' => 99998]);

        $r = $this->correr($ids);

        $this->assertEqualsCanonicalizing(['99998', '99999'], array_values(array_filter(array_column($r, 'codigo'))), json_encode($r));
        $this->assertSame(3, count(array_filter($r, fn ($x) => ($x['error'] ?? null) === 'RANGO_CODIGOS_HISTORICOS_AGOTADO')));
        $this->assertSame(2, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(100000, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        $this->assertSame(0, DB::table('cf_codigos_historicos')->whereRaw('LENGTH(codigo) > 5')->count());
    }

    public function test_una_colision_forzada_con_un_codigo_legado_se_salta_tambien_en_mysql(): void
    {
        $ids = $this->sembrar(2);
        DB::table('cf_certificados_legado')->where('id', $ids[1])->update(['codigo_legado' => '50000']);

        $codigo = (new CodigoHistorico)->resolverOAsignar(CertificadoLegado::findOrFail($ids[0]));

        $this->assertSame('50001', $codigo);
        $this->assertSame(50002, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
    }

    public function test_los_check_unique_y_fk_de_las_tablas_de_codigos(): void
    {
        [$a, $b] = $this->sembrar(2);
        $evento = (int) DB::table('cf_eventos')->value('id');
        $fila = fn (string $codigo, int $cert, string $hash) => ['codigo' => $codigo, 'evento_id' => $evento, 'certificado_canonico_id' => $cert, 'par_hash' => $hash, 'created_at' => now(), 'updated_at' => now()];
        DB::table('cf_codigos_historicos')->insert($fila('50000', $a, str_repeat('a', 64)));
        $debeFallar = function (callable $f, string $que) {
            try {
                $f();
                $this->fail($que);
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        };

        $debeFallar(fn () => DB::table('cf_codigos_historicos')->insert($fila('50000', $b, str_repeat('b', 64))), 'código repetido');
        $debeFallar(fn () => DB::table('cf_codigos_historicos')->insert($fila('50001', $b, str_repeat('a', 64))), 'par repetido');
        $debeFallar(fn () => DB::table('cf_codigos_historicos')->insert($fila('ABCDE', $b, str_repeat('c', 64))), 'formato no numérico (CHECK)');
        $debeFallar(fn () => DB::table('cf_codigo_historico_contador')->insert(['id' => 2, 'inicio' => 50000, 'fin' => 99999, 'siguiente' => 50000, 'created_at' => now(), 'updated_at' => now()]), 'segunda fila del contador (CHECK)');
        $debeFallar(fn () => DB::table('cf_codigo_historico_contador')->where('id', 1)->update(['fin' => 100000]), 'fin de 6 dígitos (CHECK)');
        $debeFallar(fn () => DB::table('cf_certificados_legado')->where('id', $a)->delete(), 'borrar el certificado de un código (FK)');
        $debeFallar(fn () => DB::table('cf_eventos')->where('id', $evento)->delete(), 'borrar el evento de un código (FK)');
    }
}
