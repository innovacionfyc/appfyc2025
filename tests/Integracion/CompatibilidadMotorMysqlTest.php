<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\Rehearsal\VerificadorRehearsal;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Compatibilidad MySQL 8 ↔ MariaDB 10.6 (Fase 11C.1). Producción usa MariaDB 10.6.23: estas pruebas comprueban sobre el MOTOR REAL que los CHECK, el JSON, los UNIQUE, SKIP LOCKED
 * y los TIMESTAMP se comportan igual. Se ejecutan dentro de una transacción que se revierte, sobre una base local *_test con el esquema COMPLETO ya migrado
 * (no la crean ni la borran):
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_migracion_legado_test php artisan test tests/Integracion/CompatibilidadMotorMysqlTest.php
 *   (MariaDB local aislada: DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=root sin contraseña …)
 */
class CompatibilidadMotorMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private bool $enTransaccion = false;

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');
        if ($base === '' || ! preg_match('/_(dev|test|testing)$/', $base) || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test con el esquema completo.');
        }
        config(['database.connections.'.self::CONEXION => [...$config, 'database' => $base], 'database.default' => self::CONEXION]);
        DB::purge(self::CONEXION);
        if (! Schema::hasTable('cf_conciliaciones') || ! Schema::hasTable('cf_envios')) {
            $this->markTestSkipped('La base no tiene el esquema completo de Credential Flow.');
        }
        DB::beginTransaction();
        $this->enTransaccion = true;
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');   // aquí se prueban los CHECK/UNIQUE/JSON, no las FK (se vuelve a activar al revertir)
    }

    protected function tearDown(): void
    {
        if ($this->enTransaccion) {
            DB::rollBack();
            DB::statement('SET FOREIGN_KEY_CHECKS = 1');
        }
        parent::tearDown();
    }

    /** Inserta una fila rellenando las columnas NOT NULL sin valor por defecto con valores neutros. */
    private function fila(string $tabla, array $valores = []): int
    {
        $fila = [];
        foreach (DB::select('select column_name n, data_type t, column_type ct, is_nullable nu, column_default d, extra e, character_maximum_length l from information_schema.columns where table_schema = database() and table_name = ? order by ordinal_position', [$tabla]) as $c) {
            if (array_key_exists($c->n, $valores)) {
                $fila[$c->n] = $valores[$c->n];

                continue;
            }
            if ($c->nu === 'YES' || $c->d !== null || str_contains((string) $c->e, 'auto_increment')) {
                continue;
            }
            $fila[$c->n] = match (true) {
                in_array($c->t, ['int', 'bigint', 'smallint', 'tinyint', 'mediumint'], true) => 1,
                in_array($c->t, ['timestamp', 'datetime'], true) => now()->toDateTimeString(),
                $c->t === 'date' => now()->toDateString(),
                $c->t === 'json' || ($c->t === 'longtext' && $c->n !== 'x') => '{}',
                $c->t === 'char' => str_repeat('a', (int) $c->l),
                $c->t === 'decimal' => 0,
                default => 'x',
            };
        }

        return (int) DB::table($tabla)->insertGetId($fila);
    }

    private function rechaza(callable $f, int ...$codigos): void
    {
        try {
            $f();
        } catch (QueryException $e) {
            $this->assertContains((int) ($e->errorInfo[1] ?? 0), $codigos, $e->getMessage());

            return;
        }
        $this->fail('El motor debía rechazar la fila.');
    }

    /** CHECK: MySQL 3819, MariaDB 4025 (y 4025 también para JSON_VALID). */
    private const CHECK = [3819, 4025];

    public function test_los_check_de_valores_cerrados_se_aplican_de_verdad(): void
    {
        $this->rechaza(fn () => $this->fila('cf_conciliaciones', ['tipo' => 'inventado', 'estado' => 'abierto']), ...self::CHECK);
        $this->rechaza(fn () => $this->fila('cf_conciliaciones', ['tipo' => 'revision_documento', 'estado' => 'inventado']), ...self::CHECK);
        $this->rechaza(fn () => $this->fila('cf_envios', ['estado' => 'inventado']), ...self::CHECK);
        $this->assertGreaterThan(0, $this->fila('cf_conciliaciones', ['tipo' => 'revision_documento', 'estado' => 'abierto', 'clave_idempotencia' => 'ok-1']));
    }

    public function test_el_check_de_un_solo_propietario_y_el_de_formato_de_codigo_se_aplican(): void
    {
        $this->rechaza(fn () => $this->fila('cf_correos', ['participante_id' => null, 'certificado_legado_id' => null, 'estado' => 'valido', 'origen' => 'legado']), ...self::CHECK);
        $this->rechaza(fn () => $this->fila('cf_correos', ['participante_id' => 1, 'certificado_legado_id' => 1, 'estado' => 'valido', 'origen' => 'legado']), ...self::CHECK);
        $this->rechaza(fn () => $this->fila('cf_codigos_historicos', ['codigo' => '12']), ...self::CHECK);
        $this->rechaza(fn () => $this->fila('cf_codigos_historicos', ['codigo' => 'ABCD']), ...self::CHECK);
        $this->assertGreaterThan(0, $this->fila('cf_codigos_historicos', ['codigo' => '51234', 'par_hash' => str_repeat('b', 64)]));
    }

    public function test_los_check_de_aprobaciones_masivas_distinto_aprobador_y_estado_se_aplican(): void
    {
        $base = ['decision_id' => 1, 'solicitada_por' => 5, 'estado' => 'pendiente'];
        $this->rechaza(fn () => $this->fila('cf_decisiones_identidad_aprobaciones', [...$base, 'estado' => 'aprobada', 'aprobada_por' => 5, 'aprobada_at' => now()->toDateTimeString()]), ...self::CHECK);   // el mismo admin
        $this->rechaza(fn () => $this->fila('cf_decisiones_identidad_aprobaciones', [...$base, 'estado' => 'aprobada', 'aprobada_por' => null, 'aprobada_at' => null]), ...self::CHECK);                       // aprobada sin aprobador
        $this->rechaza(fn () => $this->fila('cf_decisiones_identidad_aprobaciones', [...$base, 'aprobada_por' => 7]), ...self::CHECK);                                                                            // pendiente con aprobador
        $this->rechaza(fn () => $this->fila('cf_decisiones_identidad_aprobaciones', [...$base, 'estado' => 'inventado']), ...self::CHECK);
        $this->assertGreaterThan(0, $this->fila('cf_decisiones_identidad_aprobaciones', [...$base, 'estado' => 'aprobada', 'aprobada_por' => 6, 'aprobada_at' => now()->toDateTimeString()]));
    }

    public function test_el_json_invalido_se_rechaza_y_el_valido_se_conserva_equivalente(): void
    {
        $this->rechaza(fn () => $this->fila('cf_migraciones_corridas', ['totales' => '{no es json']), 3140, ...self::CHECK);   // MySQL 3140 (JSON inválido) · MariaDB 4025 (CHECK JSON_VALID)
        $id = $this->fila('cf_migraciones_corridas', ['totales' => '{"b": 2, "a": {"z": 1, "y": [1,2]}}']);
        $json = (string) DB::table('cf_migraciones_corridas')->where('id', $id)->value('totales');

        $this->assertSame('{"a":{"y":[1,2],"z":1},"b":2}', VerificadorRehearsal::canonico($json), 'la huella es neutra respecto del motor');
        // La ruta JSON usada por el backfill (`columna->clave`) funciona en ambos motores.
        $this->assertSame(1, DB::table('cf_migraciones_corridas')->where('id', $id)->where('totales->b', 2)->count());
    }

    public function test_los_unique_se_aplican(): void
    {
        $this->fila('cf_conciliaciones', ['tipo' => 'revision_documento', 'estado' => 'abierto', 'clave_idempotencia' => 'dup-1']);
        $this->rechaza(fn () => $this->fila('cf_conciliaciones', ['tipo' => 'revision_documento', 'estado' => 'abierto', 'clave_idempotencia' => 'dup-1']), 1062);
    }

    public function test_skip_locked_omite_la_fila_retenida_por_otra_conexion(): void
    {
        $id = $this->fila('cf_conciliaciones', ['tipo' => 'revision_documento', 'estado' => 'abierto', 'clave_idempotencia' => 'lock-1']);
        DB::commit();   // la otra conexión debe VER la fila: esta prueba limpia al final lo que crea
        $this->enTransaccion = false;
        $segunda = 'mysql_pruebas_b';
        config(['database.connections.'.$segunda => config('database.connections.'.self::CONEXION)]);

        try {
            DB::beginTransaction();
            $this->assertSame($id, (int) DB::table('cf_conciliaciones')->where('id', $id)->lockForUpdate()->value('id'));

            DB::connection($segunda)->beginTransaction();
            $omitida = DB::connection($segunda)->table('cf_conciliaciones')->where('id', $id)->lock('for update skip locked')->get();
            $this->assertCount(0, $omitida, 'SKIP LOCKED no espera y omite la fila retenida');
            DB::connection($segunda)->rollBack();
            DB::rollBack();
        } finally {
            DB::table('cf_conciliaciones')->where('id', $id)->delete();
        }
    }

    public function test_ningun_timestamp_not_null_queda_con_valor_implicito_y_ninguna_columna_se_actualiza_sola(): void
    {
        $implicitos = DB::select("select table_name t, column_name c from information_schema.columns where table_schema = database() and table_name like 'cf\\_%' and data_type = 'timestamp' and is_nullable = 'NO' and column_default is null");
        $this->assertSame([], array_map(fn ($r) => $r->t.'.'.$r->c, $implicitos), 'MariaDB convertiría la primera en DEFAULT CURRENT_TIMESTAMP ON UPDATE: usa dateTime() o useCurrent()');

        $alActualizar = DB::select("select table_name t, column_name c from information_schema.columns where table_schema = database() and table_name like 'cf\\_%' and lower(extra) like '%on update%'");
        $this->assertSame([], array_map(fn ($r) => $r->t.'.'.$r->c, $alActualizar));
    }

    public function test_todas_las_tablas_cf_usan_utf8mb4_unicode_ci(): void
    {
        $otras = DB::select("select table_name t, table_collation c from information_schema.tables where table_schema = database() and table_name like 'cf\\_%' and table_collation <> 'utf8mb4_unicode_ci'");
        $this->assertSame([], array_map(fn ($r) => $r->t.':'.$r->c, $otras));
    }
}
