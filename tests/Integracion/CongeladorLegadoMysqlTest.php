<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;
use Tests\TestCase;

/**
 * Congelado perezoso de PDF (Fase 6) con CONCURRENCIA REAL: dos procesos PHP independientes contra MySQL real intentan congelar el
 * MISMO certificado a la vez. El segundo debe esperar el bloqueo de fila del primero (SELECT … FOR UPDATE) y reutilizar su PDF:
 * un único archivo final, una única metadata y exactamente un proceso que genera. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/CongeladorLegadoMysqlTest.php
 */
class CongeladorLegadoMysqlTest extends TestCase
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
        'database/migrations/2026_10_14_100000_cf_codigos_historicos_tables.php',
    ];

    private const TABLAS = [
        'cf_codigos_historicos', 'cf_codigo_historico_contador', 'movimientos', 'cf_migraciones_map', 'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos',
        'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_migraciones_corridas', 'cf_plantillas',
        'stg_ev_historial', 'stg_ev_participante_correos', 'stg_ev_imagenes', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
        'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots', 'migrations',
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
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
        Schema::create('movimientos', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tipo');
            $t->string('modulo');
            $t->text('descripcion');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'cong_mysql_'.bin2hex(random_bytes(6));
        mkdir($this->dir.DIRECTORY_SEPARATOR.'img', 0777, true);
        mkdir($this->dir.DIRECTORY_SEPARATOR.'disco', 0777, true);
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

    /** Script de un trabajador: arranca la app contra la base de pruebas, espera la señal y congela el certificado. */
    private function escribirTrabajador(): string
    {
        $raiz = var_export(base_path(), true);
        $img = var_export($this->dir.DIRECTORY_SEPARATOR.'img', true);
        $disco = var_export($this->dir.DIRECTORY_SEPARATOR.'disco', true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $codigo = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\Contracts\Console\Kernel::class)->bootstrap();
        config(['filesystems.disks.local.root' => {$disco}]);
        \$id = (int) \$argv[1];
        \$retener = (int) \$argv[2];
        while (! file_exists({$go})) { usleep(5000); }
        if (\$retener > 0) {
            // El primero retiene el bloqueo de fila un rato, para que el segundo compita DE VERDAD.
            App\Support\CredentialFlow\Legado\CongeladorLegado::\$despuesDeBloquear = function () use (\$retener) { usleep(\$retener * 1000); };
        }
        \$c = new App\Support\CredentialFlow\Legado\CongeladorLegado(new App\Support\CredentialFlow\Legado\RendererLegado, new App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio({$img}));
        try {
            \$a = \$c->servir(App\Models\CredentialFlow\CertificadoLegado::findOrFail(\$id));
            echo json_encode(['gen' => \$a->recienGenerado, 'sha' => \$a->sha256, 'bytes' => \$a->bytes, 'ruta' => \$a->ruta]);
        } catch (Throwable \$e) {
            echo json_encode(['error' => get_class(\$e).': '.\$e->getMessage()]);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /** @return array{0:resource,1:array} */
    private function lanzar(string $script, int $id, int $retenerMs): array
    {
        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing']);
        $p = proc_open([PHP_BINARY, $script, (string) $id, (string) $retenerMs], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $entorno);
        $this->assertIsResource($p);

        return [$p, $pipes];
    }

    private function terminar($p, array $pipes): array
    {
        $salida = stream_get_contents($pipes[1]);
        $err = stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);
        proc_close($p);
        $json = json_decode((string) $salida, true);
        $this->assertIsArray($json, "El trabajador no devolvió JSON. stdout={$salida} stderr={$err}");

        return $json;
    }

    public function test_dos_procesos_reales_congelando_el_mismo_certificado_producen_un_unico_pdf(): void
    {
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        file_put_contents($this->dir.'/img/ALFA 2024.png', (string) ob_get_clean());
        $datos = $this->conCambio($this->datos(), 'participante', 1, ['num_verificacion' => 5237]);
        $this->cargar('s1', $datos, (new EscanerImagenes)->escanear($this->dir.'/img'));
        (new MigradorHistorico)->ejecutar();
        $id = (int) DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '1')->value('destino_id');
        $this->assertSame('ok', CertificadoLegado::findOrFail($id)->conciliacion_estado);
        $script = $this->escribirTrabajador();

        // A retiene el bloqueo 1,5 s; B arranca poco después y debe esperar a A.
        [$pa, $ta] = $this->lanzar($script, $id, 1500);
        [$pb, $tb] = $this->lanzar($script, $id, 0);
        usleep(600_000); // ambos ya arrancaron la app y esperan la señal
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'go', '1');
        $a = $this->terminar($pa, $ta);
        $b = $this->terminar($pb, $tb);

        $this->assertArrayNotHasKey('error', $a, json_encode($a));
        $this->assertArrayNotHasKey('error', $b, json_encode($b));
        $this->assertSame(1, (int) $a['gen'] + (int) $b['gen'], 'Exactamente un proceso genera; el otro reutiliza');
        $this->assertSame($a['sha'], $b['sha']);
        $this->assertSame($a['bytes'], $b['bytes']);

        // Un único PDF final, íntegro, sin temporales; una única metadata coherente.
        $disco = $this->dir.DIRECTORY_SEPARATOR.'disco';
        $archivos = array_values(array_filter(File::allFiles($disco), fn ($f) => true));
        $this->assertCount(1, $archivos, 'Un solo archivo en el disco privado');
        $this->assertStringEndsWith(str_replace('/', DIRECTORY_SEPARATOR, RutasLegado::certificado($id)), $archivos[0]->getPathname());
        $this->assertSame($a['sha'], hash_file('sha256', $archivos[0]->getPathname()));
        $this->assertSame((int) $a['bytes'], filesize($archivos[0]->getPathname()));
        $fila = CertificadoLegado::findOrFail($id);
        $this->assertSame([RutasLegado::certificado($id), $a['sha'], (int) $a['bytes']], [$fila->pdf_archivo, $fila->pdf_hash, $fila->pdf_bytes]);
        $this->assertNotNull($fila->materializado_at);
        $this->assertSame(1, DB::table('cf_certificados_legado')->whereNotNull('pdf_archivo')->count());
    }
}
