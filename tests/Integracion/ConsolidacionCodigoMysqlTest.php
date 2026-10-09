<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Conciliaciones\ConsolidacionCodigo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Consolidación de DIF_VERIF (Fase 10B-2A) con CONCURRENCIA REAL contra MySQL/MariaDB: varios procesos intentan consolidar el MISMO caso a la
 * vez. Debe quedar UNA resolución, UN evento final, estados consistentes y NINGÚN código nuevo. También ejecuta la reversión con el esquema
 * real (los nombres de acción miden más de 30 caracteres: SQLite no lo valida). Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/ConsolidacionCodigoMysqlTest.php
 */
class ConsolidacionCodigoMysqlTest extends TestCase
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
        'database/migrations/2026_10_09_100100_add_origen_to_cf_descargas_table.php',
        'database/migrations/2026_10_12_100000_cf_conciliaciones_tables.php',
        'database/migrations/2026_10_13_100000_add_aprobada_por_conciliacion_to_cf_plantillas_legado.php',
        'database/migrations/2026_10_13_100100_widen_accion_in_cf_conciliaciones_eventos.php',
        'database/migrations/2026_10_14_100000_cf_codigos_historicos_tables.php',
    ];

    private const TABLAS = ['cf_codigos_historicos', 'cf_codigo_historico_contador', 'cf_conciliaciones_eventos', 'cf_conciliaciones_certificados', 'movimientos', 'cf_conciliaciones', 'cf_migraciones_map', 'cf_migraciones_corridas',
        'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

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
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'consol_mysql_'.bin2hex(random_bytes(6));
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

    /** Un par DIF_VERIF (una variante con código y descarga histórica, otra NULL) y su caso abierto. @return array{0:int,1:int,2:int} caso, canónica, nula */
    private function sembrar(): array
    {
        $ahora = now();
        $corrida = DB::table('cf_migraciones_corridas')->insertGetId(['tipo' => 'legado_evaluaciones', 'snapshot_sha256' => str_repeat('a', 64), 'huella_global' => str_repeat('b', 64), 'huella_derivada' => str_repeat('c', 64), 'estado' => 'completada', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $plantilla = DB::table('cf_plantillas_legado')->insertGetId(['ruta_original' => 'document/certImages/A.png', 'nombre_original' => 'A.png', 'nombre_normalizado' => 'a', 'extension_original' => 'png', 'renderizable' => 1, 'estado' => 'ok', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético', 'nombre_normalizado' => 'evento sintetico', 'plantilla_legado_id' => $plantilla, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $cert = fn (int $old, ?string $codigo) => DB::table('cf_certificados_legado')->insertGetId([
            'corrida_id' => $corrida, 'evento_id' => $evento, 'plantilla_legado_id' => $plantilla, 'tipo_documento' => 'CC', 'documento' => '9200001', 'documento_clave' => '9200001', 'nombre_completo' => 'PERSONA PAR', 'codigo_legado' => $codigo,
            'conciliacion_estado' => 'pendiente_conciliacion', 'grupo_duplicado' => 'dup-9', 'estado' => 'vigente',
            'snapshot_legado' => json_encode(['migracion' => ['old_id' => $old], 'documento_estado' => 'valido', 'duplicado' => ['clasificacion' => 'conflictivo', 'etiquetas' => 'DIF_VERIF', 'canonico_old_id' => 1]]), 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $coded = $cert(1, '6200');
        $nula = $cert(2, null);
        foreach ([[1, $coded], [2, $nula]] as [$old, $id]) {
            DB::table('cf_migraciones_map')->insert(['corrida_id' => $corrida, 'origen_tabla' => 'participante', 'origen_id' => (string) $old, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $id, 'relacion' => 'variante_conflictiva', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        DB::table('cf_descargas')->insert(['certificado_legado_id' => $coded, 'via' => 'portal', 'origen' => 'legado_importado', 'descargado_at' => $ahora, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'conflicto_variantes', 'estado' => 'abierto', 'evento_id' => $evento, 'referencia_tipo' => 'grupo_duplicado', 'referencia_clave' => 'dup-9', 'motivo_origen' => 'DIF_VERIF',
            'clave_idempotencia' => 'conflicto_variantes:grupo_duplicado:dup-9', 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach ([$coded, $nula] as $id) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $id, 'rol' => 'variante', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'detectado', 'created_at' => $ahora]);

        return [$caso, $coded, $nula];
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
        \$caso = (int) \$argv[1];
        \$s = app(App\\Support\\CredentialFlow\\Conciliaciones\\ConsolidacionCodigo::class);
        while (! file_exists({$go})) { usleep(2000); }
        try {
            \$s->consolidar(\$caso, 77, 'La diferencia es solo la asignación tardía del código.');
            echo json_encode(['resultado' => 'ok']);
        } catch (App\\Support\\CredentialFlow\\Conciliaciones\\ResolucionNoPermitida \$e) {
            echo json_encode(['resultado' => \$e->codigo, 'mensaje' => \$e->getMessage()]);
        } catch (Throwable \$e) {
            echo json_encode(['resultado' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 160)]);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    public function test_cinco_procesos_consolidando_el_mismo_caso_producen_una_sola_resolucion(): void
    {
        [$caso, $coded, $nula] = $this->sembrar();
        $script = $this->trabajador();
        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing']);
        $procesos = [];
        foreach (range(1, 5) as $_) {
            $p = new Process([PHP_BINARY, $script, (string) $caso], base_path(), $entorno);
            $p->start();
            $procesos[] = $p;
        }
        touch($this->dir.DIRECTORY_SEPARATOR.'go');
        $salidas = [];
        foreach ($procesos as $p) {
            $p->wait();
            $salidas[] = json_decode(trim($p->getOutput()), true) ?? ['resultado' => 'SIN_SALIDA', 'error' => $p->getErrorOutput()];
        }

        $this->assertEquals(['ok' => 1, 'CASO_YA_RESUELTO' => 4], collect($salidas)->pluck('resultado')->countBy()->all(), json_encode($salidas));
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'codigo_consolidado', 77], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'conflicto_codigo_consolidado')->count());
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->count());
        $this->assertSame(['ok', 'duplicado_consolidado'], [DB::table('cf_certificados_legado')->where('id', $coded)->value('conciliacion_estado'), DB::table('cf_certificados_legado')->where('id', $nula)->value('conciliacion_estado')]);
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->count());
        $this->assertSame(2, DB::table('cf_migraciones_map')->where('relacion', 'variante_conflictiva')->count());
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'conciliacion')->count());
        // Ningún código nuevo y el histórico intacto.
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(50000, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
        $this->assertSame(['6200', null], [DB::table('cf_certificados_legado')->where('id', $coded)->value('codigo_legado'), DB::table('cf_certificados_legado')->where('id', $nula)->value('codigo_legado')]);
    }

    public function test_la_consolidacion_y_su_reversion_funcionan_con_el_esquema_real(): void
    {
        [$caso, $coded, $nula] = $this->sembrar();
        $s = app(ConsolidacionCodigo::class);

        $s->consolidar($caso, 77, 'La diferencia es solo la asignación tardía del código.');
        $this->assertSame('conflicto_codigo_consolidado', DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'));

        $s->revertir($caso, 77, 'Revierto la consolidación de prueba.');
        $this->assertSame('conflicto_consolidacion_revertida', DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'));
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame(['pendiente_conciliacion', 'pendiente_conciliacion'], [DB::table('cf_certificados_legado')->where('id', $coded)->value('conciliacion_estado'), DB::table('cf_certificados_legado')->where('id', $nula)->value('conciliacion_estado')]);
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->count());
        $this->assertSame(['6200', null], [DB::table('cf_certificados_legado')->where('id', $coded)->value('codigo_legado'), DB::table('cf_certificados_legado')->where('id', $nula)->value('codigo_legado')]);
    }
}
