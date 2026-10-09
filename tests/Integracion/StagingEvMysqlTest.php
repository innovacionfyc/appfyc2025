<?php

namespace Tests\Integracion;

use App\Support\CredentialFlow\StagingEv\ReporteConciliacion;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;
use Tests\TestCase;

/**
 * El staging histórico contra MySQL/MariaDB REAL (las pruebas normales corren sobre SQLite): comprueba que el cargador, el
 * conciliador (UPDATE con subconsultas, grupos de duplicados) y el reporte dan el mismo resultado en el motor de verdad.
 *
 * Solo se ejecuta con una base local de pruebas (nunca la de desarrollo):
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/StagingEvMysqlTest.php
 *
 * Crea las tablas stg_ev_* en esa base con datos sintéticos y las elimina al terminar.
 */
class StagingEvMysqlTest extends TestCase
{
    use FixturesStagingEv;

    private const CONEXION = 'mysql_pruebas';

    protected function setUp(): void
    {
        parent::setUp();

        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $config = config('database.connections.mysql');

        if ($base === '' || ! preg_match('/_(dev|test|testing)$/', $base) || $base === ($config['database'] ?? '')
            || ! in_array($config['host'] ?? '', ['127.0.0.1', 'localhost'], true)) {
            $this->markTestSkipped('Indica TEST_MYSQL_DATABASE con una base local *_test distinta de la de desarrollo.');
        }
        $driver = (string) getenv('TEST_MYSQL_DRIVER');

        config([
            'database.connections.'.self::CONEXION => [...$config, 'driver' => $driver ?: ($config['driver'] ?? 'mysql'), 'database' => $base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);

        $this->limpiar();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
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
        // Red de seguridad: este método ELIMINA tablas; solo puede actuar sobre una base de pruebas.
        $this->assertMatchesRegularExpression('/_(dev|test|testing)$/', (string) DB::connection()->getDatabaseName());
        $this->assertNotSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        // Lista explícita (no se listan las tablas de la base): solo estas se pueden eliminar.
        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (['stg_ev_historial', 'stg_ev_participante_correos', 'stg_ev_imagenes', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
            'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots', 'migrations'] as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    public function test_carga_concilia_y_reporta_igual_que_en_sqlite(): void
    {
        $r = $this->cargar('s1');

        $this->assertSame(15, $r->entidades['participante']['nuevas']);
        $rep = (new ReporteConciliacion)->generar();

        $this->assertSame([4, 8, 1, 3], [$rep['duplicados']['grupos'], $rep['duplicados']['filas_en_grupos'], $rep['duplicados']['identicos'], $rep['duplicados']['conflictivos']]);
        $this->assertSame(['SOLO_VERIF_NULL_VS_VALOR' => 1], $rep['duplicados']['subtipos']);
        $this->assertSame(4, $rep['participantes']['documento']['REVISION_DOCUMENTO']);
        $this->assertSame(['vacio' => 2, 'anomalo' => 2], array_intersect_key($rep['participantes']['documento'], ['vacio' => 1, 'anomalo' => 1]));
        $this->assertSame(1, $rep['descargas']['sin_participante']);
        $this->assertSame(2, $rep['tokens']['codigos_repetidos']);
        $this->assertSame(1, $rep['encuestas']['sin_participante_y_evento_inexistente']);
        $this->assertSame(3, $rep['encuestas']['preguntas']['pregunta1']['igual_al_texto_de_alguna_opcion']);
        $this->assertSame(1, $rep['codigo_legado']['con_descarga_y_sin_codigo']);
    }

    public function test_nombres_de_imagen_que_solo_difieren_en_mayusculas_o_tildes_no_chocan_en_mysql(): void
    {
        $imagen = fn (string $nombre, string $sha) => [
            'ruta_relativa' => 'document/certImages/'.$nombre, 'nombre_original' => $nombre, 'extension' => 'png', 'sha256' => str_repeat($sha, 64), 'bytes' => 10,
            'ancho_px' => 1, 'alto_px' => 1, 'mime_real' => 'image/png', 'renderizable_fpdf' => true, 'motivo_no_renderizable' => null,
        ];

        $b = $this->cargar('s1', null, [$imagen('AGO - Ética.png', 'a'), $imagen('AGO - ETICA.png', 'b'), $imagen('ago - ética.png', 'c')]);
        $this->assertSame(3, $b->entidades['imagenes']['nuevas']);
        $this->assertSame(3, DB::table('stg_ev_imagenes')->count());

        $c = $this->cargar('s2', null, [$imagen('AGO - Ética.png', 'a'), $imagen('AGO - ETICA.png', 'b'), $imagen('ago - ética.png', 'c')]);
        $this->assertSame(3, $c->entidades['imagenes']['iguales']);
        $this->assertSame(0, $c->entidades['imagenes']['cambiadas']);
    }

    public function test_cargas_sucesivas_en_mysql(): void
    {
        $this->cargar('s1');
        $b = $this->cargar('s2', $this->conCambio($this->sinFila($this->datos(), 'participante', 5), 'participante', 3, ['correo' => 'carla@example.test']));

        $this->assertSame([1, 1, 13], [$b->entidades['participante']['ausentes'], $b->entidades['participante']['cambiadas'], $b->entidades['participante']['iguales']]);
        $this->assertSame(1, DB::table('stg_ev_historial')->count());
        $this->assertSame('ausente_en_origen', DB::table('stg_ev_participante')->where('old_id', 5)->value('estado_fila'));
        $this->assertSame(15, DB::table('stg_ev_participante')->count());
    }

    public function test_limpiar_y_recargar_da_la_misma_huella_en_mysql(): void
    {
        $this->cargar('s1');
        $h1 = (new ReporteConciliacion)->generar()['huella_contenido'];

        $this->assertSame(0, Artisan::call('credential-flow:staging-ev:limpiar', ['--confirmar' => true]), Artisan::output());
        $this->assertSame(0, DB::table('stg_ev_participante')->count());
        $this->cargar('s1-recarga');

        $this->assertSame($h1, (new ReporteConciliacion)->generar()['huella_contenido']);
    }

    public function test_el_comando_se_niega_en_una_base_que_no_es_de_staging(): void
    {
        // La conexión de pruebas termina en «_test»: se permite. Con otro nombre la guardia debe negarse (y no toca nada).
        $base = (string) getenv('TEST_MYSQL_DATABASE');
        $this->cargar('s1');
        try {
            config(['database.connections.'.self::CONEXION.'.database' => 'appfyc_no_es_destino']);
            DB::purge(self::CONEXION);
            // Ese nombre no contiene «staging» ni termina en _test: la guardia debe negarse sin conectarse a nada.
            $this->assertSame(1, Artisan::call('credential-flow:staging-ev:limpiar', ['--confirmar' => true]));
            $this->assertStringContainsString('no parece de staging', Artisan::output());
        } finally {
            config(['database.connections.'.self::CONEXION.'.database' => $base]);
            DB::purge(self::CONEXION);
        }
        $this->assertSame(15, DB::table('stg_ev_participante')->count(), 'No se borró nada');
    }
}
