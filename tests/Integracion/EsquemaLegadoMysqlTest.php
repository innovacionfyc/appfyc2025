<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\Correo;
use App\Services\CredentialFlow\EliminacionDefinitivaService;
use App\Support\CredentialFlow\Eliminacion\EliminacionException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Fase 1 y 1.1 del modelo aditivo contra MySQL/MariaDB REAL: los CHECK de cf_descargas y cf_correos, los UNIQUE, las FK
 * RESTRICT y el rollback.
 *
 * Solo se ejecuta si se indica una base local de pruebas (nunca la de desarrollo):
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/EsquemaLegadoMysqlTest.php
 *
 * Para probar contra otro servidor local (p. ej. una MariaDB 10.6 desechable) basta con apuntar el proceso a él con
 * DB_HOST, DB_PORT, DB_USERNAME y DB_PASSWORD, y opcionalmente TEST_MYSQL_DRIVER=mariadb.
 *
 * Crea las tablas de Credential Flow en esa base, las usa y las elimina al terminar.
 */
class EsquemaLegadoMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private const BASE = [
        'database/migrations/2026_09_29_160000_cf_plantillas_table.php',
        'database/migrations/2026_09_30_100000_cf_lotes_table.php',
        'database/migrations/2026_09_30_100100_cf_participantes_table.php',
        'database/migrations/2026_09_30_200000_cf_emisiones_table.php',
    ];

    private const FASE_1 = [
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

    private const TABLAS = ['movimientos', 'cf_migraciones_map', 'cf_migraciones_corridas', 'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');

        if ($base === '' || ! preg_match('/_(dev|test|testing)$/', $base) || $base === ($config['database'] ?? '')
            || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }

        // TEST_MYSQL_DRIVER=mariadb ejecuta las mismas pruebas con la conexión MariaDbConnection de Laravel.
        $driver = (string) getenv('TEST_MYSQL_DRIVER');
        if ($driver !== '' && ! in_array($driver, ['mysql', 'mariadb'], true)) {
            $this->markTestSkipped('TEST_MYSQL_DRIVER debe ser mysql o mariadb.');
        }

        config([
            'database.connections.'.self::CONEXION => [...$config, 'driver' => $driver ?: ($config['driver'] ?? 'mysql'), 'database' => $base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);

        $this->limpiar();
        $this->migrar(self::BASE);
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
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLAS as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @param array<int,string> $rutas */
    private function migrar(array $rutas): void
    {
        $this->assertSame(0, Artisan::call('migrate', ['--path' => $rutas, '--force' => true]), Artisan::output());
    }

    private function emisionId(): int
    {
        $plantilla = DB::table('cf_plantillas')->insertGetId([
            'nombre' => 'P', 'archivo_pdf' => 'x', 'nombre_archivo_original' => 'x.pdf', 'hash_sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lote = DB::table('cf_lotes')->insertGetId(['plantilla_id' => $plantilla, 'nombre' => 'L', 'datos_comunes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $participante = DB::table('cf_participantes')->insertGetId([
            'lote_id' => $lote, 'nombre_completo' => 'ANA', 'documento' => '1', 'documento_clave' => '1', 'created_at' => now(), 'updated_at' => now(),
        ]);

        return DB::table('cf_emisiones')->insertGetId([
            'codigo' => str_repeat('A', 20), 'participante_id' => $participante, 'lote_id' => $lote, 'plantilla_id' => $plantilla, 'version' => 1,
            'estado' => 'emitida', 'participante_vigente' => $participante, 'datos_snapshot' => '{}', 'diseno_snapshot' => '{}', 'schema_version' => 1,
            'plantilla_pdf_hash' => str_repeat('a', 64), 'generador_snapshot' => '{}', 'pdf_archivo' => 'credential-flow/emisiones/aa/x.pdf',
            'pdf_hash' => str_repeat('b', 64), 'pdf_bytes' => 10,
        ]);
    }

    private function certificadoId(): int
    {
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'E', 'nombre_normalizado' => 'e', 'created_at' => now(), 'updated_at' => now()]);

        return DB::table('cf_certificados_legado')->insertGetId([
            'evento_id' => $evento, 'documento_clave' => '1', 'nombre_completo' => 'ANA', 'snapshot_legado' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);
    }

    public function test_el_check_de_cf_descargas_acepta_un_origen_y_rechaza_ninguno_o_ambos(): void
    {
        $this->migrar(self::FASE_1);
        $emision = $this->emisionId();
        $certificado = $this->certificadoId();

        // Exactamente uno: se acepta.
        DB::table('cf_descargas')->insert(['emision_id' => $emision, 'via' => 'portal']);
        DB::table('cf_descargas')->insert(['certificado_legado_id' => $certificado, 'via' => 'portal']);
        $this->assertSame(2, DB::table('cf_descargas')->count());

        // Ninguno y ambos: lo rechaza la base de datos aunque se salte el modelo.
        foreach (['ninguno' => ['via' => 'portal'], 'ambos' => ['emision_id' => $emision, 'certificado_legado_id' => $certificado, 'via' => 'portal']] as $caso => $fila) {
            try {
                DB::table('cf_descargas')->insert($fila);
                $this->fail("El CHECK debía rechazar una descarga con $caso origen");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/cf_descargas_un_origen_chk|check constraint/i', $e->getMessage());
            }
        }
        // Un UPDATE que deje ambos también se rechaza.
        try {
            DB::table('cf_descargas')->where('emision_id', $emision)->update(['certificado_legado_id' => $certificado]);
            $this->fail('El CHECK debía rechazar el UPDATE');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }
        $this->assertSame(2, DB::table('cf_descargas')->count());
    }

    /** @return array<string,array{0:bool,1:bool,2:bool}> emision, legado, aceptado */
    public static function origenesDeDescarga(): array
    {
        return [
            'NULL / NULL: rechazado' => [false, false, false],
            'emisión / NULL: aceptado' => [true, false, true],
            'NULL / legado: aceptado' => [false, true, true],
            'emisión / legado: rechazado' => [true, true, false],
        ];
    }

    #[DataProvider('origenesDeDescarga')]
    public function test_los_cuatro_escenarios_del_check(bool $conEmision, bool $conLegado, bool $aceptado): void
    {
        $this->migrar(self::FASE_1);
        $emision = $this->emisionId();
        $certificado = $this->certificadoId();

        $fila = ['via' => 'portal'] + ($conEmision ? ['emision_id' => $emision] : []) + ($conLegado ? ['certificado_legado_id' => $certificado] : []);

        if ($aceptado) {
            DB::table('cf_descargas')->insert($fila);
            $this->assertSame(1, DB::table('cf_descargas')->count());
        } else {
            $this->expectException(QueryException::class);
            DB::table('cf_descargas')->insert($fila);
        }
    }

    public function test_el_check_tiene_la_expresion_exacta(): void
    {
        $this->migrar(self::FASE_1);

        $create = DB::selectOne('SHOW CREATE TABLE cf_descargas')->{'Create Table'};

        $this->assertStringContainsString('CONSTRAINT `cf_descargas_un_origen_chk` CHECK', $create);
        // MySQL y MariaDB normalizan los paréntesis y las comillas de forma distinta: se compara sin ellos.
        $normalizada = strtolower(str_replace(['(', ')', '`'], '', substr($create, (int) strpos($create, 'CHECK'))));
        $this->assertStringContainsString('emision_id is not null and certificado_legado_id is null or emision_id is null and certificado_legado_id is not null', $normalizada);
    }

    public function test_unique_sha256_rechaza_repetidos_y_admite_varios_null_y_codigo_legado_se_repite(): void
    {
        $this->migrar(self::FASE_1);
        $contenido = fn (string $sha) => ['sha256' => $sha, 'bytes' => 1, 'created_at' => now(), 'updated_at' => now()];
        $entrada = fn (string $nombre, array $extra = []) => $extra + [
            'ruta_original' => 'document/certImages/'.$nombre, 'nombre_original' => $nombre, 'nombre_normalizado' => strtolower($nombre),
            'estado' => 'ok', 'created_at' => now(), 'updated_at' => now(),
        ];

        // Varios NOMBRES sin contenido (faltante/huérfana) conviven.
        DB::table('cf_plantillas_legado')->insert($entrada('a.png', ['estado' => 'faltante']));
        DB::table('cf_plantillas_legado')->insert($entrada('b.png', ['estado' => 'faltante']));
        DB::table('cf_plantillas_legado')->insert($entrada('c.png', ['estado' => 'huerfana']));
        $this->assertSame(3, DB::table('cf_plantillas_legado')->whereNull('contenido_id')->count());

        // El mismo contenido (SHA) dos veces, no; pero VARIOS nombres pueden apuntar a UN contenido.
        $id = DB::table('cf_plantillas_legado_contenidos')->insertGetId($contenido(str_repeat('a', 64)));
        try {
            DB::table('cf_plantillas_legado_contenidos')->insert($contenido(str_repeat('a', 64)));
            $this->fail('El UNIQUE de sha256 debía rechazar el duplicado');
        } catch (QueryException $e) {
            $this->assertMatchesRegularExpression('/duplicate entry|sha256/i', $e->getMessage());
        }
        DB::table('cf_plantillas_legado')->insert($entrada('d.png', ['contenido_id' => $id]));
        DB::table('cf_plantillas_legado')->insert($entrada('e.png', ['contenido_id' => $id]));
        $this->assertSame(2, DB::table('cf_plantillas_legado')->where('contenido_id', $id)->count());

        // Nombres que solo difieren en mayúsculas o tildes SON distintos (collation binaria de ruta_original) y el mismo no se repite.
        DB::table('cf_plantillas_legado')->insert($entrada('AGO - Ética.png'));
        DB::table('cf_plantillas_legado')->insert($entrada('AGO - ETICA.png'));
        try {
            DB::table('cf_plantillas_legado')->insert($entrada('AGO - Ética.png'));
            $this->fail('La ruta original exacta debía ser única');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // RESTRICT: un contenido con nombres no se puede borrar.
        try {
            DB::table('cf_plantillas_legado_contenidos')->where('id', $id)->delete();
            $this->fail('La FK RESTRICT debía impedirlo');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        // codigo_legado repetido: permitido (sin UNIQUE).
        $evento = DB::table('cf_eventos')->insertGetId(['nombre' => 'E', 'nombre_normalizado' => 'e', 'created_at' => now(), 'updated_at' => now()]);
        foreach (['A', 'B'] as $clave) {
            DB::table('cf_certificados_legado')->insert([
                'evento_id' => $evento, 'documento_clave' => $clave, 'nombre_completo' => $clave, 'codigo_legado' => '5237', 'snapshot_legado' => '{}',
                'created_at' => now(), 'updated_at' => now(),
            ]);
        }
        $this->assertSame(2, DB::table('cf_certificados_legado')->where('codigo_legado', '5237')->count());
    }

    public function test_las_fk_restrict_impiden_borrar_lo_referenciado_y_participante_se_anula(): void
    {
        $this->migrar(self::FASE_1);
        $emision = $this->emisionId();
        $certificado = $this->certificadoId();
        DB::table('cf_descargas')->insert(['emision_id' => $emision, 'via' => 'admin']);
        DB::table('cf_descargas')->insert(['certificado_legado_id' => $certificado, 'via' => 'admin']);

        foreach ([['cf_emisiones', $emision], ['cf_certificados_legado', $certificado]] as [$tabla, $id]) {
            try {
                DB::table($tabla)->where('id', $id)->delete();
                $this->fail("No debía poder borrarse $tabla #$id con descargas");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }

        // Borrar el participante NO borra la descarga: solo anula la referencia.
        $participante = DB::table('cf_emisiones')->where('id', $emision)->value('participante_id');
        DB::table('cf_descargas')->where('emision_id', $emision)->update(['participante_id' => $participante]);
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        DB::table('cf_participantes')->where('id', $participante)->delete();
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        $this->assertSame(2, DB::table('cf_descargas')->count());
    }

    public function test_descargado_at_no_cambia_al_actualizar_la_fila(): void
    {
        $this->migrar(self::FASE_1);
        $certificado = $this->certificadoId();
        $id = DB::table('cf_descargas')->insertGetId(['certificado_legado_id' => $certificado, 'via' => 'portal', 'descargado_at' => '2026-01-02 03:04:05']);

        DB::table('cf_descargas')->where('id', $id)->update(['ip_hash' => str_repeat('c', 64)]);

        $this->assertSame('2026-01-02 03:04:05', DB::table('cf_descargas')->where('id', $id)->value('descargado_at'));
    }

    public function test_el_rollback_deja_la_base_como_antes_y_no_toca_cf_emisiones_ni_cf_plantillas(): void
    {
        $definicion = fn (string $t) => DB::selectOne("SHOW CREATE TABLE `{$t}`")->{'Create Table'};
        $antes = [$definicion('cf_plantillas'), $definicion('cf_emisiones'), $definicion('cf_lotes'), $definicion('cf_participantes')];

        $this->migrar(self::FASE_1);
        $this->assertSame($antes[0], $definicion('cf_plantillas'));
        $this->assertSame($antes[1], $definicion('cf_emisiones'));

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => self::FASE_1, '--force' => true]), Artisan::output());

        foreach (['cf_eventos', 'cf_plantillas_legado_contenidos', 'cf_plantillas_legado', 'cf_certificados_legado', 'cf_descargas', 'cf_correos'] as $tabla) {
            $this->assertFalse(Schema::hasTable($tabla), "$tabla sigue existiendo");
        }
        $this->assertSame($antes, [$definicion('cf_plantillas'), $definicion('cf_emisiones'), $definicion('cf_lotes'), $definicion('cf_participantes')]);
    }

    // ── cf_correos ────────────────────────────────────────────────────────────

    private function participanteId(): int
    {
        return (int) DB::table('cf_emisiones')->where('id', $this->emisionId())->value('participante_id');
    }

    /** @param array<string,mixed> $extra */
    private function correo(array $extra): array
    {
        return $extra + ['correo' => 'a@example.test', 'correo_normalizado' => 'a@example.test', 'estado' => 'valido', 'origen' => 'legado', 'created_at' => now(), 'updated_at' => now()];
    }

    /** @return array<string,array{0:bool,1:bool,2:bool}> participante, certificado, aceptado */
    public static function propietariosDeCorreo(): array
    {
        return [
            'NULL / NULL: rechazado' => [false, false, false],
            'participante / NULL: aceptado' => [true, false, true],
            'NULL / certificado: aceptado' => [false, true, true],
            'participante / certificado: rechazado' => [true, true, false],
        ];
    }

    #[DataProvider('propietariosDeCorreo')]
    public function test_cf_correos_los_cuatro_escenarios_del_check_de_propietario(bool $conParticipante, bool $conCertificado, bool $aceptado): void
    {
        $this->migrar(self::FASE_1);
        $fila = $this->correo(
            ($conParticipante ? ['participante_id' => $this->participanteId()] : [])
            + ($conCertificado ? ['certificado_legado_id' => $this->certificadoId()] : [])
        );

        if ($aceptado) {
            DB::table('cf_correos')->insert($fila);
            $this->assertSame(1, DB::table('cf_correos')->count());
        } else {
            try {
                DB::table('cf_correos')->insert($fila);
                $this->fail('El CHECK debía rechazar la fila');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/cf_correos_un_propietario_chk|check constraint/i', $e->getMessage());
            }
            $this->assertSame(0, DB::table('cf_correos')->count());
        }
    }

    public function test_cf_correos_check_de_estado_origen_y_principal_valido(): void
    {
        $this->migrar(self::FASE_1);
        $cert = $this->certificadoId();

        foreach ([
            'estado ajeno' => ['estado' => 'multiple'],
            'origen ajeno' => ['origen' => 'otro'],
            'principal inválido' => ['estado' => 'invalido', 'es_principal' => 1],
        ] as $caso => $extra) {
            try {
                DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert] + $extra));
                $this->fail("El CHECK debía rechazar: $caso");
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/cf_correos_(estado|origen|principal_valido)_chk|check constraint/i', $e->getMessage(), $caso);
            }
        }

        // Los valores admitidos sí entran: inválido no principal y válido principal.
        DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert, 'correo_normalizado' => 'roto', 'estado' => 'invalido']));
        DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert, 'es_principal' => 1]));
        $this->assertSame(2, DB::table('cf_correos')->count());
    }

    public function test_cf_correos_los_check_tienen_la_expresion_exacta(): void
    {
        $this->migrar(self::FASE_1);
        $create = DB::selectOne('SHOW CREATE TABLE cf_correos')->{'Create Table'};

        foreach (['cf_correos_un_propietario_chk', 'cf_correos_estado_chk', 'cf_correos_origen_chk', 'cf_correos_principal_valido_chk'] as $nombre) {
            $this->assertStringContainsString("CONSTRAINT `{$nombre}` CHECK", $create);
        }
        $normalizada = strtolower(str_replace(['(', ')', '`', '_utf8mb4', "\\'"], '', $create));
        $this->assertStringContainsString('participante_id is not null and certificado_legado_id is null or participante_id is null and certificado_legado_id is not null', $normalizada);
        $this->assertStringContainsString('es_principal = 0 or estado = \'valido\'', str_replace('"', "'", $normalizada));
        // Collation binaria solo en el correo normalizado.
        $this->assertMatchesRegularExpression('/`correo_normalizado` varchar\(254\) (CHARACTER SET utf8mb4 )?COLLATE utf8mb4_bin NOT NULL/', $create);
    }

    public function test_cf_correos_unique_por_propietario_y_mismo_correo_en_propietarios_distintos(): void
    {
        $this->migrar(self::FASE_1);
        $participante = $this->participanteId();
        $cert1 = $this->certificadoId();
        $cert2 = (int) DB::table('cf_certificados_legado')->insertGetId([
            'evento_id' => DB::table('cf_eventos')->value('id'), 'documento_clave' => '2', 'nombre_completo' => 'LUIS', 'snapshot_legado' => '{}', 'created_at' => now(), 'updated_at' => now(),
        ]);

        // El mismo correo en tres propietarios distintos: permitido (y varios NULL del otro propietario no chocan).
        foreach ([['participante_id' => $participante], ['certificado_legado_id' => $cert1], ['certificado_legado_id' => $cert2]] as $dueno) {
            DB::table('cf_correos')->insert($this->correo($dueno));
        }
        $this->assertSame(3, DB::table('cf_correos')->where('correo_normalizado', 'a@example.test')->count());

        // Repetido dentro del mismo propietario: rechazado, sea participante o certificado.
        foreach ([['participante_id' => $participante], ['certificado_legado_id' => $cert1]] as $dueno) {
            try {
                DB::table('cf_correos')->insert($this->correo($dueno));
                $this->fail('El UNIQUE debía rechazar el duplicado');
            } catch (QueryException $e) {
                $this->assertMatchesRegularExpression('/duplicate entry|cf_correos_(participante|certificado)_correo_uq/i', $e->getMessage());
            }
        }

        // Varios correos distintos para el mismo propietario: permitido (hasta 10 reales en el sistema viejo).
        for ($i = 1; $i <= 10; $i++) {
            DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert2, 'correo' => "c{$i}@example.test", 'correo_normalizado' => "c{$i}@example.test", 'orden' => $i + 1]));
        }
        $this->assertSame(11, DB::table('cf_correos')->where('certificado_legado_id', $cert2)->count());
    }

    public function test_cf_correos_la_collation_binaria_distingue_tildes_en_el_normalizado(): void
    {
        $this->migrar(self::FASE_1);
        $cert = $this->certificadoId();

        // Con la collation por defecto (unicode_ci) estas dos direcciones chocarían; la normalización ya la hace la aplicación.
        DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert, 'correo' => 'jose@example.test', 'correo_normalizado' => 'jose@example.test']));
        DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert, 'correo' => 'josé@example.test', 'correo_normalizado' => 'josé@example.test']));

        $this->assertSame(2, DB::table('cf_correos')->where('certificado_legado_id', $cert)->count());
    }

    public function test_cf_correos_las_fk_restrict_impiden_borrar_al_propietario_y_se_borra_tras_quitar_los_correos(): void
    {
        $this->migrar(self::FASE_1);
        $participante = $this->participanteId();
        $cert = $this->certificadoId();
        DB::table('cf_correos')->insert($this->correo(['participante_id' => $participante]));
        DB::table('cf_correos')->insert($this->correo(['certificado_legado_id' => $cert]));

        foreach ([['cf_participantes', $participante], ['cf_certificados_legado', $cert]] as [$tabla, $id]) {
            try {
                DB::table($tabla)->where('id', $id)->delete();
                $this->fail("No debía poder borrarse $tabla #$id con correos");
            } catch (QueryException) {
                $this->addToAssertionCount(1);
            }
        }
        $this->assertSame(2, DB::table('cf_correos')->count());

        // Las FK apuntan a las tablas previstas con RESTRICT.
        $create = DB::selectOne('SHOW CREATE TABLE cf_correos')->{'Create Table'};
        $this->assertMatchesRegularExpression('/FOREIGN KEY \(`participante_id`\) REFERENCES `cf_participantes` \(`id`\)(?! ON DELETE (CASCADE|SET NULL))/', $create);
        $this->assertDoesNotMatchRegularExpression('/ON DELETE (CASCADE|SET NULL)/', $create);

        DB::table('cf_correos')->where('certificado_legado_id', $cert)->delete();
        DB::table('cf_correos')->where('participante_id', $participante)->delete();
        $this->assertSame(1, DB::table('cf_certificados_legado')->where('id', $cert)->delete());
    }

    public function test_cf_correos_el_modelo_funciona_contra_el_motor_real(): void
    {
        $this->migrar(self::FASE_1);
        $cert = $this->certificadoId();

        $a = Correo::create(['certificado_legado_id' => $cert, 'correo' => ' Ana@Example.TEST ', 'origen' => Correo::ORIGEN_LEGADO, 'es_principal' => true]);
        $this->assertSame('ana@example.test', $a->fresh()->correo_normalizado);
        $this->assertSame(' Ana@Example.TEST ', $a->fresh()->correo, 'La dirección original no se toca');

        try {
            Correo::create(['certificado_legado_id' => $cert, 'correo' => 'ANA@example.test', 'origen' => Correo::ORIGEN_LEGADO]);
            $this->fail('Debía rechazarse el duplicado normalizado');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        Correo::create(['certificado_legado_id' => $cert, 'correo' => 'otro@example.test', 'origen' => Correo::ORIGEN_LEGADO, 'orden' => 2]);
        $this->assertSame(2, Correo::where('certificado_legado_id', $cert)->count());
    }

    // ── Eliminación definitiva (Fase 1.2) contra el motor real ───────────────────────────────────────────────

    /** Base sin emisiones con 2 participantes y 3 correos. @return array{0:int,1:int,2:int} lote, participante 1, participante 2 */
    private function loteConCorreos(): array
    {
        Storage::fake('local');
        if (! Schema::hasTable('movimientos')) {
            // Mínima y sin FK a usuarios (no se necesita el módulo de usuarios para probar la eliminación).
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
        $plantilla = DB::table('cf_plantillas')->insertGetId([
            'nombre' => 'P', 'archivo_pdf' => 'x', 'nombre_archivo_original' => 'x.pdf', 'hash_sha256' => str_repeat('a', 64), 'created_at' => now(), 'updated_at' => now(),
        ]);
        $lote = DB::table('cf_lotes')->insertGetId(['plantilla_id' => $plantilla, 'nombre' => 'L', 'datos_comunes' => '{}', 'created_at' => now(), 'updated_at' => now()]);
        $ids = [];
        foreach ([1, 2] as $n) {
            $ids[] = DB::table('cf_participantes')->insertGetId(['lote_id' => $lote, 'nombre_completo' => "P$n", 'documento' => "$n", 'documento_clave' => "$n", 'created_at' => now(), 'updated_at' => now()]);
        }
        foreach ([[$ids[0], 'a@example.test'], [$ids[0], 'b@example.test'], [$ids[1], 'c@example.test']] as [$participante, $correo]) {
            DB::table('cf_correos')->insert($this->correo(['participante_id' => $participante, 'correo' => $correo, 'correo_normalizado' => $correo, 'origen' => 'credential_flow']));
        }

        return [$lote, $ids[0], $ids[1]];
    }

    public function test_eliminar_base_sin_historial_borra_correos_participantes_y_base_con_fk_restrict_reales(): void
    {
        $this->migrar(self::FASE_1);
        [$lote] = $this->loteConCorreos();

        // Sin pasar por el servicio la FK RESTRICT impide borrar al participante con correos (por eso se borran explícitamente).
        try {
            DB::table('cf_participantes')->where('lote_id', $lote)->delete();
            $this->fail('La FK RESTRICT debía impedirlo');
        } catch (QueryException) {
            $this->addToAssertionCount(1);
        }

        $r = app(EliminacionDefinitivaService::class)->eliminarLote($lote);

        $this->assertSame([2, 0, 3], [$r['participantes'], $r['emisiones'], $r['correos']]);
        $this->assertSame([0, 0, 0], [DB::table('cf_correos')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count()]);
        $this->assertSame(3, DB::table('movimientos')->first()->metadata ? json_decode(DB::table('movimientos')->first()->metadata, true)['correos'] : -1);
    }

    public function test_si_falla_tras_borrar_los_correos_el_motor_real_revierte_todo(): void
    {
        $this->migrar(self::FASE_1);
        [$lote] = $this->loteConCorreos();

        EliminacionDefinitivaService::$despuesDeBorrarCorreos = function () {
            $this->assertSame(0, DB::table('cf_correos')->count());
            throw new \RuntimeException('fallo simulado');
        };
        try {
            app(EliminacionDefinitivaService::class)->eliminarLote($lote);
            $this->fail('Debía lanzar');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::ERROR_GENERAL, $e->codigo);
        } finally {
            EliminacionDefinitivaService::$despuesDeBorrarCorreos = null;
        }

        $this->assertSame([3, 2, 1, 0], [DB::table('cf_correos')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count(), DB::table('movimientos')->count()]);
    }

    public function test_una_base_con_historial_se_bloquea_con_mensaje_humano_y_sin_error_de_fk(): void
    {
        $this->migrar(self::FASE_1);
        $emision = $this->emisionId();
        Storage::fake('local');
        $lote = (int) DB::table('cf_emisiones')->where('id', $emision)->value('lote_id');
        $participante = (int) DB::table('cf_emisiones')->where('id', $emision)->value('participante_id');
        DB::table('cf_correos')->insert($this->correo(['participante_id' => $participante, 'origen' => 'credential_flow']));
        $antes = [DB::table('cf_correos')->count(), DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count()];

        try {
            app(EliminacionDefinitivaService::class)->eliminarLote($lote);
            $this->fail('Debía bloquearse');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::CON_HISTORIAL, $e->codigo);
            $this->assertDoesNotMatchRegularExpression('/sqlstate|constraint|foreign|cf_/i', $e->getMessage());
        }

        // Con una descarga ligada a la emisión cambia el motivo, y tampoco se toca nada.
        DB::table('cf_descargas')->insert(['emision_id' => $emision, 'via' => 'portal']);
        try {
            app(EliminacionDefinitivaService::class)->eliminarLote($lote);
            $this->fail('Debía bloquearse');
        } catch (EliminacionException $e) {
            $this->assertSame(EliminacionException::CON_DESCARGAS, $e->codigo);
        }

        $this->assertSame($antes, [DB::table('cf_correos')->count(), DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count()]);
    }

    public function test_cf_correos_el_rollback_individual_deja_el_resto_intacto(): void
    {
        $this->migrar(self::FASE_1);
        $definicion = fn (string $t) => DB::selectOne("SHOW CREATE TABLE `{$t}`")->{'Create Table'};
        $antes = [$definicion('cf_participantes'), $definicion('cf_certificados_legado'), $definicion('cf_descargas')];

        $this->assertSame(0, Artisan::call('migrate:rollback', ['--path' => [self::FASE_1[6]], '--force' => true]), Artisan::output());

        $this->assertFalse(Schema::hasTable('cf_correos'));
        $this->assertSame($antes, [$definicion('cf_participantes'), $definicion('cf_certificados_legado'), $definicion('cf_descargas')]);
        $this->migrar([self::FASE_1[6]]);
        $this->assertTrue(Schema::hasTable('cf_correos'));
    }
}
