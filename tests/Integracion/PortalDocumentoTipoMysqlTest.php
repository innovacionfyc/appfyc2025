<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\EmisionesModernas;
use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Búsqueda por la FAMILIA del número (con y sin prefijo de tipo) contra MySQL/MariaDB real: histórico «9100001» + participante importado como «CC9100001»
 * (misma persona: se ven juntos), C.C./T.I. del mismo número y nombre (ambiguo: no se une), ceros a la izquierda (no se unen) y homónimos. Datos 100 %
 * sintéticos. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/PortalDocumentoTipoMysqlTest.php
 */
class PortalDocumentoTipoMysqlTest extends TestCase
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

    private const TABLAS = ['cf_codigos_historicos', 'cf_codigo_historico_contador', 'cf_emisiones', 'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos',
        'cf_migraciones_map', 'cf_migraciones_corridas', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

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

    public function test_familia_del_numero_tipos_ambiguos_ceros_y_homonimos_con_mariadb_estricto(): void
    {
        $a = now()->toDateTimeString();
        $plantilla = DB::table('cf_plantillas')->insertGetId(['nombre' => 'P', 'archivo_pdf' => 'x.pdf', 'nombre_archivo_original' => 'x.pdf', 'hash_sha256' => str_repeat('a', 64), 'created_at' => $a, 'updated_at' => $a]);
        $lote = DB::table('cf_lotes')->insertGetId(['plantilla_id' => $plantilla, 'nombre' => 'L', 'datos_comunes' => '{}', 'created_at' => $a, 'updated_at' => $a]);
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'Taller sintético', 'nombre_normalizado' => 'taller sintetico', 'anio' => 2024, 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a]);
        $n = 0;
        $emitir = function (string $nombre, string $clave, string $correo) use ($lote, $plantilla, $a, &$n) {
            $n++;
            $p = DB::table('cf_participantes')->insertGetId(['lote_id' => $lote, 'nombre_completo' => $nombre, 'documento' => $clave, 'documento_clave' => $clave, 'correo' => $correo, 'correo_normalizado' => $correo, 'correo_estado' => 'valido', 'created_at' => $a, 'updated_at' => $a]);

            return DB::table('cf_emisiones')->insertGetId([
                'codigo' => 'CF'.str_pad((string) $n, 18, '0', STR_PAD_LEFT), 'participante_id' => $p, 'lote_id' => $lote, 'plantilla_id' => $plantilla, 'version' => 1, 'estado' => 'emitida',
                'participante_vigente' => $p, 'datos_snapshot' => json_encode(['evento' => 'Seminario sintético', 'fecha' => '15 de octubre de 2026']), 'diseno_snapshot' => '{}', 'schema_version' => 1,
                'plantilla_pdf_hash' => str_repeat('b', 64), 'generador_snapshot' => '{}', 'pdf_archivo' => 'x', 'pdf_hash' => str_repeat('c', 64), 'pdf_bytes' => 1, 'emitido_at' => $a, 'created_at' => $a, 'updated_at' => $a,
            ]);
        };
        $historico = function (string $nombre, string $clave, ?string $tipo, string $correo) use ($evento, $a) {
            $id = DB::table('cf_certificados_legado')->insertGetId(['evento_id' => $evento, 'tipo_documento' => $tipo, 'documento' => $clave, 'documento_clave' => $clave, 'nombre_completo' => $nombre,
                'correo' => $correo, 'correo_normalizado' => $correo, 'correo_estado' => 'valido', 'snapshot_legado' => '{}', 'estado' => 'vigente', 'conciliacion_estado' => 'ok', 'created_at' => $a, 'updated_at' => $a]);
            DB::table('cf_correos')->insert(['certificado_legado_id' => $id, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => $a, 'updated_at' => $a]);

            return $id;
        };

        // Misma persona: histórico sin prefijo (tipo C.C. en el sistema anterior) + certificado nuevo importado con «C.C.».
        $hAna = $historico('ANA PRUEBA UNO', '9100001', 'CC', 'ana@example.test');
        $mAna = $emitir('ANA PRUEBA UNO', 'CC9100001', 'ana@example.test');
        // Homónimo con el mismo número, guardado con «T.I.» y su propio correo.
        $mOtra = $emitir('OTRA PERSONA DISTINTA', 'TI9100001', 'otra@example.test');
        // Mismo número y nombre con C.C. y T.I.: ambiguo.
        $emitir('PERSONA DOBLE TIPO', 'CC9100002', 'doble@example.test');
        $emitir('PERSONA DOBLE TIPO', 'TI9100002', 'doble@example.test');
        // Ceros a la izquierda: otro número.
        $mCero = $emitir('ANA PRUEBA UNO', 'CC09100001', 'ana@example.test');

        $acceso = new AccesoPortal;
        $gAna = NombreConservador::grupoId('ANA PRUEBA UNO');
        foreach (['9100001', 'CC9100001', 'C.C. 9.100.001'] as $digitado) {
            $clave = AccesoPortal::claveDocumento($digitado);
            $this->assertSame('9100001', $clave);
            $this->assertSame(['grupo' => $gAna], $acceso->alcance($clave, 'ana@example.test'));
            $this->assertSame([$mAna], array_column($acceso->tarjetasModernas($clave, $gAna), 'id'));
            $this->assertSame([$hAna], array_column($acceso->tarjetas($clave, $gAna), 'id'));
        }
        $this->assertTrue(AccesoPortal::perteneceA(CertificadoLegado::findOrFail($hAna), '9100001', [$gAna]));
        $this->assertNull($acceso->emisionModerna('9100001', $gAna, $mOtra));
        $this->assertNull($acceso->emisionModerna('9100001', $gAna, $mCero));
        $this->assertNull($acceso->emisionModerna('9100001', null, $mAna), 'dos grupos: documento completo falla cerrado');
        $this->assertSame([], $acceso->tarjetas('9100001', []));
        $this->assertNull($acceso->alcance('9100001', 'doble@example.test'));

        $this->assertSame(1, FamiliaDocumento::excluidos('9100002')['unidades']);
        $this->assertCount(0, EmisionesModernas::filas('9100002'));
        $this->assertNull($acceso->alcance('9100002', 'doble@example.test'));

        $this->assertSame(['grupo' => null], $acceso->alcance('09100001', 'ana@example.test'));
        $this->assertSame([$mCero], array_column($acceso->tarjetasModernas('09100001', null), 'id'));
        $this->assertSame(0, FamiliaDocumento::excluidos('9100001')['unidades']);
    }
}
