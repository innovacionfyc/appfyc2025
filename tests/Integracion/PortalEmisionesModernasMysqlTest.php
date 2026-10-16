<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Portal\AccesoPortal;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

/**
 * Consultas del portal para certificados MODERNOS (documento + correo + grupo de nombre) contra MySQL/MariaDB real: un acceso válido, un correo
 * incorrecto, un homónimo con el mismo documento y la descarga autorizada solo por documento + grupo. Datos 100 % sintéticos. Solo con una base
 * local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/PortalEmisionesModernasMysqlTest.php
 */
class PortalEmisionesModernasMysqlTest extends TestCase
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
    ];

    private const TABLAS = ['cf_emisiones', 'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_migraciones_map', 'cf_migraciones_corridas',
        'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

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

    public function test_acceso_valido_correo_incorrecto_y_homonimos_con_mariadb_estricto(): void
    {
        $a = now()->toDateTimeString();
        $plantilla = DB::table('cf_plantillas')->insertGetId(['nombre' => 'P', 'archivo_pdf' => 'x.pdf', 'nombre_archivo_original' => 'x.pdf', 'hash_sha256' => str_repeat('a', 64), 'created_at' => $a, 'updated_at' => $a]);
        $lote = DB::table('cf_lotes')->insertGetId(['plantilla_id' => $plantilla, 'nombre' => 'L', 'datos_comunes' => '{}', 'created_at' => $a, 'updated_at' => $a]);
        $n = 0;
        $emitir = function (string $nombre, string $clave, string $correo, string $estado = 'emitida') use ($lote, $plantilla, $a, &$n) {
            $n++;
            $p = DB::table('cf_participantes')->insertGetId(['lote_id' => $lote, 'nombre_completo' => $nombre, 'documento' => $clave, 'documento_clave' => $clave, 'correo' => $correo, 'correo_normalizado' => $correo, 'correo_estado' => 'valido', 'created_at' => $a, 'updated_at' => $a]);
            DB::table('cf_correos')->insert(['participante_id' => $p, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'credential_flow', 'created_at' => $a, 'updated_at' => $a]);

            return DB::table('cf_emisiones')->insertGetId([
                'codigo' => 'CF'.str_pad((string) $n, 18, '0', STR_PAD_LEFT), 'participante_id' => $p, 'lote_id' => $lote, 'plantilla_id' => $plantilla, 'version' => 1, 'estado' => $estado,
                'participante_vigente' => $estado === 'emitida' ? $p : null, 'datos_snapshot' => json_encode(['evento' => 'Seminario sintético', 'fecha' => '15 y 16 de octubre de 2026']), 'diseno_snapshot' => '{}', 'schema_version' => 1,
                'plantilla_pdf_hash' => str_repeat('b', 64), 'generador_snapshot' => '{}', 'pdf_archivo' => 'x', 'pdf_hash' => str_repeat('c', 64), 'pdf_bytes' => 1, 'emitido_at' => $a, 'created_at' => $a, 'updated_at' => $a,
            ]);
        };
        $ana = $emitir('ANA PRUEBA UNO', '9100001', 'ana@example.test');
        $otra = $emitir('OTRA PERSONA DISTINTA', '9100001', 'otra@example.test');   // homónimo: mismo documento, otro nombre y otro correo
        $solo = $emitir('PERSONA SOLA', '9100002', 'sola@example.test');
        $emitir('PERSONA REVOCADA', '9100003', 'rev@example.test', 'revocada');

        $acceso = new AccesoPortal;
        $gAna = NombreConservador::grupoId('ANA PRUEBA UNO');
        $gOtra = NombreConservador::grupoId('OTRA PERSONA DISTINTA');

        // Acceso válido (sin homónimo): documento completo.
        $this->assertSame(['grupo' => null], $acceso->alcance('9100002', 'sola@example.test'));
        // Correo incorrecto o de otra persona: sin acceso.
        $this->assertNull($acceso->alcance('9100002', 'ana@example.test'));
        $this->assertNull($acceso->alcance('9100002', 'cualquiera@example.test'));
        // Homónimos: cada correo identifica EXACTAMENTE su grupo; un correo ajeno no abre ninguno.
        $this->assertSame(['grupo' => $gAna], $acceso->alcance('9100001', 'ana@example.test'));
        $this->assertSame(['grupo' => $gOtra], $acceso->alcance('9100001', 'otra@example.test'));
        $this->assertNull($acceso->alcance('9100001', 'sola@example.test'));
        // Solo revocada: no habilita OTP.
        $this->assertNull($acceso->alcance('9100003', 'rev@example.test'));

        // Tarjetas y descarga: solo dentro del grupo autorizado.
        $this->assertSame([$ana], array_column($acceso->tarjetasModernas('9100001', $gAna), 'id'));
        $this->assertSame([$otra], array_column($acceso->tarjetasModernas('9100001', $gOtra), 'id'));
        $this->assertNull($acceso->emisionModerna('9100001', $gAna, $otra));
        $this->assertNotNull($acceso->emisionModerna('9100001', $gAna, $ana));
        $this->assertNull($acceso->emisionModerna('9100001', [], $ana));    // un scope vacío nunca es «documento completo»
        $this->assertNull($acceso->emisionModerna('9100001', null, $ana));  // documento con dos grupos: la sesión de documento completo falla cerrada
        $this->assertSame([$solo], array_column($acceso->tarjetasModernas('9100002', null), 'id'));
        $this->assertSame(['revocado'], array_column($acceso->tarjetasModernas('9100003', null), 'tipo'));
        $this->assertCount(1, $acceso->tarjetasPaginadas('9100002', null)['items']);
    }
}
