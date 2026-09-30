<?php

namespace Tests\Feature\CredentialFlow;

use Illuminate\Database\Connection;
use Illuminate\Database\MariaDbConnection;
use Illuminate\Database\MySqlConnection;
use Illuminate\Support\Facades\Schema;
use PDO;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * Producción usa MariaDB 10.6 con explicit_defaults_for_timestamp = 0: allí la PRIMERA columna TIMESTAMP NOT NULL de una
 * tabla, si no declara DEFAULT ni ON UPDATE, recibe implícitamente `DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP`
 * y cambiaría sola en cualquier UPDATE (p. ej. `cf_emisiones.emitido_at` al revocar).
 *
 * Estas pruebas NO ejecutan MariaDB (los tests corren sobre SQLite): compilan las migraciones de Credential Flow con las
 * gramáticas de MySQL y de MariaDB —sin conexión— y comprueban que ninguna columna TIMESTAMP NOT NULL queda sin un DEFAULT
 * explícito, que es lo que evita el ON UPDATE implícito. La semántica del servidor se validó a mano contra una
 * MariaDB 10.6.23 real con explicit_defaults_for_timestamp = 0 (la definición anterior mutaba emitido_at al hacer UPDATE; la
 * actual lo conserva).
 */
class MigracionesTimestampTest extends TestCase
{
    private const MIGRACIONES = [
        'database/migrations/2026_09_29_160000_cf_plantillas_table.php',
        'database/migrations/2026_09_30_100000_cf_lotes_table.php',
        'database/migrations/2026_09_30_100100_cf_participantes_table.php',
        'database/migrations/2026_09_30_200000_cf_emisiones_table.php',
    ];

    /** @return array<string,array{0:class-string<Connection>}> */
    public static function motores(): array
    {
        return ['MySQL' => [MySqlConnection::class], 'MariaDB' => [MariaDbConnection::class]];
    }

    /** @return array<int,string> columnas del CREATE TABLE compilado por la gramática del motor */
    private function columnas(string $conexionClase, string $migracion): array
    {
        // PDO de mentira: solo responde la versión del servidor (la gramática la consulta); nunca se ejecuta SQL real.
        $version = $conexionClase === MariaDbConnection::class ? '10.6.23-MariaDB-0ubuntu0.22.04.1' : '8.4.3';
        $pdo = new class($version) extends PDO
        {
            public function __construct(private string $version) {}

            public function getAttribute(int $attribute): mixed
            {
                return $attribute === PDO::ATTR_SERVER_VERSION ? $this->version : null;
            }
        };
        $conexion = new $conexionClase($pdo, 'appfyc', '', ['name' => 'pretend']);
        $conexion->useDefaultSchemaGrammar();
        Schema::swap($conexion->getSchemaBuilder());

        $m = require base_path($migracion);
        $sentencias = array_column($conexion->pretend(fn () => $m->up()), 'query');
        $create = collect($sentencias)->first(fn ($q) => str_starts_with($q, 'create table'));
        $this->assertNotNull($create, "No se compiló el CREATE TABLE de $migracion");

        return array_map(fn ($c) => rtrim(trim($c), ')'), explode(',', substr($create, (int) strpos($create, '(') + 1)));
    }

    /**
     * @dataProvider motores
     */
    #[DataProvider('motores')]
    public function test_emitido_at_declara_un_default_explicito_y_nunca_un_on_update(string $conexionClase): void
    {
        $columna = collect($this->columnas($conexionClase, self::MIGRACIONES[3]))->first(fn ($c) => str_starts_with($c, '`emitido_at`'));

        $this->assertSame('`emitido_at` timestamp not null default CURRENT_TIMESTAMP', $columna);
        $this->assertStringNotContainsStringIgnoringCase('on update', $columna);
    }

    /**
     * @dataProvider motores
     */
    #[DataProvider('motores')]
    public function test_ninguna_columna_timestamp_not_null_de_credential_flow_queda_sin_default_explicito(string $conexionClase): void
    {
        $revisadas = 0;
        foreach (self::MIGRACIONES as $migracion) {
            foreach ($this->columnas($conexionClase, $migracion) as $columna) {
                if (! preg_match('/^`[a-z_]+` timestamp\b/i', $columna)) {
                    continue;
                }
                $revisadas++;
                // Las NULL (timestamps(), softDeletes(), revocado_at) no reciben ningún valor implícito; las NOT NULL exigen DEFAULT.
                $this->assertTrue(
                    str_contains($columna, ' null') && ! str_contains($columna, 'not null') || str_contains(strtolower($columna), 'default'),
                    "$migracion: $columna quedaría sujeta al DEFAULT/ON UPDATE implícito de explicit_defaults_for_timestamp = 0"
                );
                $this->assertStringNotContainsStringIgnoringCase('on update', $columna);
            }
        }

        $this->assertGreaterThanOrEqual(9, $revisadas, 'Se esperaban al menos las columnas de timestamps()/softDeletes() de las cuatro tablas y emitido_at/revocado_at');
    }

    public function test_revocado_at_y_los_timestamps_automaticos_son_nullable(): void
    {
        $columnas = $this->columnas(MariaDbConnection::class, self::MIGRACIONES[3]);

        foreach (['revocado_at', 'created_at', 'updated_at'] as $nombre) {
            $this->assertContains("`$nombre` timestamp null", $columnas);
        }
    }
}
