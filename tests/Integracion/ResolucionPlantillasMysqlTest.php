<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Conciliaciones\ResolucionPlantillas;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Resolución de plantillas (Fase 10B-1) con CONCURRENCIA REAL contra MySQL/MariaDB: varios procesos PHP independientes intentan resolver el
 * MISMO caso a la vez. Debe quedar UNA sola resolución, UN solo evento final, UN solo movimiento, ningún estado corrupto y (al aportar una
 * plantilla) un único archivo. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/ResolucionPlantillasMysqlTest.php
 */
class ResolucionPlantillasMysqlTest extends TestCase
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

    private const TABLAS = ['cf_conciliaciones_eventos', 'cf_conciliaciones_certificados', 'movimientos', 'cf_conciliaciones', 'cf_migraciones_map', 'cf_migraciones_corridas', 'cf_correos', 'cf_descargas', 'cf_certificados_legado',
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
        // `movimientos` apunta a `usuarios` (fuera del alcance de esta prueba): se crea sin esa FK.
        Schema::create('movimientos', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tipo');
            $t->string('modulo');
            $t->text('descripcion');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'resol_mysql_'.bin2hex(random_bytes(6));
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

    /** Un evento con la imagen faltante (con candidata), 3 certificados pendientes (uno canónico y su duplicado idéntico) y el caso detectado. */
    private function sembrar(bool $conCandidata): int
    {
        $ahora = now();
        $sha = str_repeat('a', 63).'b';
        $contenido = DB::table('cf_plantillas_legado_contenidos')->insertGetId(['sha256' => $sha, 'bytes' => 1234, 'mime_real' => 'image/png', 'ancho_px' => 3300, 'alto_px' => 2550, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cf_plantillas_legado')->insert(['contenido_id' => $contenido, 'ruta_original' => 'document/certImages/CANDIDATA.png', 'nombre_original' => 'CANDIDATA.png', 'nombre_normalizado' => 'candidata', 'extension_original' => 'png',
            'renderizable' => 1, 'estado' => $conCandidata ? 'candidata_revision' : 'huerfana', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $notas = $conCandidata ? json_encode(['evidencia_candidata' => ['candidata_sha256' => $sha, 'mime_real' => 'image/png', 'ancho_px' => 3300, 'alto_px' => 2550, 'bytes' => 1234]]) : null;
        $faltante = DB::table('cf_plantillas_legado')->insertGetId(['contenido_id' => null, 'ruta_original' => 'document/certImages/FALTANTE.png', 'nombre_original' => 'FALTANTE.png', 'nombre_normalizado' => 'faltante', 'extension_original' => 'png',
            'renderizable' => 0, 'estado' => 'faltante', 'notas' => $notas, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético', 'nombre_normalizado' => 'evento sintetico', 'plantilla_legado_id' => $faltante, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach ([[1, 1, 'DOC1'], [2, 2, 'DOC2'], [3, 2, 'DOC2']] as [$i, $old, $doc]) {
            $dup = $i >= 2 ? ['clasificacion' => 'identico', 'canonico_old_id' => 2] : null;
            DB::table('cf_certificados_legado')->insert([
                'evento_id' => $evento, 'documento' => '900000'.$old, 'documento_clave' => '900000'.$old, 'nombre_completo' => 'PERSONA '.$old, 'codigo_legado' => (string) (5000 + $i), 'conciliacion_estado' => 'pendiente_plantilla',
                'grupo_duplicado' => $dup ? 'dup-1' : null, 'snapshot_legado' => json_encode(['migracion' => ['old_id' => $i], 'documento_estado' => 'valido', 'duplicado' => $dup]), 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
        app(DetectorConciliaciones::class)->ejecutar();

        return (int) DB::table('cf_conciliaciones')->where('tipo', 'like', 'plantilla_%')->value('id');
    }

    private function trabajador(string $llamada): string
    {
        $raiz = var_export(base_path(), true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $almacen = var_export($this->dir.DIRECTORY_SEPARATOR.'storage', true);
        $codigo = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        config(['filesystems.disks.local.root' => {$almacen}]);
        Illuminate\\Support\\Facades\\Storage::forgetDisk('local');
        \$caso = (int) \$argv[1];
        \$archivo = \$argv[2] ?? '';
        \$s = app(App\\Support\\CredentialFlow\\Conciliaciones\\ResolucionPlantillas::class);
        while (! file_exists({$go})) { usleep(2000); }
        try {
            {$llamada}
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

    /** @return list<array<string,mixed>> */
    private function correrEnParalelo(string $script, int $caso, string $archivo = '', int $procesos = 5): array
    {
        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing']);
        $lista = [];
        foreach (range(1, $procesos) as $_) {
            $p = new Process([PHP_BINARY, $script, (string) $caso, $archivo], base_path(), $entorno);
            $p->start();
            $lista[] = $p;
        }
        touch($this->dir.DIRECTORY_SEPARATOR.'go');
        $salidas = [];
        foreach ($lista as $p) {
            $p->wait();
            $salidas[] = json_decode(trim($p->getOutput()), true) ?? ['resultado' => 'SIN_SALIDA', 'error' => $p->getErrorOutput()];
        }

        return $salidas;
    }

    public function test_varios_procesos_aprobando_la_misma_candidata_producen_una_sola_resolucion(): void
    {
        $caso = $this->sembrar(true);
        $script = $this->trabajador('$s->aprobarCandidata($caso, 77, \'Decisión administrativa de prueba.\');');

        $r = collect($this->correrEnParalelo($script, $caso))->pluck('resultado')->countBy()->all();

        $this->assertEquals(['ok' => 1, 'CASO_YA_RESUELTO' => 4], $r);
        $fila = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'candidata_aprobada', 77], [$fila->estado, $fila->resolucion, (int) $fila->resuelto_por]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', 'plantilla_candidata_aprobada')->count());
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->count());   // detectado + resolución
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'conciliacion')->count());
        $this->assertSame(1, DB::table('cf_plantillas_legado')->whereNotNull('aprobada_por_conciliacion_id')->count());
        // Estado coherente: todos asociados a la MISMA entrada; el duplicado idéntico recupera su relación.
        $this->assertSame(1, DB::table('cf_certificados_legado')->distinct()->count('plantilla_legado_id'));
        $this->assertEquals(['ok' => 2, 'duplicado_consolidado' => 1], DB::table('cf_certificados_legado')->selectRaw('conciliacion_estado e, count(*) n')->groupBy('e')->pluck('n', 'e')->all());
    }

    public function test_varios_procesos_aportando_la_misma_plantilla_dejan_un_solo_archivo_y_una_sola_resolucion(): void
    {
        $caso = $this->sembrar(false);
        $im = imagecreatetruecolor(400, 300);
        $archivo = $this->dir.DIRECTORY_SEPARATOR.'aporte.png';
        imagepng($im, $archivo);
        $sha = hash_file('sha256', $archivo);
        $script = $this->trabajador('$s->aportarPlantilla($caso, 77, \'Decisión administrativa de prueba.\', $archivo);');

        $r = collect($this->correrEnParalelo($script, $caso, $archivo))->pluck('resultado')->countBy()->all();

        $this->assertEquals(['ok' => 1, 'CASO_YA_RESUELTO' => 4], $r);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('accion', 'plantilla_manual_aportada')->count());
        $this->assertSame(1, DB::table('cf_plantillas_legado_contenidos')->where('sha256', $sha)->count());
        $this->assertSame(1, DB::table('cf_plantillas_legado')->where('ruta_original', 'like', 'manual://%')->count());
        $archivos = collect(File::allFiles($this->dir.DIRECTORY_SEPARATOR.'storage'))->map(fn ($f) => $f->getFilename())->all();
        $this->assertSame(['original.png'], $archivos);   // un solo archivo, sin temporales huérfanos
        $this->assertSame($sha, hash_file('sha256', File::allFiles($this->dir.DIRECTORY_SEPARATOR.'storage')[0]->getRealPath()));
        $this->assertSame(0, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_plantilla')->count());
    }

    public function test_una_resolucion_y_una_reversion_a_la_vez_no_corrompen_el_estado(): void
    {
        $caso = $this->sembrar(true);
        app(ResolucionPlantillas::class)->aprobarCandidata($caso, 77, 'Decisión administrativa de prueba.');
        $script = $this->trabajador('$s->revertir($caso, 77, \'Revierto la decisión de prueba.\');');

        $r = collect($this->correrEnParalelo($script, $caso, '', 4))->pluck('resultado')->countBy()->all();

        $this->assertEquals(['ok' => 1, 'NO_REVERSIBLE' => 3], $r);
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('accion', 'plantilla_resolucion_revertida')->count());
        $this->assertSame(3, DB::table('cf_certificados_legado')->where('conciliacion_estado', 'pendiente_plantilla')->whereNull('plantilla_legado_id')->count());
    }

    public function test_las_acciones_de_tipo_invalido_y_la_reversion_caben_en_el_esquema_real(): void
    {
        // Lo que SQLite no valida (largo de columnas) lo valida MySQL: «plantilla_renderizable_confirmada» tiene 33 caracteres.
        $ahora = now();
        $contenido = DB::table('cf_plantillas_legado_contenidos')->insertGetId(['sha256' => str_repeat('c', 64), 'bytes' => 60350, 'mime_real' => 'image/png', 'ancho_px' => 792, 'alto_px' => 612, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $entrada = DB::table('cf_plantillas_legado')->insertGetId(['contenido_id' => $contenido, 'ruta_original' => 'document/certImages/INVALIDA', 'nombre_original' => 'INVALIDA', 'nombre_normalizado' => 'invalida', 'extension_original' => 'texto invalido',
            'renderizable' => 0, 'motivo_no_renderizable' => 'TIPO_NO_SOPORTADO', 'estado' => 'extension_invalida', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento inválido', 'nombre_normalizado' => 'evento invalido', 'plantilla_legado_id' => $entrada, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cf_certificados_legado')->insert(['evento_id' => $evento, 'documento' => '9100001', 'documento_clave' => '9100001', 'nombre_completo' => 'PERSONA UNICA', 'codigo_legado' => '5100', 'conciliacion_estado' => 'pendiente_plantilla',
            'snapshot_legado' => json_encode(['migracion' => ['old_id' => 1], 'documento_estado' => 'valido', 'duplicado' => null]), 'created_at' => $ahora, 'updated_at' => $ahora]);
        app(DetectorConciliaciones::class)->ejecutar();
        $caso = (int) DB::table('cf_conciliaciones')->where('tipo', 'plantilla_tipo_invalido')->value('id');
        $s = app(ResolucionPlantillas::class);

        $s->confirmarRenderizable($caso, 77, 'Decisión administrativa de prueba.');
        $this->assertSame('plantilla_renderizable_confirmada', DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'));
        $this->assertSame(['extension_invalida', 'texto invalido', 0], [DB::table('cf_plantillas_legado')->find($entrada)->estado, DB::table('cf_plantillas_legado')->find($entrada)->extension_original, (int) DB::table('cf_plantillas_legado')->find($entrada)->renderizable]);
        $this->assertSame('ok', DB::table('cf_certificados_legado')->value('conciliacion_estado'));

        $s->revertir($caso, 77, 'Revierto la decisión de prueba.');
        $this->assertSame('plantilla_resolucion_revertida', DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'));
        $this->assertSame(['abierto', 'pendiente_plantilla'], [DB::table('cf_conciliaciones')->find($caso)->estado, DB::table('cf_certificados_legado')->value('conciliacion_estado')]);
        $this->assertNull(DB::table('cf_plantillas_legado')->find($entrada)->aprobada_por_conciliacion_id);
    }

    public function test_la_fk_impide_borrar_un_caso_con_una_aprobacion_vigente(): void
    {
        $caso = $this->sembrar(true);
        app(ResolucionPlantillas::class)->aprobarCandidata($caso, 77, 'Decisión administrativa de prueba.');

        $this->expectException(QueryException::class);
        DB::table('cf_conciliaciones')->where('id', $caso)->delete();
    }
}
