<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\MigracionCorrida;
use App\Support\CredentialFlow\Conciliaciones\DetectorConciliaciones;
use App\Support\CredentialFlow\Legado\PreflightCodigosHistoricos;
use App\Support\CredentialFlow\Migracion\DeltaRechazado;
use App\Support\CredentialFlow\Migracion\MigradorDelta;
use App\Support\CredentialFlow\Migracion\MigradorDeltaEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorEncuestas;
use App\Support\CredentialFlow\Migracion\MigradorHistorico;
use App\Support\CredentialFlow\Migracion\RollbackDelta;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;
use Tests\TestCase;

/**
 * Migración DELTA (Fase 11F-B) contra MySQL/MariaDB REAL con staging SINTÉTICO: UNIQUE binarios, FK RESTRICT, JSON, lotes y rollback
 * con restricciones reales. Solo con una base local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/MigracionDeltaMysqlTest.php
 *
 * La prueba de ESCALA (proporciones de producción: 627 eventos, 25 917 participantes, 10 980 descargas, 3 087 encuestas) solo corre con
 * DELTA_ESCALA=1 y escribe sus tiempos (ms) en DELTA_ESCALA_SALIDA (por defecto, storage/logs/delta_escala.json).
 */
class MigracionDeltaMysqlTest extends TestCase
{
    use FixturesStagingEv;

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
        'database/migrations/2026_10_12_100000_cf_conciliaciones_tables.php',
        'database/migrations/2026_10_13_100000_add_aprobada_por_conciliacion_to_cf_plantillas_legado.php',
        'database/migrations/2026_10_13_100100_widen_accion_in_cf_conciliaciones_eventos.php',
        'database/migrations/2026_10_14_100000_cf_codigos_historicos_tables.php',
    ];

    private const TABLAS = [
        'movimientos', 'cf_conciliaciones_eventos', 'cf_conciliaciones_certificados', 'cf_conciliaciones', 'cf_codigos_historicos', 'cf_codigo_historico_contador',
        'cf_encuestas_respuestas_detalle', 'cf_encuestas_respuestas', 'cf_encuestas_opciones', 'cf_encuestas_preguntas', 'cf_encuestas_versiones', 'cf_encuestas',
        'cf_migraciones_map', 'cf_correos', 'cf_descargas', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos',
        'cf_emisiones', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_migraciones_corridas', 'cf_plantillas',
        'stg_ev_historial', 'stg_ev_participante_correos', 'stg_ev_imagenes', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
        'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots', 'migrations',
    ];

    private string $png = '';

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

        // Con DELTA_MOTOR_ESPERADO (p. ej. «10.6.23-MariaDB») la prueba exige ese motor exacto: evidencia de qué servidor la ejecutó.
        $esperado = (string) getenv('DELTA_MOTOR_ESPERADO');
        if ($esperado !== '') {
            $this->assertStringContainsString($esperado, (string) DB::connection(self::CONEXION)->selectOne('select version() as v')->v);
        }

        $this->limpiar();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRACIONES, '--force' => true]), Artisan::output());
        $this->assertSame(0, Artisan::call('migrate', ['--path' => 'database/staging/ev', '--force' => true]), Artisan::output());
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

    protected function tearDown(): void
    {
        if (DB::getDefaultConnection() === self::CONEXION) {
            $this->limpiar();
        }
        parent::tearDown();
    }

    private function limpiar(): void
    {
        $this->assertMatchesRegularExpression('/_(dev|test|testing)$/', (string) DB::connection()->getDatabaseName());
        $this->assertNotSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        DB::statement('SET FOREIGN_KEY_CHECKS = 0');
        foreach (self::TABLAS as $tabla) {
            DB::statement("DROP TABLE IF EXISTS `{$tabla}`");
        }
        DB::statement('SET FOREIGN_KEY_CHECKS = 1');
    }

    /** @return array<string,mixed> */
    private function imagenes(array $extra = []): array
    {
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'delta_mysql_'.bin2hex(random_bytes(6));
        mkdir($dir);
        if ($this->png === '') {
            $im = imagecreatetruecolor(2, 2);
            ob_start();
            imagepng($im);
            $this->png = (string) ob_get_clean();
        }
        file_put_contents($dir.'/ALFA 2024.png', $this->png);
        file_put_contents($dir.'/DELTA 2023', $this->png);
        file_put_contents($dir.'/ORFANA.png', $this->png.'o');
        file_put_contents($dir.'/GAMMA_.png', $this->png.'g');
        foreach ($extra as $n => $c) {
            file_put_contents($dir.'/'.$n, $c);
        }
        $r = (new EscanerImagenes)->escanear($dir);
        File::deleteDirectory($dir);

        return $r;
    }

    private function nuevaImagen(): string
    {
        $im = imagecreatetruecolor(3, 3);
        ob_start();
        imagepng($im);

        return (string) ob_get_clean();
    }

    /** @return array<string,int> */
    private function conteos(): array
    {
        return collect(['cf_eventos', 'cf_plantillas_legado_contenidos', 'cf_plantillas_legado', 'cf_certificados_legado', 'cf_correos', 'cf_descargas', 'cf_migraciones_map', 'cf_encuestas_respuestas', 'cf_encuestas_respuestas_detalle', 'cf_migraciones_corridas'])
            ->mapWithKeys(fn ($t) => [$t => DB::table($t)->count()])->all();
    }

    private function datosDelta(): array
    {
        $d = $this->datos();
        $d['evento'][] = ['id' => 7, 'nombre' => 'Curso Eta 2026', 'imagen_certificado' => 'ETA 2026.png'];
        for ($i = 0; $i < 4; $i++) {
            $d['participante'][] = ['id' => 16 + $i, 'tipo_documento' => 'CC', 'documento' => (string) (3000001 + $i), 'nombre' => 'PERSONA '.$i, 'correo' => $i === 2 ? null : "p{$i}@example.test", 'id_evento' => 7, 'num_verificacion' => $i === 0 ? 777 : null];
        }
        $d['descargas'][] = ['id' => 6, 'fecha' => '2026-10-14 10:00:00', 'id_evento' => 1, 'id_participante' => 1];
        $d['descargas'][] = ['id' => 7, 'fecha' => '2026-10-14 10:05:00', 'id_evento' => 1, 'id_participante' => 7];
        $d['descargas'][] = ['id' => 8, 'fecha' => '2026-10-14 11:00:00', 'id_evento' => 7, 'id_participante' => 16];
        $d['token'][] = ['id' => 4, 'codigo' => 'tokensecreto-ccc', 'fecha_creacion' => '2026-10-14 09:00:00', 'id_participante' => 16];
        $d['encuesta'][] = [
            'id' => 4, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno',
            'pregunta5' => 'Muy bien', 'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '',
            'id_evento' => 7, 'documento_participante' => '3000001', 'fecha' => '2026-10-14 12:00:00',
        ];

        return $d;
    }

    public function test_delta_idempotencia_rechazos_y_rollback_con_restricciones_reales(): void
    {
        $this->cargar('s1', null, $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();
        $base = $this->conteos();

        // Delta vacío (snapshot sin cambios): no-op sin tocar nada.
        $this->cargar('s1b', $this->datos(), $this->imagenes());
        $this->assertTrue((new MigradorDelta)->ejecutar()->noOp);
        $this->assertSame($base, $this->conteos());

        // Fila cambiada → rechaza sin escribir.
        $this->cargar('s2c', $this->conCambio($this->datosDelta(), 'participante', 1, ['nombre' => 'CAMBIADA']), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));
        try {
            (new MigradorDelta)->ejecutar();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::FILAS_CAMBIADAS, $e->codigo);
        }
        $this->assertSame($base, $this->conteos());
    }

    public function test_delta_completo_en_el_motor_real(): void
    {
        $this->cargar('s1', null, $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();
        $base = $this->conteos();
        $this->cargar('s2', $this->datosDelta(), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));

        $r = (new MigradorDelta)->ejecutar();
        $e = (new MigradorDeltaEncuestas)->ejecutar();
        $despues = $this->conteos();

        $this->assertFalse($r->noOp);
        $this->assertSame([1, 4, 3, 1, 1], [
            $despues['cf_eventos'] - $base['cf_eventos'], $despues['cf_certificados_legado'] - $base['cf_certificados_legado'],
            $despues['cf_descargas'] - $base['cf_descargas'], $despues['cf_plantillas_legado_contenidos'] - $base['cf_plantillas_legado_contenidos'],
            $despues['cf_encuestas_respuestas'] - $base['cf_encuestas_respuestas'],
        ]);
        foreach ([...$r->totales['validaciones'], ...$e->totales['validaciones']] as $v) {
            $this->assertTrue($v['ok'], $v['clave']);
        }
        $this->assertSame(2, $r->totales['descargas']['sobre_certificados_base']);
        $this->assertSame(1, $r->totales['descargas']['sobre_certificados_delta']);
        $this->assertSame('777', (string) DB::table('cf_certificados_legado')->where('corrida_id', $r->corridaId)->whereNotNull('codigo_legado')->value('codigo_legado'));

        // Idempotencia.
        $this->assertTrue((new MigradorDelta)->ejecutar()->noOp);
        $this->assertTrue((new MigradorDeltaEncuestas)->ejecutar()->noOp);
        $this->assertSame($despues, $this->conteos());

        // El rollback del delta de certificados se niega mientras existan las encuestas del delta (FK RESTRICT real).
        try {
            (new RollbackDelta)->revertirLegado($r->corridaId);
            $this->fail('Debía negarse');
        } catch (RollbackNoPermitido $x) {
            $this->assertSame(RollbackNoPermitido::ENCUESTAS_VINCULADAS, $x->codigo);
        }
        $this->assertSame($despues, $this->conteos());

        (new RollbackDelta)->revertirEncuestas($e->corridaId);
        $out = (new RollbackDelta)->revertirLegado($r->corridaId);
        $this->assertSame(4, $out['certificados']);
        $final = $this->conteos();
        $base['cf_migraciones_corridas'] += 2;
        $this->assertSame($base, $final);
        $this->assertSame(15, DB::table('stg_ev_participante')->where('primera_snapshot_id', 1)->count(), 'El staging no se toca');
    }

    private function baseMigrada(): void
    {
        $this->cargar('s1', null, $this->imagenes());
        (new MigradorHistorico)->ejecutar();
        (new MigradorEncuestas)->ejecutar();
    }

    public function test_descarga_sobre_certificado_base_solo_inserta_la_descarga_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $cert = (int) DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '1')->value('destino_id');
        $antes = (array) DB::table('cf_certificados_legado')->where('id', $cert)->first();
        $certs = DB::table('cf_certificados_legado')->count();
        $descargas = DB::table('cf_descargas')->where('certificado_legado_id', $cert)->count();
        $this->cargar('s2', $this->datosDelta(), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));

        $r = (new MigradorDelta)->ejecutar();

        $this->assertSame($antes, (array) DB::table('cf_certificados_legado')->where('id', $cert)->first(), 'el certificado base no cambia');
        $this->assertSame($certs + 4, DB::table('cf_certificados_legado')->count());
        $this->assertSame($descargas + 1, DB::table('cf_descargas')->where('certificado_legado_id', $cert)->count());
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '1')->count(), 'un solo mapa del participante');
        $this->assertSame(0, DB::table('cf_migraciones_map')->where('corrida_id', $r->corridaId)->where('origen_tabla', 'participante')->where('origen_id', '1')->count());
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('corrida_id', $r->corridaId)->where('origen_tabla', 'descarga')->where('origen_id', '6')->count());
    }

    public function test_actualizacion_de_codigo_scoped_idempotente_y_reversible_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $cert = fn (int $old) => (int) DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', (string) $old)->value('destino_id');
        $antes = (array) DB::table('cf_certificados_legado')->where('id', $cert(3))->first();
        $otros = (array) DB::table('cf_certificados_legado')->where('id', $cert(6))->first();
        $conteos = $this->conteos();
        $datos = $this->conCambio($this->datosDelta(), 'participante', 3, ['num_verificacion' => 31003]);
        $this->cargar('s2', $datos, $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));

        $r = (new MigradorDelta)->ejecutar();

        $this->assertSame('31003', (string) DB::table('cf_certificados_legado')->where('id', $cert(3))->value('codigo_legado'));
        $this->assertSame($otros, (array) DB::table('cf_certificados_legado')->where('id', $cert(6))->first(), 'solo cambia el certificado indicado');
        $this->assertSame(1, DB::table('cf_migraciones_map')->where('corrida_id', $r->corridaId)->where('origen_tabla', MigradorDelta::MAPA_CODIGO)->count());
        // Idempotencia: el mismo snapshot no vuelve a escribir.
        $otra = (new MigradorDelta)->ejecutar();
        $this->assertTrue($otra->noOp);

        // Rollback: el código vuelve exactamente a NULL (y el resto a lo anterior).
        $out = (new RollbackDelta)->revertirLegado($r->corridaId);
        $this->assertSame(1, $out['codigos']);
        $this->assertSame($antes, (array) DB::table('cf_certificados_legado')->where('id', $cert(3))->first());
        $esperado = $conteos;
        $esperado['cf_migraciones_corridas'] += 1;
        $this->assertSame($esperado, $this->conteos());
    }

    public function test_cambio_de_codigo_con_actividad_posterior_bloquea_el_rollback_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $cert = (int) DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->where('origen_id', '3')->value('destino_id');
        $datos = $this->conCambio($this->datosDelta(), 'participante', 3, ['num_verificacion' => 31003]);
        $this->cargar('s2', $datos, $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));
        $r = (new MigradorDelta)->ejecutar();
        DB::table('cf_certificados_legado')->where('id', $cert)->update(['visible_portal' => true]);

        try {
            (new RollbackDelta)->revertirLegado($r->corridaId);
            $this->fail('Debía bloquear');
        } catch (RollbackNoPermitido $e) {
            $this->assertSame(RollbackNoPermitido::CERTIFICADOS_MODIFICADOS, $e->codigo);
        }
        $this->assertSame('31003', (string) DB::table('cf_certificados_legado')->where('id', $cert)->value('codigo_legado'));
    }

    public function test_fila_ausente_rechaza_todo_sin_escribir_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $antes = $this->conteos();
        $this->cargar('s2', $this->sinFila($this->datosDelta(), 'participante', 5), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));

        try {
            (new MigradorDelta)->ejecutar();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::FILAS_AUSENTES, $e->codigo);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_grupo_duplicado_existente_se_rechaza_sin_escrituras_parciales_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $antes = $this->conteos();
        $datos = $this->datosDelta();
        $datos['participante'][] = ['id' => 20, 'tipo_documento' => 'CC', 'documento' => '2000002', 'nombre' => 'HUGO NUEVE', 'correo' => 'hugo@example.test', 'id_evento' => 1, 'num_verificacion' => null];
        $this->cargar('s2', $datos, $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));

        try {
            (new MigradorDelta)->ejecutar();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::GRUPO_EXISTENTE, $e->codigo);
            $this->assertSame(1, $e->detalle['participantes_nuevos_en_grupos_existentes']);
        }
        $this->assertSame($antes, $this->conteos());
    }

    public function test_estructura_de_encuesta_nueva_se_rechaza_sin_escritura_parcial_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $datos = $this->datosDelta();
        $datos['encuesta'][3]['pregunta1'] = 'Opinión inventada';
        $this->cargar('s2', $datos, $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));
        (new MigradorDelta)->ejecutar();
        $antes = $this->conteos();

        try {
            (new MigradorDeltaEncuestas)->ejecutar();
            $this->fail('Debía rechazar');
        } catch (DeltaRechazado $e) {
            $this->assertSame(DeltaRechazado::ESTRUCTURA_ENCUESTA_NUEVA, $e->codigo);
        }
        $this->assertSame($antes, $this->conteos());
        $this->assertSame(0, MigracionCorrida::where('tipo', MigracionCorrida::TIPO_ENCUESTAS_DELTA)->count());
    }

    public function test_imagen_con_el_mismo_sha_reutiliza_el_contenido_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $contenidos = DB::table('cf_plantillas_legado_contenidos')->count();
        $this->cargar('s2', $this->datosDelta(), $this->imagenes(['ETA 2026.png' => $this->png]));

        $r = (new MigradorDelta)->ejecutar();

        $this->assertSame($contenidos, DB::table('cf_plantillas_legado_contenidos')->count());
        $this->assertSame(1, $r->totales['contenidos']['reutilizados_por_sha']);
    }

    public function test_rollback_y_reaplicacion_reproducen_el_mismo_resultado_en_el_motor_real(): void
    {
        $this->baseMigrada();
        $base = $this->conteos();
        $this->cargar('s2', $this->datosDelta(), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));
        (new MigradorDelta)->ejecutar();
        $e1 = (new MigradorDeltaEncuestas)->ejecutar();
        $primera = $this->huellaSemantica();
        $l1 = (int) MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->value('id');

        (new RollbackDelta)->revertirEncuestas($e1->corridaId);
        (new RollbackDelta)->revertirLegado($l1);
        $tras = $this->conteos();
        $base['cf_migraciones_corridas'] += 2;
        $this->assertSame($base, $tras);
        $this->assertSame(2, MigracionCorrida::whereIn('tipo', [MigracionCorrida::TIPO_LEGADO, MigracionCorrida::TIPO_ENCUESTAS])->where('estado', 'completada')->count(), 'las corridas base siguen completadas');

        (new MigradorDelta)->ejecutar();
        (new MigradorDeltaEncuestas)->ejecutar();
        $this->assertSame($primera, $this->huellaSemantica());
    }

    /** Resultado semántico sin ids autoincrementales: debe ser idéntico entre MySQL y MariaDB y tras un rollback + reaplicación. */
    private function huellaSemantica(): array
    {
        $tipos = MigracionCorrida::pluck('tipo', 'id')->all();
        $porOld = [];
        foreach (DB::table('cf_migraciones_map')->where('origen_tabla', 'participante')->orderBy('id')->get(['corrida_id', 'origen_id', 'destino_id']) as $m) {
            if (($tipos[$m->corrida_id] ?? null) === null) {
                continue;
            }
            $c = DB::table('cf_certificados_legado')->where('id', $m->destino_id)->first();
            if ($c === null) {
                continue;
            }
            $porOld[(int) $m->origen_id] = [
                'tipo' => $tipos[$m->corrida_id], 'conc' => $c->conciliacion_estado, 'correo' => $c->correo_estado, 'codigo' => $c->codigo_legado,
                'plantilla' => $c->plantilla_legado_id !== null, 'grupo' => $c->grupo_duplicado !== null,
                'correos' => DB::table('cf_correos')->where('certificado_legado_id', $c->id)->count(),
                'descargas' => DB::table('cf_descargas')->where('certificado_legado_id', $c->id)->count(),
            ];
        }
        ksort($porOld);
        $mapa = [];
        foreach (DB::table('cf_migraciones_map')->orderBy('corrida_id')->get(['corrida_id', 'origen_tabla']) as $m) {
            $k = ($tipos[$m->corrida_id] ?? '?').':'.$m->origen_tabla;
            $mapa[$k] = ($mapa[$k] ?? 0) + 1;
        }
        ksort($mapa);
        $resp = DB::table('cf_encuestas_respuestas')->selectRaw('clasificacion, count(*) n')->groupBy('clasificacion')->orderBy('clasificacion')->pluck('n', 'clasificacion')->all();
        $totales = $this->conteos();
        unset($totales['cf_migraciones_corridas']);

        return ['certificados' => $porOld, 'mapa' => $mapa, 'respuestas' => $resp, 'totales' => $totales];
    }

    public function test_huella_semantica_del_motor(): void
    {
        $this->baseMigrada();
        $base = $this->huellaSemantica();
        $this->cargar('s2', $this->datosDelta(), $this->imagenes(['ETA 2026.png' => $this->nuevaImagen()]));
        $resultados = ['noop_inicial' => false];
        $r = (new MigradorDelta)->ejecutar();
        $e = (new MigradorDeltaEncuestas)->ejecutar();
        $resultados['delta'] = ['noOp' => $r->noOp, 'validaciones' => count($r->totales['validaciones']), 'descargas' => $r->totales['descargas']];
        $resultados['encuestas'] = ['noOp' => $e->noOp, 'respuestas' => $e->totales['respuestas'], 'detalles' => $e->totales['detalles']];
        $resultados['rerun'] = [(new MigradorDelta)->ejecutar()->noOp, (new MigradorDeltaEncuestas)->ejecutar()->noOp];
        $resultados['final'] = $this->huellaSemantica();
        $resultados['base'] = $base;
        $detector = app(DetectorConciliaciones::class);
        $d1 = $detector->ejecutar();
        $resultados['detector'] = [$d1['total']['nuevos'], $detector->ejecutar()['total']['nuevos'], DB::table('cf_conciliaciones')->count()];
        $resultados['preflight_codigos'] = (new PreflightCodigosHistoricos)->verificar()['ok'];

        $salida = (string) getenv('DELTA_HUELLA_SALIDA');
        if ($salida !== '') {
            file_put_contents($salida, json_encode($resultados, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
        $this->assertTrue($resultados['preflight_codigos']);
        $this->assertSame(0, $resultados['detector'][1]);
    }

    public function test_rehearsal_a_escala_de_produccion(): void
    {
        if (getenv('DELTA_ESCALA') !== '1') {
            $this->markTestSkipped('Prueba de escala: activa DELTA_ESCALA=1.');
        }
        $tiempos = [];
        $t = function (string $k, callable $f) use (&$tiempos) {
            $i = microtime(true);
            $r = $f();
            $tiempos[$k] = (int) round((microtime(true) - $i) * 1000);

            return $r;
        };

        // BASE sintética con las proporciones de la migración histórica real (sin imágenes: se pesan aparte).
        $eventos = [];
        for ($e = 1; $e <= 627; $e++) {
            $eventos[] = ['id' => $e, 'nombre' => "Evento sintético {$e} 20".(20 + $e % 7), 'imagen_certificado' => ''];
        }
        $part = [];
        for ($p = 1; $p <= 25917; $p++) {
            $part[] = ['id' => $p, 'tipo_documento' => 'CC', 'documento' => (string) (10000000 + $p), 'nombre' => "PERSONA {$p}", 'correo' => "p{$p}@example.test", 'id_evento' => 1 + $p % 627, 'num_verificacion' => $p % 5 === 0 ? 1000 + $p : null];
        }
        $desc = [];
        for ($d = 1; $d <= 10980; $d++) {
            $pid = 1 + ($d * 7) % 25917;
            $desc[] = ['id' => $d, 'fecha' => '2026-09-01 10:00:00', 'id_evento' => 1 + $pid % 627, 'id_participante' => $pid];
        }
        $enc = [];
        for ($n = 1; $n <= 3087; $n++) {
            $pid = 1 + ($n * 11) % 25917;
            $enc[] = [
                'id' => $n, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno', 'pregunta5' => 'Texto',
                'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '', 'id_evento' => 1 + $pid % 627, 'documento_participante' => (string) (10000000 + $pid), 'fecha' => '2026-02-01 09:00:00',
            ];
        }
        $datos = ['evento' => $eventos, 'participante' => $part, 'token' => [], 'descargas' => $desc, 'encuesta' => $enc,
            'preguntas_encuesta' => [['id' => 1, 'texto' => 'P', 'tipo_respuesta' => 'unica', 'num_opciones' => 2]],
            'opciones_respuesta' => [['id' => 1, 'opcion' => 'Excelente', 'id_pregunta' => 1], ['id' => 2, 'opcion' => 'Bueno', 'id_pregunta' => 1]]];

        $t('carga_staging_base', fn () => $this->cargar('base', $datos, []));
        $t('migrar_base_legado', fn () => (new MigradorHistorico)->ejecutar());
        $t('migrar_base_encuestas', fn () => (new MigradorEncuestas)->ejecutar());

        // DELTA con la composición observada: +1 evento (con imagen nueva), +29 participantes, +23 descargas (18 base + 5 delta), +1 encuesta, +9 tokens.
        $datos['evento'][] = ['id' => 628, 'nombre' => 'Evento sintético delta 2026', 'imagen_certificado' => 'DELTA NUEVO.png'];
        for ($i = 0; $i < 29; $i++) {
            $datos['participante'][] = ['id' => 30000 + $i, 'tipo_documento' => 'CC', 'documento' => (string) (90000000 + $i), 'nombre' => "NUEVA {$i}", 'correo' => "n{$i}@example.test", 'id_evento' => 628, 'num_verificacion' => $i < 4 ? 70000 + $i : null];
        }
        for ($i = 0; $i < 18; $i++) {
            $datos['descargas'][] = ['id' => 20000 + $i, 'fecha' => '2026-10-14 10:00:00', 'id_evento' => 1 + (1 + $i) % 627, 'id_participante' => 1 + $i];
        }
        for ($i = 0; $i < 5; $i++) {
            $datos['descargas'][] = ['id' => 20100 + $i, 'fecha' => '2026-10-14 11:00:00', 'id_evento' => 628, 'id_participante' => 30000 + $i];
        }
        for ($i = 0; $i < 9; $i++) {
            $datos['token'][] = ['id' => 500 + $i, 'codigo' => 'tok'.$i, 'fecha_creacion' => '2026-10-14 09:00:00', 'id_participante' => 30000 + $i];
        }
        $datos['encuesta'][] = [
            'id' => 3088, 'pregunta1' => 'Excelente', 'justificacion_pregunta1' => null, 'pregunta2' => 'Bueno', 'pregunta3' => 'Bueno', 'pregunta4' => 'Bueno', 'pregunta5' => 'Texto',
            'pregunta6' => '', 'pregunta7' => '', 'pregunta8' => '', 'pregunta9' => '', 'id_evento' => 628, 'documento_participante' => '90000000', 'fecha' => '2026-10-14 12:00:00',
        ];
        $dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'delta_escala_'.bin2hex(random_bytes(4));
        mkdir($dir);
        file_put_contents($dir.'/DELTA NUEVO.png', $this->nuevaImagen());
        $imagenes = (new EscanerImagenes)->escanear($dir);
        File::deleteDirectory($dir);

        $antes = $this->conteos();
        $t('carga_staging_snapshot_final', fn () => $this->cargar('final', $datos, $imagenes));
        $sim = $t('delta_legado_simular', fn () => (new MigradorDelta)->ejecutar(true));
        $t('delta_legado', fn () => (new MigradorDelta)->ejecutar());
        $t('delta_encuestas', fn () => (new MigradorDeltaEncuestas)->ejecutar());
        $despues = $this->conteos();
        $detector = app(DetectorConciliaciones::class);
        $t('detector_primera_pasada', fn () => $detector->ejecutar());
        $t('detector_idempotente', fn () => $detector->ejecutar());
        $t('verificar_preflight_codigos', fn () => (new PreflightCodigosHistoricos)->verificar());

        $this->assertTrue($sim->noOp);
        $this->assertSame(1, $despues['cf_eventos'] - $antes['cf_eventos']);
        $this->assertSame(29, $despues['cf_certificados_legado'] - $antes['cf_certificados_legado']);
        $this->assertSame(23, $despues['cf_descargas'] - $antes['cf_descargas']);
        $this->assertSame(1, $despues['cf_encuestas_respuestas'] - $antes['cf_encuestas_respuestas']);
        $this->assertSame(1, MigracionCorrida::where('tipo', MigracionCorrida::TIPO_LEGADO_DELTA)->where('estado', 'completada')->count());

        $salida = (string) (getenv('DELTA_ESCALA_SALIDA') ?: storage_path('logs/delta_escala.json'));
        file_put_contents($salida, json_encode(['motor' => DB::connection()->selectOne('select version() as v')->v, 'tiempos_ms' => $tiempos, 'base' => $antes, 'despues' => $despues], JSON_PRETTY_PRINT));
    }
}
