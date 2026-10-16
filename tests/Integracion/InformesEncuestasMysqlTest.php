<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Encuestas\InformeEncuestas;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Informes de encuestas (Fase 15A) contra MySQL/MariaDB real con ONLY_FULL_GROUP_BY: agrupación mensual (DATE_FORMAT), conteos por evento,
 * participaciones únicas, paginación y filtros combinados. Datos 100 % sintéticos. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/InformesEncuestasMysqlTest.php
 */
class InformesEncuestasMysqlTest extends TestCase
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
        'database/migrations/2026_10_10_100000_cf_encuestas_tables.php',
    ];

    private const TABLAS = ['cf_encuestas_respuestas_detalle', 'cf_encuestas_respuestas', 'cf_encuestas_opciones', 'cf_encuestas_preguntas', 'cf_encuestas_versiones', 'cf_encuestas',
        'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_migraciones_map', 'cf_migraciones_corridas',
        'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');
        if ($base === '' || ! preg_match('/_(dev|test|testing)$/', $base) || $base === ($config['database'] ?? '')
            || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }
        config([
            'database.connections.'.self::CONEXION => [...$config, 'driver' => (string) (getenv('TEST_MYSQL_DRIVER') ?: ($config['driver'] ?? 'mysql')), 'database' => $base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);

        $this->limpiar();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRACIONES, '--force' => true]), Artisan::output());
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

    public function test_las_consultas_de_informes_funcionan_con_only_full_group_by_y_dan_los_conteos_esperados(): void
    {
        $this->assertStringContainsString('ONLY_FULL_GROUP_BY', (string) DB::selectOne('select @@sql_mode as m')->m, 'La base de pruebas debe usar el modo estricto de producción.');
        $a = now()->toDateTimeString();
        $enc = DB::table('cf_encuestas')->insertGetId(['nombre' => 'Encuesta', 'estado' => 'publicada', 'activa' => true, 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a]);
        $ver = fn (int $n) => DB::table('cf_encuestas_versiones')->insertGetId(['encuesta_id' => $enc, 'numero' => $n, 'titulo' => "V{$n}", 'publicada_at' => $a, 'activa_desde' => $a, 'created_at' => $a, 'updated_at' => $a]);
        $v = [1 => $ver(1), 4 => $ver(4)];
        $pregunta = DB::table('cf_encuestas_preguntas')->insertGetId(['version_id' => $v[4], 'orden' => 1, 'clave' => 'pregunta1', 'texto' => 'Calidad', 'tipo' => 'opcion_unica', 'created_at' => $a, 'updated_at' => $a]);
        $opcion = DB::table('cf_encuestas_opciones')->insertGetId(['pregunta_id' => $pregunta, 'orden' => 1, 'valor' => 'Bueno', 'etiqueta' => 'Bueno', 'created_at' => $a, 'updated_at' => $a]);
        $evento = fn (string $n, int $anio) => DB::table('cf_eventos')->insertGetId(['nombre' => $n, 'nombre_normalizado' => mb_strtolower($n), 'anio' => $anio, 'fecha_inicio' => "{$anio}-03-01", 'fecha_fin' => "{$anio}-03-01", 'estado' => 'activo', 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a]);
        $e1 = $evento('Evento Uno', 2024);
        $e2 = $evento('Evento Dos', 2025);
        $resp = function (int $ver, ?int $ev, string $hash, string $fecha, string $origen) use ($v, $pregunta, $opcion, $a) {
            $id = DB::table('cf_encuestas_respuestas')->insertGetId(['version_id' => $v[$ver], 'evento_id' => $ev, 'documento_hash' => str_pad($hash, 64, '0'), 'completada_at' => $fecha, 'origen' => $origen, 'created_at' => $a, 'updated_at' => $a]);
            if ($ver === 4) {
                DB::table('cf_encuestas_respuestas_detalle')->insert(['respuesta_id' => $id, 'pregunta_id' => $pregunta, 'valor_texto' => 'Bueno', 'opcion_id' => $opcion, 'snapshot_pregunta' => '{}', 'snapshot_opcion' => 'Bueno', 'created_at' => $a, 'updated_at' => $a]);
            }
        };
        $resp(1, $e1, 'a', '2024-03-15 10:00:00', 'legado');
        $resp(1, $e1, 'a', '2024-03-16 10:00:00', 'legado'); // duplicado histórico: cuenta como respuesta, no como persona
        $resp(1, $e1, 'b', '2024-04-02 10:00:00', 'legado');
        $resp(1, null, 'c', '2024-04-03 10:00:00', 'legado'); // evento eliminado
        $resp(4, $e2, 'a', '2026-10-16 09:00:00', 'credential_flow');
        $resp(4, $e2, 'b', '2026-10-17 09:00:00', 'credential_flow');

        $informe = new InformeEncuestas;
        $todos = InformeEncuestas::filtros([]);
        $r = $informe->resumen($todos);
        $this->assertSame(6, $r['respuestas']);
        $this->assertSame(5, $r['participaciones_unicas']); // (e1,a) (e1,b) (e2,a) (e2,b) + la persona del evento eliminado
        $this->assertSame(2, $r['eventos_evaluados']);
        $this->assertSame(['2024-03', '2024-04', '2026-10'], array_column($r['por_mes'], 'mes'));
        $this->assertSame([2, 2, 2], array_column($r['por_mes'], 'respuestas'));

        $pagina = $informe->eventos($todos, 1);
        $this->assertSame(2, $pagina->total());
        $this->assertSame([3, 2], collect($pagina->items())->pluck('respuestas')->sortDesc()->values()->all());

        $combo = $informe->resumen(InformeEncuestas::filtros(['anio' => 2025, 'origen' => 'credential_flow', 'version' => $v[4], 'resp_desde' => '2026-10-01']));
        $this->assertSame(2, $combo['respuestas']);
        $this->assertSame(0, $informe->resumen(InformeEncuestas::filtros(['anio' => 1999]))['respuestas']);
        $this->assertSame(1, $informe->resumen(InformeEncuestas::filtros(['evento' => InformeEncuestas::SIN_EVENTO]))['respuestas']);
        $this->assertGreaterThan(0, count(iterator_to_array($informe->filasDistribucion($todos), false)));
    }
}
