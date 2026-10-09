<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Conciliaciones\ConsolidacionVariantes;
use App\Support\CredentialFlow\Conciliaciones\GestionCaso;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Symfony\Component\Process\Process;
use Tests\TestCase;

/**
 * Consolidación de DIF_CORREO / variación cosmética de nombre y soporte / descarte (Fase 10B-2B-1) con CONCURRENCIA REAL contra MySQL/MariaDB:
 * cinco procesos intentan la MISMA acción sobre el MISMO caso. Debe quedar UNA decisión, UN evento y nada más. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/ConciliacionAdministrativaMysqlTest.php
 */
class ConciliacionAdministrativaMysqlTest extends TestCase
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
        'database/migrations/2026_10_10_100000_cf_encuestas_tables.php',
        'database/migrations/2026_10_12_100000_cf_conciliaciones_tables.php',
        'database/migrations/2026_10_13_100000_add_aprobada_por_conciliacion_to_cf_plantillas_legado.php',
        'database/migrations/2026_10_13_100100_widen_accion_in_cf_conciliaciones_eventos.php',
        'database/migrations/2026_10_14_100000_cf_codigos_historicos_tables.php',
    ];

    private const TABLAS = ['cf_codigos_historicos', 'cf_codigo_historico_contador', 'cf_conciliaciones_eventos', 'cf_conciliaciones_certificados', 'movimientos', 'cf_conciliaciones', 'cf_migraciones_map',
        'cf_encuestas_respuestas_detalle', 'cf_encuestas_respuestas', 'cf_encuestas_opciones', 'cf_encuestas_preguntas', 'cf_encuestas_versiones', 'cf_encuestas', 'cf_migraciones_corridas',
        'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

    private string $base = '';

    private string $dir = '';

    private int $corrida = 0;

    private int $plantilla = 0;

    private int $evento = 0;

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
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'concadm_mysql_'.bin2hex(random_bytes(6));
        mkdir($this->dir, 0777, true);

        $ahora = now();
        $this->corrida = DB::table('cf_migraciones_corridas')->insertGetId(['tipo' => 'legado_evaluaciones', 'snapshot_sha256' => str_repeat('a', 64), 'huella_global' => str_repeat('b', 64), 'huella_derivada' => str_repeat('c', 64), 'estado' => 'completada', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $this->plantilla = DB::table('cf_plantillas_legado')->insertGetId(['ruta_original' => 'document/certImages/A.png', 'nombre_original' => 'A.png', 'nombre_normalizado' => 'a', 'extension_original' => 'png', 'renderizable' => 1, 'estado' => 'ok', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $this->evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético', 'nombre_normalizado' => 'evento sintetico', 'plantilla_legado_id' => $this->plantilla, 'created_at' => $ahora, 'updated_at' => $ahora]);
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

    /** @return int id del certificado */
    private function cert(int $old, string $documento, ?string $nombre, string $estado, array $snap, ?string $grupo = null): int
    {
        $ahora = now();
        $id = DB::table('cf_certificados_legado')->insertGetId([
            'corrida_id' => $this->corrida, 'evento_id' => $this->evento, 'plantilla_legado_id' => $this->plantilla, 'tipo_documento' => 'CC', 'documento' => $documento, 'documento_clave' => $documento,
            'nombre_completo' => $nombre ?? '', 'codigo_legado' => null, 'conciliacion_estado' => $estado, 'grupo_duplicado' => $grupo, 'estado' => 'vigente',
            'snapshot_legado' => json_encode(['migracion' => ['old_id' => $old], 'documento_estado' => 'valido'] + $snap), 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        DB::table('cf_migraciones_map')->insert(['corrida_id' => $this->corrida, 'origen_tabla' => 'participante', 'origen_id' => (string) $old, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $id,
            'relacion' => $grupo ? 'variante_conflictiva' : 'unico', 'created_at' => $ahora, 'updated_at' => $ahora]);

        return $id;
    }

    private function correo(int $cert, string $correo): void
    {
        $ahora = now();
        DB::table('cf_correos')->insert(['certificado_legado_id' => $cert, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora]);
    }

    private function caso(string $tipo, string $clave, array $certs, string $origen): int
    {
        $ahora = now();
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => $tipo, 'estado' => 'abierto', 'evento_id' => $this->evento, 'referencia_tipo' => $tipo === 'revision_documento' ? 'certificado_legado' : 'grupo_duplicado', 'referencia_clave' => $clave,
            'motivo_origen' => $origen, 'clave_idempotencia' => $tipo.':'.$clave, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach ($certs as $id) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $id, 'rol' => 'variante', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'detectado', 'created_at' => $ahora]);

        return $caso;
    }

    private function sembrarCorreo(): int
    {
        $dup = fn (string $e) => ['duplicado' => ['clasificacion' => 'conflictivo', 'etiquetas' => $e, 'canonico_old_id' => 1]];
        $a = $this->cert(1, '9300001', 'PERSONA UNO', 'pendiente_conciliacion', $dup('DIF_CORREO'), 'dup-c');
        $b = $this->cert(2, '9300001', 'PERSONA UNO', 'pendiente_conciliacion', $dup('DIF_CORREO'), 'dup-c');
        $this->correo($a, 'uno@example.test');
        $this->correo($b, 'dos@example.test');

        return $this->caso('conflicto_variantes', 'dup-c', [$a, $b], 'DIF_CORREO');
    }

    private function sembrarCosmetico(): array
    {
        $dup = fn (string $e) => ['duplicado' => ['clasificacion' => 'conflictivo', 'etiquetas' => $e, 'canonico_old_id' => 3]];
        $a = $this->cert(3, '9300002', 'María Pérez', 'pendiente_conciliacion', $dup('DIF_NOMBRE'), 'dup-n');
        $b = $this->cert(4, '9300002', 'MARIA PEREZ', 'pendiente_conciliacion', $dup('DIF_NOMBRE'), 'dup-n');
        $this->correo($a, 'mp@example.test');
        $this->correo($b, 'mp@example.test');

        return [$this->caso('conflicto_variantes', 'dup-n', [$a, $b], 'DIF_NOMBRE'), $b];
    }

    private function sembrarDocumento(string $nombre, string $clave, int $old): int
    {
        $id = $this->cert($old, '', $nombre, 'revision_documento', ['motivos' => 'DOC_VACIO', 'documento_estado' => 'vacio']);

        return $this->caso('revision_documento', $clave, [$id], 'DOC_VACIO');
    }

    /** Lanza cinco procesos con la misma acción y devuelve sus resultados. @return list<array<string,mixed>> */
    private function concurrente(string $llamada, int $caso): array
    {
        $raiz = var_export(base_path(), true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $codigo = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        \$caso = (int) \$argv[1];
        while (! file_exists({$go})) { usleep(2000); }
        try {
            {$llamada};
            echo json_encode(['resultado' => 'ok']);
        } catch (App\\Support\\CredentialFlow\\Conciliaciones\\ResolucionNoPermitida \$e) {
            echo json_encode(['resultado' => \$e->codigo, 'mensaje' => \$e->getMessage()]);
        } catch (Throwable \$e) {
            echo json_encode(['resultado' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 160)]);
        }
        PHP;
        $script = $this->dir.DIRECTORY_SEPARATOR.'trabajador_'.bin2hex(random_bytes(3)).'.php';
        file_put_contents($script, $codigo);
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');

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

        return $salidas;
    }

    private function unaSolaDecision(array $salidas, int $caso, string $accion): void
    {
        $this->assertEquals(['ok' => 1, 'CASO_YA_RESUELTO' => 4], collect($salidas)->pluck('resultado')->countBy()->all(), json_encode($salidas));
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', $accion)->count());
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->count());
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'conciliacion')->count());
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(50000, (int) DB::table('cf_codigo_historico_contador')->value('siguiente'));
    }

    public function test_cinco_procesos_consolidando_dif_correo_producen_una_sola_resolucion(): void
    {
        $caso = $this->sembrarCorreo();

        $s = $this->concurrente('app(App\Support\CredentialFlow\Conciliaciones\ConsolidacionVariantes::class)->consolidar($caso, 77, \'Las variantes solo difieren en el correo.\', \'correo\')', $caso);

        $this->unaSolaDecision($s, $caso, 'conflicto_correo_consolidado');
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', 'correo_consolidado', 77], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $this->assertEqualsCanonicalizing(['ok', 'duplicado_consolidado'], DB::table('cf_certificados_legado')->pluck('conciliacion_estado')->all());
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->count());
        $this->assertSame(2, DB::table('cf_migraciones_map')->where('relacion', 'variante_conflictiva')->count());
        $this->assertSame(2, DB::table('cf_correos')->count());
    }

    public function test_cinco_procesos_consolidando_la_variacion_cosmetica_producen_una_sola_resolucion(): void
    {
        [$caso, $canonica] = $this->sembrarCosmetico();

        $s = $this->concurrente("app(App\\Support\\CredentialFlow\\Conciliaciones\\ConsolidacionVariantes::class)->consolidar(\$caso, 77, 'Solo cambian tildes y mayúsculas.', 'nombre_cosmetico', {$canonica})", $caso);

        $this->unaSolaDecision($s, $caso, 'conflicto_nombre_cosmetico_consolidado');
        $this->assertSame('nombre_cosmetico_consolidado', DB::table('cf_conciliaciones')->find($caso)->resolucion);
        $this->assertSame('ok', DB::table('cf_certificados_legado')->where('id', $canonica)->value('conciliacion_estado'));
        $this->assertEqualsCanonicalizing(['María Pérez', 'MARIA PEREZ'], DB::table('cf_certificados_legado')->pluck('nombre_completo')->all());
    }

    public function test_cinco_procesos_marcando_requiere_soporte_producen_una_sola_decision(): void
    {
        $caso = $this->sembrarDocumento('PERSONA SIN DOCUMENTO', 'doc-1', 5);

        $s = $this->concurrente('app(App\\Support\\CredentialFlow\\Conciliaciones\\GestionCaso::class)->marcarRequiereSoporte($caso, 77, \'No hay evidencia para decidir.\')', $caso);

        $this->unaSolaDecision($s, $caso, GestionCaso::ACCION_SOPORTE);
        $this->assertSame('requiere_soporte', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame('revision_documento', DB::table('cf_certificados_legado')->value('conciliacion_estado'));
    }

    public function test_cinco_procesos_descartando_producen_una_sola_decision_y_no_borran_nada(): void
    {
        $caso = $this->sembrarDocumento('', 'doc-2', 6);

        $s = $this->concurrente('app(App\\Support\\CredentialFlow\\Conciliaciones\\GestionCaso::class)->descartar($caso, 77, \'No hay ningún dato útil.\')', $caso);

        $this->unaSolaDecision($s, $caso, GestionCaso::ACCION_DESCARTE);
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['descartado', GestionCaso::RESOLUCION_DESCARTE], [$f->estado, $f->resolucion]);
        $this->assertSame(1, DB::table('cf_certificados_legado')->count());
    }

    public function test_la_reversion_y_la_reapertura_funcionan_con_el_esquema_real(): void
    {
        $caso = $this->sembrarCorreo();
        $v = app(ConsolidacionVariantes::class);
        $v->consolidar($caso, 77, 'Las variantes solo difieren en el correo.', ConsolidacionVariantes::MODO_CORREO);
        $v->revertir($caso, 77, 'Revierto la consolidación de prueba.');
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($caso)->estado);
        $this->assertSame(['pendiente_conciliacion', 'pendiente_conciliacion'], DB::table('cf_certificados_legado')->orderBy('id')->pluck('conciliacion_estado')->all());
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('origen_tabla', 'conciliacion')->count());

        $doc = $this->sembrarDocumento('', 'doc-3', 7);
        $g = app(GestionCaso::class);
        $g->descartar($doc, 77, 'No hay ningún dato útil.');
        $g->reabrir($doc, 77, 'Reabro para revisarlo de nuevo.');
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($doc)->estado);
        $this->assertSame(GestionCaso::ACCION_REABIERTA, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $doc)->orderByDesc('id')->value('accion'));
    }
}
