<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\Conciliacion;
use App\Support\CredentialFlow\Conciliaciones\DetectorGruposSinVia;
use App\Support\CredentialFlow\Identidad\AutorizacionMasiva;
use App\Support\CredentialFlow\Identidad\DecisionesIdentidad;
use App\Support\CredentialFlow\Identidad\EvidenciaIdentidad;
use App\Support\CredentialFlow\Portal\NombreConservador;
use App\Support\CredentialFlow\Portal\ServicioOtp;
use App\Support\CredentialFlow\Reemplazo\CoberturaCanonica;
use App\Support\CredentialFlow\Reemplazo\EmisionVigente;
use App\Support\CredentialFlow\Reemplazo\ReemplazoHistorico;
use App\Support\CredentialFlow\Reemplazo\ReglasValorAprobado;
use App\Support\CredentialFlow\Reemplazo\SolicitudReemplazo;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\Process\Process;
use Tests\Feature\CredentialFlow\Support\PdfBase;
use Tests\Feature\CredentialFlow\Support\PngSintetico;
use Tests\TestCase;

/**
 * Reemplazo de un certificado histórico por una emisión moderna (Fase 10B-2B-2A) con CONCURRENCIA REAL contra MySQL/MariaDB: varios procesos
 * intentan reemplazar el MISMO histórico (debe ganar uno), dos históricos distintos del mismo evento y plantilla (deben compartir un solo lote)
 * y la creación simultánea del clon de una plantilla histórica (debe quedar uno). Los PDFs se escriben en un disco temporal. Solo con una base
 * local de pruebas:
 *
 *   TEST_MYSQL_DATABASE=appfyc2025_fase1_test php artisan test tests/Integracion/ReemplazoHistoricoMysqlTest.php
 */
class ReemplazoHistoricoMysqlTest extends TestCase
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
        'database/migrations/2026_10_15_100000_cf_reemplazo_historico_infraestructura.php',
        'database/migrations/2026_10_15_100100_add_origen_legado_meta_to_cf_plantillas.php',
        'database/migrations/2026_10_09_100000_cf_accesos_otp_table.php',
        'database/migrations/2026_10_09_100200_add_grupo_hash_to_cf_accesos_otp_table.php',
        'database/migrations/2026_10_11_100000_cf_envios_tables.php',
        'database/migrations/2026_10_11_100100_ajusta_cf_accesos_otp_para_envios.php',
        'database/migrations/2026_10_16_100000_cf_decisiones_identidad_tables.php',
        'database/migrations/2026_10_17_100000_add_scope_to_cf_accesos_otp.php',
        'database/migrations/2026_10_18_100000_cf_decisiones_identidad_aprobaciones.php',
    ];

    private const TABLAS = ['cf_envios_intentos', 'cf_envios', 'cf_accesos_otp', 'cf_decisiones_identidad_aprobaciones', 'cf_decisiones_identidad_correos', 'cf_decisiones_identidad_grupos', 'cf_decisiones_identidad', 'perfil_organizadores', 'roles', 'usuarios', 'cf_codigos_historicos', 'cf_codigo_historico_contador', 'cf_conciliaciones_eventos', 'cf_conciliaciones_certificados', 'movimientos', 'cf_conciliaciones',
        'cf_migraciones_map', 'cf_encuestas_respuestas_detalle', 'cf_encuestas_respuestas', 'cf_encuestas_opciones', 'cf_encuestas_preguntas', 'cf_encuestas_versiones', 'cf_encuestas', 'cf_migraciones_corridas',
        'cf_correos', 'cf_descargas', 'cf_emisiones', 'cf_certificados_legado', 'cf_plantillas_legado', 'cf_plantillas_legado_contenidos', 'cf_participantes', 'cf_lotes', 'cf_eventos', 'cf_plantillas', 'migrations'];

    private string $base = '';

    private string $dir = '';

    private int $corrida = 0;

    private int $eventoId = 0;

    private int $plantillaLegado = 0;

    private int $plantillaModerna = 0;

    private int $actor = 0;

    private int $actor2 = 0;

    private int $actor3 = 0;

    private int $n = 0;

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

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reemplazo_mysql_'.bin2hex(random_bytes(6));
        mkdir($this->dir.DIRECTORY_SEPARATOR.'storage', 0777, true);
        mkdir($this->dir.DIRECTORY_SEPARATOR.'imagenes', 0777, true);
        $this->usarDisco();

        $this->limpiar();
        $this->assertSame(0, Artisan::call('migrate', ['--path' => self::MIGRACIONES, '--force' => true]), Artisan::output());
        // Tablas externas al módulo, mínimas y sin sus FK: `movimientos` y las del rol del actor.
        Schema::create('movimientos', function ($t) {
            $t->id();
            $t->unsignedBigInteger('user_id')->nullable();
            $t->string('tipo');
            $t->string('modulo');
            $t->text('descripcion');
            $t->json('metadata')->nullable();
            $t->timestamps();
        });
        Schema::create('roles', function ($t) {
            $t->id();
            $t->string('slug');
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('usuarios', function ($t) {
            $t->id();
            $t->timestamps();
            $t->softDeletes();
        });
        Schema::create('perfil_organizadores', function ($t) {
            $t->id();
            $t->unsignedBigInteger('usuario_id');
            $t->unsignedBigInteger('rol_id');
            $t->timestamps();
            $t->softDeletes();
        });
        $rol = DB::table('roles')->insertGetId(['slug' => 'admin', 'created_at' => now(), 'updated_at' => now()]);
        $this->actor = DB::table('usuarios')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
        DB::table('perfil_organizadores')->insert(['usuario_id' => $this->actor, 'rol_id' => $rol, 'created_at' => now(), 'updated_at' => now()]);
        // Segundo y tercer administrador (doble control de la autorización masiva, 10B-3C-3).
        foreach (['actor2', 'actor3'] as $otro) {
            $this->$otro = DB::table('usuarios')->insertGetId(['created_at' => now(), 'updated_at' => now()]);
            DB::table('perfil_organizadores')->insert(['usuario_id' => $this->$otro, 'rol_id' => $rol, 'created_at' => now(), 'updated_at' => now()]);
        }

        $this->sembrarBase();
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

    private function usarDisco(): void
    {
        config(['filesystems.disks.local.root' => $this->dir.DIRECTORY_SEPARATOR.'storage']);
        Storage::forgetDisk('local');
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

    private function sembrarBase(): void
    {
        $ahora = now();
        $im = imagecreatetruecolor(2, 2);
        ob_start();
        imagepng($im);
        $png = (string) ob_get_clean();
        file_put_contents($this->dir.'/imagenes/PLANTILLA.png', $png);

        $this->corrida = DB::table('cf_migraciones_corridas')->insertGetId(['tipo' => 'legado_evaluaciones', 'snapshot_sha256' => str_repeat('a', 64), 'huella_global' => str_repeat('b', 64), 'huella_derivada' => str_repeat('c', 64), 'estado' => 'completada', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $contenido = DB::table('cf_plantillas_legado_contenidos')->insertGetId(['sha256' => hash('sha256', $png), 'bytes' => strlen($png), 'mime_real' => 'image/png', 'ancho_px' => 2, 'alto_px' => 2, 'ruta_almacenada' => null, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $this->plantillaLegado = DB::table('cf_plantillas_legado')->insertGetId(['contenido_id' => $contenido, 'ruta_original' => 'document/certImages/PLANTILLA.png', 'nombre_original' => 'PLANTILLA.png', 'nombre_normalizado' => 'plantilla', 'extension_original' => 'png', 'renderizable' => 1, 'estado' => 'ok', 'created_at' => $ahora, 'updated_at' => $ahora]);
        $this->eventoId = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético', 'nombre_normalizado' => 'evento sintetico', 'origen' => 'legado', 'plantilla_legado_id' => $this->plantillaLegado, 'created_at' => $ahora, 'updated_at' => $ahora]);

        // Plantilla moderna con PDF base real (en el disco temporal) y diseño de nombre, documento y QR.
        $bytes = PdfBase::crear();
        $id = DB::table('cf_plantillas')->insertGetId(['nombre' => 'Moderna', 'archivo_pdf' => '', 'nombre_archivo_original' => 'base.pdf', 'hash_sha256' => hash('sha256', $bytes), 'schema_version' => 3, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $ruta = 'credential-flow/plantillas/'.$id.'/base.pdf';
        Storage::disk('local')->put($ruta, $bytes);
        $el = fn (string $campo, int $y, array $extra = []) => array_merge(['id' => sprintf('00000000-0000-4000-8000-%012d', ++$this->n), 'type' => 'text', 'field' => $campo, 'text' => '', 'x' => 96, 'y' => $y, 'width' => 600, 'height' => 40,
            'fontFamily' => 'outfit', 'fontSize' => 22, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center'], $extra);
        $diseno = ['page' => ['width' => 792, 'height' => 612], 'elements' => [$el('nombre_completo', 200), $el('documento', 260, ['prefix' => 'C.C. ']),
            ['id' => sprintf('00000000-0000-4000-8000-%012d', ++$this->n), 'type' => 'qr', 'x' => 640, 'y' => 460, 'width' => 96, 'height' => 96]]];
        DB::table('cf_plantillas')->where('id', $id)->update(['archivo_pdf' => $ruta, 'diseno' => json_encode($diseno)]);
        $this->plantillaModerna = $id;
    }

    /** Un histórico con documento de espacio de no separación y su caso abierto. @return array{0:int,1:int} caso, certificado */
    private function sembrarCaso(string $sufijo): array
    {
        $ahora = now();
        $doc = "\u{00A0}95000{$sufijo}";
        $cert = DB::table('cf_certificados_legado')->insertGetId([
            'corrida_id' => $this->corrida, 'evento_id' => $this->eventoId, 'plantilla_legado_id' => $this->plantillaLegado, 'tipo_documento' => 'CC', 'documento' => $doc, 'documento_clave' => '95000'.$sufijo,
            'nombre_completo' => 'PERSONA SINTETICA '.$sufijo, 'codigo_legado' => null, 'conciliacion_estado' => 'revision_documento', 'estado' => 'vigente',
            'snapshot_legado' => json_encode(['migracion' => ['old_id' => (int) $sufijo], 'documento_estado' => 'whitespace', 'motivos' => ['DOC_WHITESPACE_CAMBIA_IMPRESION']]), 'created_at' => $ahora, 'updated_at' => $ahora,
        ]);
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'revision_documento', 'estado' => 'abierto', 'evento_id' => $this->eventoId, 'referencia_tipo' => 'certificado_legado', 'referencia_clave' => (string) $cert,
            'motivo_origen' => 'DOC_WHITESPACE_CAMBIA_IMPRESION', 'clave_idempotencia' => 'revision_documento:certificado_legado:'.$cert, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $cert, 'rol' => 'variante', 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'detectado', 'created_at' => $ahora]);

        return [$caso, $cert];
    }

    /**
     * Lanza `$copias` procesos a la vez; cada uno ejecuta `$codigo` (PHP con `$argv[1]` = argumento) y devuelve su resultado.
     *
     * @param  list<string>  $argumentos  un argumento por proceso
     * @return list<array<string,mixed>>
     */
    private function concurrente(string $codigo, array $argumentos, string $memoria = '128M'): array
    {
        $raiz = var_export(base_path(), true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $disco = var_export($this->dir.DIRECTORY_SEPARATOR.'storage', true);
        $imagenes = var_export($this->dir.DIRECTORY_SEPARATOR.'imagenes', true);
        $actor = var_export($this->actor, true);
        $actor2 = var_export($this->actor2, true);
        $actor3 = var_export($this->actor3, true);
        $script = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        config(['filesystems.disks.local.root' => {$disco}]);
        Illuminate\\Support\\Facades\\Storage::forgetDisk('local');
        \$arg = \$argv[1]; \$imagenes = {$imagenes}; \$actor = {$actor}; \$actor2 = {$actor2}; \$actor3 = {$actor3};
        while (! file_exists({$go})) { usleep(2000); }
        try {
            \$r = (function () use (\$arg, \$imagenes, \$actor, \$actor2, \$actor3) { {$codigo} })();
            echo json_encode(['resultado' => 'ok', 'pico' => memory_get_peak_usage(true)] + (array) \$r);
        } catch (App\\Support\\CredentialFlow\\Conciliaciones\\ResolucionNoPermitida \$e) {
            echo json_encode(['resultado' => \$e->codigo, 'mensaje' => \$e->getMessage()]);
        } catch (Throwable \$e) {
            echo json_encode(['resultado' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 200)]);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador_'.bin2hex(random_bytes(3)).'.php';
        file_put_contents($ruta, $script);
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');

        $entorno = array_merge(getenv(), ['DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing']);
        $procesos = [];
        foreach ($argumentos as $arg) {
            // Memoria como en producción (memory_limit = 128M): ningún proceso puede apoyarse en un límite holgado.
            $p = new Process([PHP_BINARY, '-d', 'memory_limit='.$memoria, $ruta, $arg], base_path(), $entorno);
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

    private function llamadaReemplazo(): string
    {
        $plantilla = $this->plantillaModerna;

        return <<<PHP
        \$caso = (int) \$arg;
        \$s = new App\\Support\\CredentialFlow\\Reemplazo\\SolicitudReemplazo(plantillaId: {$plantilla}, reglaDocumento: App\\Support\\CredentialFlow\\Reemplazo\\ReglasValorAprobado::DOC_SIN_NBSP);
        return app(App\\Support\\CredentialFlow\\Reemplazo\\ReemplazoHistorico::class)->reemplazar(\$caso, \$actor, 'Documento con espacio de no separación confirmado.', \$s);
        PHP;
    }

    /** @return list<string> PDFs de emisiones en el disco temporal */
    private function pdfsEmitidos(): array
    {
        return array_values(array_filter(Storage::disk('local')->allFiles('credential-flow/emisiones'), fn ($f) => str_ends_with($f, '.pdf')));
    }

    public function test_cinco_procesos_reemplazando_el_mismo_historico_producen_una_sola_emision(): void
    {
        [$caso, $cert] = $this->sembrarCaso('1');

        $s = $this->concurrente($this->llamadaReemplazo(), array_fill(0, 5, (string) $caso));

        $cuenta = collect($s)->pluck('resultado')->countBy()->all();
        $this->assertSame(1, $cuenta['ok'] ?? 0, json_encode($s));
        $this->assertSame(4, ($cuenta['YA_REEMPLAZADO'] ?? 0) + ($cuenta['CASO_YA_RESUELTO'] ?? 0), json_encode($s));
        $this->assertLessThanOrEqual(96 * 1048576, collect($s)->max('pico'), 'con memory_limit=128M el pico PHP de cada proceso queda bajo 96 MB');
        $this->assertSame([1, 1, 1], [DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count()]);
        $fila = DB::table('cf_certificados_legado')->find($cert);
        $this->assertSame('reemplazado', $fila->estado);
        $this->assertSame((int) DB::table('cf_emisiones')->value('id'), (int) $fila->reemplazado_por_emision_id);
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', ReemplazoHistorico::RESOLUCION, $this->actor], [$f->estado, $f->resolucion, (int) $f->resuelto_por]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', ReemplazoHistorico::ACCION)->count());
        $this->assertSame(2, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->count());
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'conciliacion')->count());
        $this->assertSame(1, DB::table('cf_emisiones')->where('operacion', ReemplazoHistorico::operacion($cert))->count());
        $this->assertCount(1, $this->pdfsEmitidos(), 'los perdedores no dejan PDFs');
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
    }

    public function test_dos_historicos_distintos_del_mismo_evento_y_plantilla_comparten_un_unico_lote(): void
    {
        [$casoA, $certA] = $this->sembrarCaso('2');
        [$casoB, $certB] = $this->sembrarCaso('3');

        $s = $this->concurrente($this->llamadaReemplazo(), [(string) $casoA, (string) $casoA, (string) $casoA, (string) $casoB, (string) $casoB]);

        $cuenta = collect($s)->pluck('resultado')->countBy()->all();
        $this->assertSame(2, $cuenta['ok'] ?? 0, json_encode($s));
        $this->assertSame(3, ($cuenta['YA_REEMPLAZADO'] ?? 0) + ($cuenta['CASO_YA_RESUELTO'] ?? 0), json_encode($s));
        $this->assertLessThanOrEqual(96 * 1048576, collect($s)->max('pico'));
        $this->assertSame(1, DB::table('cf_lotes')->count(), 'un solo lote para evento + plantilla');
        $this->assertSame([2, 2], [DB::table('cf_participantes')->count(), DB::table('cf_emisiones')->count()]);
        $this->assertSame(2, DB::table('cf_participantes')->where('lote_id', DB::table('cf_lotes')->value('id'))->count());
        foreach ([$certA, $certB] as $cert) {
            $this->assertSame('reemplazado', DB::table('cf_certificados_legado')->where('id', $cert)->value('estado'));
        }
        $this->assertCount(2, $this->pdfsEmitidos());
        $this->assertSame(2, DB::table('cf_emisiones')->distinct()->count('codigo'));
    }

    public function test_cinco_procesos_creando_el_clon_de_la_misma_imagen_de_337_mp_con_128_mb_dejan_una_sola_plantilla(): void
    {
        [, $cert] = $this->sembrarCaso('4');
        // La imagen histórica es de 6600×5100 (33,7 MP): antes exigía ~350 MB por proceso; ahora se incrusta sin decodificar.
        $png = PngSintetico::crear(6600, 5100);
        file_put_contents($this->dir.'/imagenes/PLANTILLA.png', $png);
        DB::table('cf_plantillas_legado_contenidos')->update(['sha256' => hash('sha256', $png), 'bytes' => strlen($png), 'ancho_px' => 6600, 'alto_px' => 5100]);
        $llamada = <<<'PHP'
        $c = App\Models\CredentialFlow\CertificadoLegado::findOrFail((int) $arg);
        $creador = new App\Support\CredentialFlow\Reemplazo\CreadorPlantillaReemplazo(app(App\Services\CredentialFlow\PlantillaService::class), new App\Support\CredentialFlow\Legado\ResolutorPlantillaDirectorio($imagenes));
        $r = $creador->paraCertificado($c);
        return ['plantilla_id' => $r['plantilla']->id, 'creada' => $r['creada']];
        PHP;

        $s = $this->concurrente($llamada, array_fill(0, 5, (string) $cert));

        $this->assertSame(['ok' => 5], collect($s)->pluck('resultado')->countBy()->all(), json_encode($s));
        $this->assertLessThanOrEqual(96 * 1048576, collect($s)->max('pico'), 'cinco procesos a la vez, cada uno bajo 96 MB');
        $clones = DB::table('cf_plantillas')->whereNotNull('origen_legado_sha256')->get();
        $this->assertCount(1, $clones);
        $meta = json_decode((string) $clones[0]->origen_legado_meta, true);
        $this->assertSame(['incrustacion_directa', hash('sha256', $png), 6600, 5100], [$meta['algoritmo'], $meta['sha256_origen'], $meta['ancho_px_derivado'], $meta['alto_px_derivado']]);
        $this->assertSame(hash('sha256', Storage::disk('local')->get($clones[0]->archivo_pdf)), $clones[0]->hash_sha256);
        $this->assertSame([(int) $clones[0]->id], collect($s)->pluck('plantilla_id')->unique()->values()->all());
        $this->assertSame(1, collect($s)->where('creada', true)->count(), 'solo un proceso lo crea; los demás lo reutilizan');
        // Sin carpetas huérfanas de los intentos perdedores: solo la carpeta del clon y la de la plantilla moderna sembrada.
        $carpetas = collect(Storage::disk('local')->directories('credential-flow/plantillas'))->map(fn ($d) => basename($d))->sort()->values()->all();
        $this->assertEqualsCanonicalizing([(string) $this->plantillaModerna, (string) $clones[0]->id], $carpetas);
    }

    public function test_el_reemplazo_completo_funciona_con_el_esquema_real_y_la_cadena_se_resuelve(): void
    {
        [$caso, $cert] = $this->sembrarCaso('5');
        $r = app(ReemplazoHistorico::class)->reemplazar($caso, $this->actor, 'Documento con espacio de no separación confirmado.',
            new SolicitudReemplazo(plantillaId: $this->plantillaModerna, reglaDocumento: ReglasValorAprobado::DOC_SIN_NBSP));

        $res = EmisionVigente::paraCertificado($cert);
        $this->assertSame([$r['emision_id']], $res['cadena']);
        $this->assertSame($r['emision_id'], $res['vigente']->id);
        $this->assertSame(
            ['accion' => ReemplazoHistorico::ACCION, 'len' => 33],
            ['accion' => DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->orderByDesc('id')->value('accion'), 'len' => strlen(ReemplazoHistorico::ACCION)],
            'el nombre del evento (33 caracteres) cabe en la columna real'
        );
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    /** Un certificado lógico de DOS filas (mismo grupo, cada una con su caso); la raíz la marca el mapa. @return array{0:int,1:int,2:int,3:int} caso raíz, cert raíz, caso variante, cert variante */
    private function sembrarPar(string $sufijo): array
    {
        [$casoRaiz, $certRaiz] = $this->sembrarCaso($sufijo);
        $ahora = now();
        DB::table('cf_certificados_legado')->where('id', $certRaiz)->update(['grupo_duplicado' => 'grupo-par-'.$sufijo]);
        $fila = (array) DB::table('cf_certificados_legado')->where('id', $certRaiz)->first();
        unset($fila['id']);
        $certVar = DB::table('cf_certificados_legado')->insertGetId($fila);
        $casoVar = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'revision_documento', 'estado' => 'abierto', 'evento_id' => $this->eventoId, 'referencia_tipo' => 'certificado_legado', 'referencia_clave' => (string) $certVar,
            'motivo_origen' => 'DOC_WHITESPACE_CAMBIA_IMPRESION', 'clave_idempotencia' => 'revision_documento:certificado_legado:'.$certVar, 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $casoVar, 'certificado_legado_id' => $certVar, 'rol' => 'variante', 'created_at' => $ahora, 'updated_at' => $ahora]);
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $casoVar, 'accion' => 'detectado', 'created_at' => $ahora]);
        foreach ([[$certRaiz, 'canonico'], [$certVar, 'duplicado_identico']] as [$dest, $rel]) {
            DB::table('cf_migraciones_map')->insert(['corrida_id' => $this->corrida, 'origen_tabla' => 'participante', 'origen_id' => 'par-'.$sufijo.'-'.$dest, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $dest,
                'relacion' => $rel, 'detalle' => null, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        return [$casoRaiz, $certRaiz, $casoVar, $certVar];
    }

    public function test_cinco_procesos_entrando_por_la_raiz_y_por_la_variante_producen_un_solo_reemplazo_logico(): void
    {
        [$casoRaiz, $certRaiz, $casoVar, $certVar] = $this->sembrarPar('6');

        $s = $this->concurrente($this->llamadaReemplazo(), [(string) $casoRaiz, (string) $casoVar, (string) $casoRaiz, (string) $casoVar, (string) $casoVar]);

        $cuenta = collect($s)->pluck('resultado')->countBy()->all();
        $this->assertSame(1, $cuenta['ok'] ?? 0, json_encode($s));
        $rechazos = ($cuenta['YA_REEMPLAZADO'] ?? 0) + ($cuenta['YA_REEMPLAZADO_LOGICAMENTE'] ?? 0) + ($cuenta['VARIANTE_NO_CANONICA'] ?? 0) + ($cuenta['CASO_YA_RESUELTO'] ?? 0);
        $this->assertSame(4, $rechazos, 'cuatro rechazos controlados, ningún error técnico: '.json_encode($s));
        $this->assertLessThanOrEqual(96 * 1048576, collect($s)->max('pico'));

        // Una emisión, un participante, un lote, una cadena moderna.
        $this->assertSame([1, 1, 1], [DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count()]);
        $emision = (int) DB::table('cf_emisiones')->value('id');
        $raiz = DB::table('cf_certificados_legado')->find($certRaiz);
        $variante = DB::table('cf_certificados_legado')->find($certVar);
        $this->assertSame(['reemplazado', $emision], [$raiz->estado, (int) $raiz->reemplazado_por_emision_id]);
        $this->assertSame(['vigente', null], [$variante->estado, $variante->reemplazado_por_emision_id], 'solo la raíz lleva el enlace');
        $this->assertSame(1, DB::table('cf_emisiones')->where('operacion', ReemplazoHistorico::operacion($certRaiz))->count());
        $this->assertSame(0, DB::table('cf_emisiones')->where('operacion', ReemplazoHistorico::operacion($certVar))->count());
        $this->assertCount(1, $this->pdfsEmitidos());
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'conciliacion')->count());

        // Casos: el de la raíz, resuelto con el reemplazo; el de la variante, cubierto UNA sola vez (aunque su proceso perdiera la carrera).
        $this->assertSame(['resuelto', ReemplazoHistorico::RESOLUCION], [DB::table('cf_conciliaciones')->find($casoRaiz)->estado, DB::table('cf_conciliaciones')->find($casoRaiz)->resolucion]);
        $cv = DB::table('cf_conciliaciones')->find($casoVar);
        $this->assertSame(['resuelto', CoberturaCanonica::RESOLUCION], [$cv->estado, $cv->resolucion], 'resolucion cabe en la columna real: '.strlen(CoberturaCanonica::RESOLUCION));
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $casoVar)->where('accion', CoberturaCanonica::ACCION)->count());
        $this->assertSame(0, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $casoRaiz)->where('accion', CoberturaCanonica::ACCION)->count());
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    // ── DIF_NOMBRE con nombre aprobado (10B-3C-2) ────────────────────────────

    /** Un certificado lógico de dos variantes con nombres distintos (mismo documento, evento y grupo) y su caso DIF_NOMBRE. @return array{0:int,1:int,2:int} caso, raíz, variante */
    private function sembrarDifNombre(string $sufijo): array
    {
        $ahora = now();
        $fila = fn (string $nombre) => [
            'corrida_id' => $this->corrida, 'evento_id' => $this->eventoId, 'plantilla_legado_id' => $this->plantillaLegado, 'tipo_documento' => 'CC', 'documento' => '95100'.$sufijo, 'documento_clave' => '95100'.$sufijo,
            'nombre_completo' => $nombre, 'codigo_legado' => null, 'conciliacion_estado' => 'pendiente_conciliacion', 'estado' => 'vigente', 'grupo_duplicado' => 'dif-nombre-'.$sufijo,
            'snapshot_legado' => json_encode(['migracion' => ['old_id' => (int) $sufijo], 'duplicado' => ['etiquetas' => 'DIF_NOMBRE']]), 'created_at' => $ahora, 'updated_at' => $ahora,
        ];
        $raiz = DB::table('cf_certificados_legado')->insertGetId($fila('ANA LUZ SINTETICA'));
        $variante = DB::table('cf_certificados_legado')->insertGetId($fila('ANA LUZ SINTETICA DOS'));
        foreach ([[$raiz, 'canonico'], [$variante, 'variante_conflictiva']] as [$dest, $rel]) {
            DB::table('cf_migraciones_map')->insert(['corrida_id' => $this->corrida, 'origen_tabla' => 'participante', 'origen_id' => 'dif-'.$sufijo.'-'.$dest, 'destino_tabla' => 'cf_certificados_legado', 'destino_id' => $dest,
                'relacion' => $rel, 'detalle' => null, 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'conflicto_variantes', 'estado' => 'abierto', 'evento_id' => $this->eventoId, 'referencia_tipo' => 'documento', 'referencia_clave' => '95100'.$sufijo,
            'motivo_origen' => 'DIF_NOMBRE', 'clave_idempotencia' => 'conflicto_variantes:dif-nombre:'.$sufijo, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach ([$raiz, $variante] as $cert) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $cert, 'rol' => 'variante', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        DB::table('cf_conciliaciones_eventos')->insert(['conciliacion_id' => $caso, 'accion' => 'detectado', 'created_at' => $ahora]);

        return [$caso, $raiz, $variante];
    }

    private function llamadaDifNombre(): string
    {
        $plantilla = $this->plantillaModerna;

        return <<<PHP
        [\$caso, \$nombre] = explode('|', \$arg, 2);
        \$s = new App\\Support\\CredentialFlow\\Reemplazo\\SolicitudReemplazo(plantillaId: {$plantilla}, reglaDocumento: App\\Support\\CredentialFlow\\Reemplazo\\ReglasValorAprobado::DOC_SIN_CAMBIO,
            reglaNombre: App\\Support\\CredentialFlow\\Reemplazo\\ReglasValorAprobado::NOMBRE_CONFIRMADO, valorNombre: \$nombre, confirmado: true, evidencia: 'Acta firmada por la persona, folio 12 de prueba.');
        return app(App\\Support\\CredentialFlow\\Reemplazo\\ReemplazoHistorico::class)->reemplazar((int) \$caso, \$actor, 'Nombre aprobado con evidencia documental.', \$s);
        PHP;
    }

    public function test_cinco_procesos_aprobando_nombres_distintos_para_el_mismo_dif_nombre_producen_una_sola_emision(): void
    {
        [$caso, $raiz, $variante] = $this->sembrarDifNombre('1');

        $s = $this->concurrente($this->llamadaDifNombre(), [$caso.'|ANA LUZ SINTETICA', $caso.'|ANA LUZ SINTETICA DOS', $caso.'|ANA LUZ SINTETICA', $caso.'|ANA LUZ SINTETICA DOS', $caso.'|ANA LUZ SINTETICA']);

        $cuenta = collect($s)->pluck('resultado')->countBy()->all();
        $this->assertSame(1, $cuenta['ok'] ?? 0, json_encode($s));
        $this->assertSame(4, ($cuenta['YA_REEMPLAZADO'] ?? 0) + ($cuenta['CASO_YA_RESUELTO'] ?? 0) + ($cuenta['YA_REEMPLAZADO_LOGICAMENTE'] ?? 0), 'rechazos controlados, ningún error técnico: '.json_encode($s));
        $this->assertLessThanOrEqual(96 * 1048576, collect($s)->max('pico'), 'con memory_limit=128M el pico PHP de cada proceso queda bajo 96 MB');

        $this->assertSame([1, 1, 1], [DB::table('cf_emisiones')->count(), DB::table('cf_participantes')->count(), DB::table('cf_lotes')->count()]);
        $emision = (int) DB::table('cf_emisiones')->value('id');
        $filaRaiz = DB::table('cf_certificados_legado')->find($raiz);
        $filaVar = DB::table('cf_certificados_legado')->find($variante);
        $this->assertSame(['reemplazado', $emision], [$filaRaiz->estado, (int) $filaRaiz->reemplazado_por_emision_id]);
        $this->assertSame(['vigente', null], [$filaVar->estado, $filaVar->reemplazado_por_emision_id], 'la variante no lleva enlace duplicado');
        $this->assertContains(DB::table('cf_participantes')->value('nombre_completo'), ['ANA LUZ SINTETICA', 'ANA LUZ SINTETICA DOS'], 'el nombre impreso es exactamente el aprobado por el ganador');
        $this->assertSame(1, DB::table('cf_emisiones')->where('operacion', ReemplazoHistorico::operacion($raiz))->count(), 'un solo linaje');
        $this->assertSame(0, DB::table('cf_emisiones')->where('operacion', ReemplazoHistorico::operacion($variante))->count());
        $this->assertCount(1, $this->pdfsEmitidos(), 'los perdedores no dejan PDFs');
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso)->where('accion', ReemplazoHistorico::ACCION)->count());
        $f = DB::table('cf_conciliaciones')->find($caso);
        $this->assertSame(['resuelto', ReemplazoHistorico::RESOLUCION], [$f->estado, $f->resolucion]);
        $this->assertSame(1, DB::table('movimientos')->where('tipo', 'conciliacion')->count());
        $this->assertSame(0, DB::table('cf_codigos_historicos')->count());
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_dos_administradores_entrando_a_dif_nombre_con_el_mismo_nombre_dejan_una_sola_emision_idempotente(): void
    {
        [$caso, $raiz] = $this->sembrarDifNombre('2');

        $s = $this->concurrente($this->llamadaDifNombre(), [$caso.'|ANA LUZ SINTETICA DOS', $caso.'|ANA LUZ SINTETICA DOS']);
        $cuenta = collect($s)->pluck('resultado')->countBy()->all();
        $this->assertSame(1, $cuenta['ok'] ?? 0, json_encode($s));
        $this->assertSame(1, DB::table('cf_emisiones')->count());
        $this->assertSame('ANA LUZ SINTETICA DOS', DB::table('cf_participantes')->value('nombre_completo'));

        // Un reintento posterior no crea otra emisión.
        $otra = $this->concurrente($this->llamadaDifNombre(), [$caso.'|ANA LUZ SINTETICA DOS']);
        $this->assertNotSame('ok', $otra[0]['resultado']);
        $this->assertSame(1, DB::table('cf_emisiones')->where('operacion', ReemplazoHistorico::operacion($raiz))->count());
    }

    // ── Decisiones de identidad (10B-3A) ─────────────────────────────────────

    /** Un documento con DOS grupos de nombre que comparten el único correo, y su caso de identidad. @return array{caso:int,grupos:list<string>,correo:string} */
    private function sembrarIdentidad(string $sufijo): array
    {
        $ahora = now();
        $clave = '96100'.$sufijo;
        $correo = "compartido{$sufijo}@example.test";
        $certs = [];
        $evento2 = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sintético 2 '.$sufijo, 'nombre_normalizado' => 'evento sintetico 2 '.$sufijo, 'origen' => 'legado', 'plantilla_legado_id' => $this->plantillaLegado, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach (['PERSONA UNO '.$sufijo, 'PERSONA UNO '.$sufijo.'B'] as $k => $nombre) {
            $certs[] = DB::table('cf_certificados_legado')->insertGetId([
                'corrida_id' => $this->corrida, 'evento_id' => $k === 0 ? $this->eventoId : $evento2, 'plantilla_legado_id' => $this->plantillaLegado, 'tipo_documento' => 'CC', 'documento' => $clave, 'documento_clave' => $clave,
                'nombre_completo' => $nombre, 'conciliacion_estado' => 'ok', 'estado' => 'vigente', 'snapshot_legado' => json_encode(['migracion' => ['old_id' => 1]]), 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
        }
        foreach ($certs as $c) {
            DB::table('cf_correos')->insert(['certificado_legado_id' => $c, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento($clave),
            'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:'.$sufijo, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach ($certs as $i => $c) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $c, 'rol' => 'grupo_'.($i + 1), 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        return ['caso' => $caso, 'grupos' => array_keys(EvidenciaIdentidad::de(Conciliacion::findOrFail($caso))['por_hash']), 'correo' => $correo];
    }

    private function llamadaDecision(): string
    {
        return <<<'PHP'
        $p = json_decode($arg, true);
        return app(App\Support\CredentialFlow\Identidad\DecisionesIdentidad::class)->crear($p['caso'], $actor, $p['tipo'], 'Motivo concurrente de prueba.', $p['p']);
        PHP;
    }

    public function test_dos_administradores_con_decisiones_contradictorias_dejan_una_sola_vigente(): void
    {
        $s = $this->sembrarIdentidad('1');
        $misma = json_encode(['caso' => $s['caso'], 'tipo' => 'misma_persona', 'p' => ['grupos' => $s['grupos'], 'evidencia' => 'Evidencia externa suficiente para la prueba.', 'confirmo' => true, 'evidencia_externa' => true]]);
        $distintas = json_encode(['caso' => $s['caso'], 'tipo' => 'personas_distintas', 'p' => ['grupos' => $s['grupos'], 'confirmo' => true]]);

        $r = $this->concurrente($this->llamadaDecision(), [$misma, $distintas, $misma, $distintas]);

        $this->assertSame(2, collect($r)->where('resultado', 'ok')->count(), json_encode($r));
        $this->assertSame(2, collect($r)->where('resultado', 'DECISION_CONFLICTIVA')->count(), json_encode($r));
        $this->assertCount(1, collect($r)->where('resultado', 'ok')->pluck('tipo')->unique(), 'las dos «ok» son la MISMA decisión (una creada y su repetición idempotente)');
        $this->assertSame(1, collect($r)->where('resultado', 'ok')->where('creada', true)->count());
        $this->assertSame([1, 1], [DB::table('cf_decisiones_identidad')->where('estado', 'vigente')->count(), DB::table('cf_decisiones_identidad')->count()]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $s['caso'])->where('accion', 'identidad_decision_creada')->count());
    }

    public function test_cinco_procesos_con_la_misma_decision_no_la_duplican(): void
    {
        $s = $this->sembrarIdentidad('2');
        $arg = json_encode(['caso' => $s['caso'], 'tipo' => 'requiere_soporte', 'p' => []]);

        $r = $this->concurrente($this->llamadaDecision(), array_fill(0, 5, $arg));

        $this->assertSame(5, collect($r)->where('resultado', 'ok')->count(), json_encode($r));
        $this->assertSame([1, 4], [collect($r)->where('creada', true)->count(), collect($r)->where('creada', false)->count()]);
        $this->assertSame([1, 1], [DB::table('cf_decisiones_identidad')->count(), DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_decision_creada')->count()]);
        $this->assertSame('requiere_soporte', DB::table('cf_conciliaciones')->find($s['caso'])->estado);
    }

    public function test_el_mismo_correo_no_se_autoriza_a_dos_grupos_en_paralelo(): void
    {
        $s = $this->sembrarIdentidad('3');
        $hmac = EvidenciaIdentidad::hashCorreo($s['correo']);
        $para = fn (string $g) => json_encode(['caso' => $s['caso'], 'tipo' => 'correo_autorizado', 'p' => ['grupo' => $g, 'correo_hmac' => $hmac, 'evidencia' => 'Evidencia externa suficiente para la prueba.', 'reforzada' => true]]);

        $r = $this->concurrente($this->llamadaDecision(), [$para($s['grupos'][0]), $para($s['grupos'][1]), $para($s['grupos'][0]), $para($s['grupos'][1])]);

        $this->assertSame(1, collect($r)->where('creada', true)->count(), json_encode($r));
        $this->assertSame(2, collect($r)->where('resultado', 'DECISION_CONFLICTIVA')->count());
        $this->assertSame([1, 1], [DB::table('cf_decisiones_identidad_correos')->where('vigente', 1)->count(), DB::table('cf_decisiones_identidad')->count()]);
    }

    public function test_cuatro_revocaciones_simultaneas_de_la_misma_decision_revocan_una_vez(): void
    {
        $s = $this->sembrarIdentidad('4');
        $d = app(DecisionesIdentidad::class)->crear($s['caso'], $this->actor, 'requiere_soporte', 'Motivo de prueba suficiente.');
        $revocar = <<<'PHP'
        return app(App\Support\CredentialFlow\Identidad\DecisionesIdentidad::class)->revocar((int) $arg, $actor, 'Se revoca por la prueba.');
        PHP;

        $r = $this->concurrente($revocar, array_fill(0, 4, (string) $d['decision_id']));

        $this->assertSame(1, collect($r)->where('resultado', 'ok')->count(), json_encode($r));
        $this->assertSame(3, collect($r)->where('resultado', 'DECISION_YA_REVOCADA')->count());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('accion', 'identidad_decision_revocada')->count());
        $this->assertSame('abierto', DB::table('cf_conciliaciones')->find($s['caso'])->estado);
        $fila = DB::table('cf_decisiones_identidad')->first();
        $this->assertSame([null, null, 'revocada'], [$fila->vigente_clave, $fila->vigente_caso_clave, $fila->estado]);
    }

    // ── Identidad aprobada: OTP con scope congelado frente a la revocación (10B-3B-1) ──

    private function llamadaOtp(): string
    {
        return <<<'PHP'
        Illuminate\Support\Facades\Mail::fake();
        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.correo.habilitado' => false]);
        $p = json_decode($arg, true);
        if ($p['accion'] === 'solicitar') {
            return ['emitido' => app(App\Support\CredentialFlow\Portal\ServicioOtp::class)->solicitar($p['clave'], $p['correo'], '10.0.0.7', 'ua')];
        }
        if ($p['accion'] === 'revocar') {
            return app(App\Support\CredentialFlow\Identidad\DecisionesIdentidad::class)->revocar($p['decision'], $actor, 'Se revoca durante la solicitud.');
        }
        return app(App\Support\CredentialFlow\Identidad\DecisionesIdentidad::class)->crear($p['caso'], $actor, 'misma_persona', 'Se decide durante la solicitud.', $p['p']);
        PHP;
    }

    private function claveDe(int $caso): string
    {
        return (string) DB::table('cf_certificados_legado as k')->join('cf_conciliaciones_certificados as p', 'p.certificado_legado_id', '=', 'k.id')->where('p.conciliacion_id', $caso)->value('k.documento_clave');
    }

    public function test_solicitar_el_otp_mientras_otro_proceso_revoca_nunca_deja_un_scope_valido_tras_la_revocacion(): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => true]);
        $svc = app(DecisionesIdentidad::class);
        foreach (['5', '6', '7'] as $sufijo) {
            $s = $this->sembrarIdentidad($sufijo);
            $dec = $svc->crear($s['caso'], $this->actor, 'misma_persona', 'Motivo de prueba suficiente.', ['grupos' => $s['grupos'], 'evidencia' => 'Evidencia suficiente para la prueba.', 'confirmo' => true, 'evidencia_externa' => true])['decision_id'];
            $clave = $this->claveDe($s['caso']);
            $sol = json_encode(['accion' => 'solicitar', 'clave' => $clave, 'correo' => $s['correo']]);
            $rev = json_encode(['accion' => 'revocar', 'decision' => $dec]);

            $r = $this->concurrente($this->llamadaOtp(), [$sol, $rev, $sol]);

            $this->assertSame(0, collect($r)->whereIn('resultado', ['EXCEPCION', 'SIN_SALIDA'])->count(), json_encode($r));
            $this->assertSame('revocada', DB::table('cf_decisiones_identidad')->find($dec)->estado);
            // Sea cual sea el entrelazado, un OTP emitido con ese scope NO se puede validar tras la revocación.
            foreach (DB::table('cf_accesos_otp')->get() as $fila) {
                DB::table('cf_accesos_otp')->where('id', $fila->id)->update(['otp_hash' => Hash::make('123456'), 'usado_at' => null, 'invalidado_at' => null, 'bloqueado_at' => null, 'intentos' => 0, 'expires_at' => now()->addMinutes(5)]);
                $this->assertNull(app(ServicioOtp::class)->validar($clave, $s['correo'], '123456'), 'el OTP con scope revocado no valida');
                $this->assertNull(DB::table('cf_accesos_otp')->find($fila->id)->usado_at);
            }
            DB::table('cf_accesos_otp')->delete();
        }
    }

    public function test_solicitar_mientras_otro_proceso_decide_deja_un_otp_coherente_con_el_estado_final(): void
    {
        config(['credential_flow.identidad.decisiones_enabled' => true]);
        foreach (['8', '9'] as $sufijo) {
            $s = $this->sembrarIdentidad($sufijo);
            $clave = $this->claveDe($s['caso']);
            $sol = json_encode(['accion' => 'solicitar', 'clave' => $clave, 'correo' => $s['correo']]);
            $crear = json_encode(['accion' => 'crear', 'caso' => $s['caso'], 'p' => ['grupos' => $s['grupos'], 'evidencia' => 'Evidencia suficiente para la prueba.', 'confirmo' => true, 'evidencia_externa' => true]]);

            $r = $this->concurrente($this->llamadaOtp(), [$sol, $crear, $sol]);

            $this->assertSame(0, collect($r)->whereIn('resultado', ['EXCEPCION', 'SIN_SALIDA'])->count(), json_encode($r));
            $this->assertSame(1, DB::table('cf_decisiones_identidad')->where('conciliacion_id', $s['caso'])->where('estado', 'vigente')->count());
            $filas = DB::table('cf_accesos_otp')->get();
            $this->assertLessThanOrEqual(1, $filas->count(), 'el límite de 60 s deja un solo desafío');
            foreach ($filas as $f) {
                // Un desafío solo existe si, en su momento, el scope estaba aprobado: y entonces coincide con el estado final (la decisión sigue vigente).
                $this->assertNotNull($f->scope_hash);
                DB::table('cf_accesos_otp')->where('id', $f->id)->update(['otp_hash' => Hash::make('123456'), 'expires_at' => now()->addMinutes(5)]);
                $v = app(ServicioOtp::class)->validar($clave, $s['correo'], '123456');
                $this->assertSame($f->scope_hash, $v['scope']->scopeHash);
            }
            DB::table('cf_accesos_otp')->delete();
        }
    }

    // ── Sesión con scope aprobado: revocación efectiva frente a peticiones concurrentes (10B-3B-2) ──

    private function llamadaSesion(): string
    {
        return <<<'PHP'
        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true]);
        $p = json_decode($arg, true);
        if ($p['accion'] === 'sondear') {
            $store = new Illuminate\Session\Store('t', new Illuminate\Session\ArraySessionHandler(120));
            $scope = app(App\Support\CredentialFlow\Identidad\ScopeIdentidadAprobado::class)->resolver($p['clave'], $p['correo']);
            if (! App\Support\CredentialFlow\Portal\SesionPortal::iniciarAprobado($store, $p['clave'], $scope)) {
                return ['sesion' => false];
            }
            $lecturas = [];
            $fin = microtime(true) + 1.6;
            while (microtime(true) < $fin) {
                $t = microtime(true);
                $lecturas[] = [$t, App\Support\CredentialFlow\Portal\SesionPortal::contexto($store) !== null];
                usleep(1500);
            }
            return ['sesion' => true, 'lecturas' => $lecturas];
        }
        usleep(500000);   // deja que el sondeo arranque
        $svc = app(App\Support\CredentialFlow\Identidad\DecisionesIdentidad::class);
        $svc->revocar($p['decision'], $actor, 'Se revoca durante las peticiones.');
        $commit = microtime(true);
        if ($p['accion'] === 'sustituir') {
            $svc->crear($p['caso'], $actor, 'misma_persona', 'Se decide de nuevo durante las peticiones.', $p['p']);
        }
        return ['commit' => $commit];
        PHP;
    }

    /** @return array{0:array<string,mixed>,1:array<string,mixed>} sondeo, escritor */
    private function sesionContraRevocacion(string $sufijo, bool $sustituir): array
    {
        config(['credential_flow.identidad.decisiones_enabled' => true]);
        $s = $this->sembrarIdentidad($sufijo);
        $p = ['grupos' => $s['grupos'], 'evidencia' => 'Evidencia suficiente para la prueba.', 'confirmo' => true, 'evidencia_externa' => true];
        $dec = app(DecisionesIdentidad::class)->crear($s['caso'], $this->actor, 'misma_persona', 'Motivo de prueba suficiente.', $p)['decision_id'];
        $clave = $this->claveDe($s['caso']);

        $r = $this->concurrente($this->llamadaSesion(), [
            json_encode(['accion' => 'sondear', 'clave' => $clave, 'correo' => $s['correo']]),
            json_encode(['accion' => $sustituir ? 'sustituir' : 'revocar', 'decision' => $dec, 'caso' => $s['caso'], 'p' => $p]),
        ]);
        foreach ($r as $x) {
            $this->assertNotContains($x['resultado'], ['EXCEPCION', 'SIN_SALIDA'], json_encode($x));
        }

        return [$r[0], $r[1]];
    }

    public function test_peticiones_concurrentes_con_sesion_aprobada_nunca_usan_el_scope_tras_el_commit_de_la_revocacion(): void
    {
        foreach (['10', '11'] as $sufijo) {
            [$sondeo, $escritor] = $this->sesionContraRevocacion($sufijo, false);

            $this->assertTrue($sondeo['sesion']);
            $commit = $escritor['commit'];
            $antes = array_filter($sondeo['lecturas'], fn ($l) => $l[0] < $commit - 0.05);
            $despues = array_filter($sondeo['lecturas'], fn ($l) => $l[0] > $commit);
            $this->assertNotEmpty($antes);
            $this->assertNotEmpty($despues);
            $this->assertNotEmpty(array_filter($antes, fn ($l) => $l[1]), 'antes de revocar, la sesión sirve');
            $this->assertEmpty(array_filter($despues, fn ($l) => $l[1]), 'tras el commit de la revocación NINGUNA petición nueva usa el scope');
        }
    }

    public function test_revocar_la_decision_y_crear_otra_con_el_mismo_scope_no_deja_ventana_a_la_sesion_vieja(): void
    {
        [$sondeo, $escritor] = $this->sesionContraRevocacion('12', true);

        $commit = $escritor['commit'];
        $this->assertEmpty(array_filter($sondeo['lecturas'], fn ($l) => $l[0] > $commit && $l[1]), 'los ids y claves son parte del contrato: la decisión 2 NO rescata la sesión de la 1');
        $this->assertSame(2, DB::table('cf_decisiones_identidad')->count());
        $this->assertSame(1, DB::table('cf_decisiones_identidad')->where('estado', 'vigente')->count());
    }

    // ── Autorización masiva con doble control (10B-3C-3) ─────────────────────

    private const FLAGS_MASA = "config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true, 'credential_flow.identidad.mass_scope_enabled' => true, 'credential_flow.correo.habilitado' => false]);";

    /**
     * Réplica del documento de 624: un grupo grande (100 certificados) y una fila suelta que comparten el único correo, y su caso de identidad.
     *
     * @return array{caso:int,grande:string,suelta:string,correo:string,clave:string}
     */
    private function sembrarMasivo(string $sufijo): array
    {
        $ahora = now();
        $clave = '96400'.$sufijo;
        $correo = "masivo{$sufijo}@example.test";
        $evento2 = DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento masivo '.$sufijo, 'nombre_normalizado' => 'evento masivo '.$sufijo, 'origen' => 'legado', 'plantilla_legado_id' => $this->plantillaLegado, 'created_at' => $ahora, 'updated_at' => $ahora]);
        $certs = [];
        $fila = fn (string $nombre, int $evento) => [
            'corrida_id' => $this->corrida, 'evento_id' => $evento, 'plantilla_legado_id' => $this->plantillaLegado, 'tipo_documento' => 'CC', 'documento' => $clave, 'documento_clave' => $clave,
            'nombre_completo' => $nombre, 'conciliacion_estado' => 'ok', 'estado' => 'vigente', 'snapshot_legado' => json_encode(['migracion' => ['old_id' => 1]]), 'created_at' => $ahora, 'updated_at' => $ahora,
        ];
        for ($i = 0; $i < 100; $i++) {
            $certs[] = DB::table('cf_certificados_legado')->insertGetId($fila('PERSONA MASIVA '.$sufijo, $this->eventoId));
        }
        $certs[] = DB::table('cf_certificados_legado')->insertGetId($fila('PERSONA MASIVA '.$sufijo.'X', $evento2));
        foreach ($certs as $c) {
            DB::table('cf_correos')->insert(['certificado_legado_id' => $c, 'correo' => $correo, 'correo_normalizado' => $correo, 'estado' => 'valido', 'orden' => 1, 'es_principal' => 1, 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }
        $caso = DB::table('cf_conciliaciones')->insertGetId(['tipo' => 'identidad_ambigua', 'estado' => 'abierto', 'referencia_tipo' => 'documento', 'referencia_clave' => EvidenciaIdentidad::hashDocumento($clave),
            'motivo_origen' => 'CORREO_CRUZA_GRUPOS', 'clave_idempotencia' => 'identidad_ambigua:documento:m'.$sufijo, 'created_at' => $ahora, 'updated_at' => $ahora]);
        foreach ($certs as $i => $c) {
            DB::table('cf_conciliaciones_certificados')->insert(['conciliacion_id' => $caso, 'certificado_legado_id' => $c, 'rol' => $i < 100 ? 'grupo_1' : 'grupo_2', 'created_at' => $ahora, 'updated_at' => $ahora]);
        }

        return ['caso' => $caso, 'grande' => NombreConservador::grupoId('PERSONA MASIVA '.$sufijo), 'suelta' => NombreConservador::grupoId('PERSONA MASIVA '.$sufijo.'X'), 'correo' => $correo, 'clave' => $clave];
    }

    /** ADMIN A (el actor base) solicita la autorización masiva del grupo grande. @return int id de la decisión */
    private function solicitarMasivo(array $s): int
    {
        config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true, 'credential_flow.identidad.mass_scope_enabled' => true]);
        $r = app(DecisionesIdentidad::class)->crear($s['caso'], $this->actor, 'correo_autorizado', 'Solicitud masiva de prueba.', ['grupo' => $s['grande'], 'correo_hmac' => EvidenciaIdentidad::hashCorreo($s['correo']),
            'evidencia' => 'Certificación del organizador sobre la propiedad del buzón.', 'reforzada' => true, 'alcance_masivo' => true, 'evidencia_externa' => true, 'fuente_evidencia' => 'certificacion_organizador']);
        $this->assertTrue($r['aprobacion_pendiente']);

        return $r['decision_id'];
    }

    private function llamadaMasiva(): string
    {
        $flags = self::FLAGS_MASA;

        return <<<PHP
        Illuminate\\Support\\Facades\\Mail::fake();
        {$flags}
        \$p = json_decode(\$arg, true);
        \$masiva = app(App\\Support\\CredentialFlow\\Identidad\\AutorizacionMasiva::class);
        if (\$p['accion'] === 'aprobar') {
            return \$masiva->aprobar(\$p['decision'], \$p['actor'] === 'A' ? \$actor : (\$p['actor'] === 'B' ? \$actor2 : \$actor3), 'Aprobación concurrente de prueba.', true);
        }
        if (\$p['accion'] === 'revocar_aprobacion') {
            return \$masiva->revocarAprobacion(\$p['decision'], \$actor2, 'Se retira la aprobación durante la solicitud.');
        }
        if (\$p['accion'] === 'solicitar') {
            return ['emitido' => app(App\\Support\\CredentialFlow\\Portal\\ServicioOtp::class)->solicitar(\$p['clave'], \$p['correo'], '10.0.0.8', 'ua')];
        }
        PHP;
    }

    public function test_dos_aprobadores_a_la_vez_dejan_una_sola_aprobacion_efectiva_y_el_solicitante_no_puede(): void
    {
        $s = $this->sembrarMasivo('1');
        $dec = $this->solicitarMasivo($s);
        $ap = fn (string $quien) => json_encode(['accion' => 'aprobar', 'decision' => $dec, 'actor' => $quien]);

        $r = $this->concurrente($this->llamadaMasiva(), [$ap('B'), $ap('C'), $ap('B'), $ap('C'), $ap('A')]);

        $cuenta = collect($r)->pluck('resultado')->countBy()->all();
        $this->assertSame(1, $cuenta['ok'] ?? 0, json_encode($r));
        // El solicitante (A) JAMÁS aprueba: según llegue, recibe SEGUNDO_APROBADOR_REQUERIDO o YA_APROBADA (ya aprobada manda); el resto, YA_APROBADA.
        $this->assertSame(4, ($cuenta['YA_APROBADA'] ?? 0) + ($cuenta['SEGUNDO_APROBADOR_REQUERIDO'] ?? 0), json_encode($r));
        $this->assertNotSame('ok', $r[4]['resultado'], 'el solicitante no aprueba su propia solicitud');
        $this->assertSame(0, collect($r)->whereIn('resultado', ['EXCEPCION', 'SIN_SALIDA'])->count(), json_encode($r));
        $this->assertSame(1, DB::table('cf_decisiones_identidad_aprobaciones')->count());
        $fila = DB::table('cf_decisiones_identidad_aprobaciones')->first();
        $this->assertSame('aprobada', $fila->estado);
        $this->assertContains((int) $fila->aprobada_por, [$this->actor2, $this->actor3]);
        $this->assertNotSame((int) $fila->solicitada_por, (int) $fila->aprobada_por);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $s['caso'])->where('accion', 'identidad_autorizacion_masiva_aprobada')->count());
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $s['caso'])->where('accion', 'identidad_acceso_aplicado')->count());
        $c = DB::table('cf_conciliaciones')->find($s['caso']);
        $this->assertSame(['resuelto', 'identidad_aplicada'], [$c->estado, $c->resolucion]);
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_solicitar_el_otp_mientras_otro_proceso_revoca_la_aprobacion_nunca_deja_un_scope_valido_tras_la_revocacion(): void
    {
        foreach (['2', '3'] as $sufijo) {
            $s = $this->sembrarMasivo($sufijo);
            $dec = $this->solicitarMasivo($s);
            app(AutorizacionMasiva::class)->aprobar($dec, $this->actor2, 'Aprobación previa de la prueba.', true);
            $sol = json_encode(['accion' => 'solicitar', 'clave' => $s['clave'], 'correo' => $s['correo']]);
            $rev = json_encode(['accion' => 'revocar_aprobacion', 'decision' => $dec]);

            $r = $this->concurrente($this->llamadaMasiva(), [$sol, $rev, $sol]);

            $this->assertSame(0, collect($r)->whereIn('resultado', ['EXCEPCION', 'SIN_SALIDA'])->count(), json_encode($r));
            $this->assertSame('revocada', DB::table('cf_decisiones_identidad_aprobaciones')->where('decision_id', $dec)->value('estado'));
            $this->assertSame('vigente', DB::table('cf_decisiones_identidad')->find($dec)->estado, 'solo se revocó la aprobación');
            foreach (DB::table('cf_accesos_otp')->get() as $fila) {
                DB::table('cf_accesos_otp')->where('id', $fila->id)->update(['otp_hash' => Hash::make('123456'), 'usado_at' => null, 'invalidado_at' => null, 'bloqueado_at' => null, 'intentos' => 0, 'expires_at' => now()->addMinutes(5)]);
                config(['credential_flow.identidad.decisiones_enabled' => true, 'credential_flow.identidad.multi_scope_enabled' => true, 'credential_flow.identidad.mass_scope_enabled' => true]);
                $this->assertNull(app(ServicioOtp::class)->validar($s['clave'], $s['correo'], '123456'), 'el OTP con una aprobación revocada no valida');
                $this->assertNull(DB::table('cf_accesos_otp')->find($fila->id)->usado_at);
            }
            DB::table('cf_accesos_otp')->delete();
        }
    }

    public function test_peticiones_concurrentes_con_sesion_masiva_nunca_usan_el_scope_tras_el_commit_de_la_revocacion_de_la_aprobacion(): void
    {
        $s = $this->sembrarMasivo('4');
        $dec = $this->solicitarMasivo($s);
        app(AutorizacionMasiva::class)->aprobar($dec, $this->actor2, 'Aprobación previa de la prueba.', true);
        $flags = self::FLAGS_MASA;
        $codigo = <<<PHP
        {$flags}
        \$p = json_decode(\$arg, true);
        if (\$p['accion'] === 'sondear') {
            \$store = new Illuminate\\Session\\Store('t', new Illuminate\\Session\\ArraySessionHandler(120));
            \$scope = app(App\\Support\\CredentialFlow\\Identidad\\ScopeIdentidadAprobado::class)->resolver(\$p['clave'], \$p['correo']);
            if (! App\\Support\\CredentialFlow\\Portal\\SesionPortal::iniciarAprobado(\$store, \$p['clave'], \$scope)) {
                return ['sesion' => false];
            }
            \$lecturas = [];
            \$fin = microtime(true) + 2.2;
            while (microtime(true) < \$fin) {
                \$t = microtime(true);
                \$lecturas[] = [\$t, App\\Support\\CredentialFlow\\Portal\\SesionPortal::contexto(\$store) !== null];
                usleep(1500);
            }
            return ['sesion' => true, 'lecturas' => \$lecturas, 'pico' => memory_get_peak_usage(true)];
        }
        usleep(800000);
        app(App\\Support\\CredentialFlow\\Identidad\\AutorizacionMasiva::class)->revocarAprobacion(\$p['decision'], \$actor2, 'Se retira la aprobación durante las peticiones.');
        return ['commit' => microtime(true)];
        PHP;

        $r = $this->concurrente($codigo, [json_encode(['accion' => 'sondear', 'clave' => $s['clave'], 'correo' => $s['correo']]), json_encode(['accion' => 'revocar', 'decision' => $dec])]);

        foreach ($r as $x) {
            $this->assertNotContains($x['resultado'], ['EXCEPCION', 'SIN_SALIDA'], json_encode($x));
        }
        $this->assertTrue($r[0]['sesion']);
        $commit = $r[1]['commit'];
        $antes = array_filter($r[0]['lecturas'], fn ($l) => $l[0] < $commit - 0.05);
        $despues = array_filter($r[0]['lecturas'], fn ($l) => $l[0] > $commit);
        $this->assertNotEmpty(array_filter($antes, fn ($l) => $l[1]), 'antes de revocar, la sesión masiva sirve');
        $this->assertNotEmpty($despues);
        $this->assertEmpty(array_filter($despues, fn ($l) => $l[1]), 'tras el commit de la revocación NINGUNA petición nueva usa el scope masivo');
        $this->assertLessThanOrEqual(96 * 1048576, $r[0]['pico'], 'con memory_limit=128M el scope masivo no agota la memoria');
    }

    // ── Grupos sin vía (10B-3C-1): autorizaciones concurrentes sobre casos del detector ──

    /** Documento parcial: S entra con `c` (exclusivo) y comparte `x` con `$objetivos` grupos sin vía. @return array{caso:int,grupos:list<string>,correo:string} */
    private function sembrarSinVia(string $sufijo, int $objetivos): array
    {
        $ahora = now();
        $clave = '96300'.$sufijo;
        $correo = "x{$sufijo}@example.test";
        $nombres = ['PERSONA SV '.$sufijo];
        for ($i = 1; $i <= $objetivos; $i++) {
            $nombres[] = 'PERSONA SV '.$sufijo.chr(64 + $i);
        }
        $hashes = [];
        foreach ($nombres as $k => $nombre) {
            $evento = $k === 0 ? $this->eventoId : DB::table('cf_eventos')->insertGetId(['nombre' => 'Evento sv '.$sufijo.$k, 'nombre_normalizado' => 'evento sv '.$sufijo.$k, 'origen' => 'legado', 'plantilla_legado_id' => $this->plantillaLegado, 'created_at' => $ahora, 'updated_at' => $ahora]);
            $cert = DB::table('cf_certificados_legado')->insertGetId([
                'corrida_id' => $this->corrida, 'evento_id' => $evento, 'plantilla_legado_id' => $this->plantillaLegado, 'tipo_documento' => 'CC', 'documento' => $clave, 'documento_clave' => $clave,
                'nombre_completo' => $nombre, 'conciliacion_estado' => 'ok', 'estado' => 'vigente', 'snapshot_legado' => json_encode(['migracion' => ['old_id' => 1]]), 'created_at' => $ahora, 'updated_at' => $ahora,
            ]);
            foreach ($k === 0 ? ["c{$sufijo}@example.test", $correo] : [$correo] as $i => $mail) {
                DB::table('cf_correos')->insert(['certificado_legado_id' => $cert, 'correo' => $mail, 'correo_normalizado' => $mail, 'estado' => 'valido', 'orden' => $i + 1, 'es_principal' => 0, 'origen' => 'legado', 'created_at' => $ahora, 'updated_at' => $ahora]);
            }
            $hashes[] = NombreConservador::grupoId($nombre);
        }
        app(DetectorGruposSinVia::class)->ejecutar();
        $caso = (int) DB::table('cf_conciliaciones')->where('referencia_clave', EvidenciaIdentidad::hashDocumento($clave))->where('motivo_origen', 'GRUPO_SIN_VIA_CORREO_COMPARTIDO')->value('id');

        return ['caso' => $caso, 'grupos' => array_slice($hashes, 1), 'correo' => $correo, 'hermano' => $hashes[0]];
    }

    private function autorizacion(array $s, string $grupo): string
    {
        return json_encode(['caso' => $s['caso'], 'tipo' => 'correo_autorizado', 'p' => ['grupo' => $grupo, 'correo_hmac' => EvidenciaIdentidad::hashCorreo($s['correo']), 'evidencia' => 'Evidencia externa suficiente para la prueba.', 'reforzada' => true]]);
    }

    public function test_cuatro_administradores_autorizando_el_mismo_correo_al_mismo_grupo_dejan_una_decision(): void
    {
        $s = $this->sembrarSinVia('1', 1);
        $this->assertGreaterThan(0, $s['caso']);

        $r = $this->concurrente($this->llamadaDecision(), array_fill(0, 4, $this->autorizacion($s, $s['grupos'][0])));

        $this->assertSame(4, collect($r)->where('resultado', 'ok')->count(), json_encode($r));
        $this->assertSame([1, 3], [collect($r)->where('creada', true)->count(), collect($r)->where('creada', false)->count()]);
        $this->assertSame([1, 1], [DB::table('cf_decisiones_identidad')->count(), DB::table('cf_decisiones_identidad_correos')->where('vigente', 1)->count()]);
        $this->assertSame(1, DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $s['caso'])->where('accion', 'identidad_decision_creada')->count());
    }

    public function test_la_autorizacion_valida_frente_a_un_intento_sobre_el_hermano_deja_una_sola_vigente(): void
    {
        $s = $this->sembrarSinVia('2', 1);

        // El mismo correo hacia el grupo objetivo (válido) y hacia el hermano accesible (no es objetivo): solo la primera puede registrarse.
        $r = $this->concurrente($this->llamadaDecision(), [$this->autorizacion($s, $s['grupos'][0]), $this->autorizacion($s, $s['hermano']), $this->autorizacion($s, $s['grupos'][0]), $this->autorizacion($s, $s['hermano'])]);

        $this->assertSame(0, collect($r)->whereIn('resultado', ['EXCEPCION', 'SIN_SALIDA'])->count(), json_encode($r));
        $this->assertSame(1, collect($r)->where('creada', true)->count());
        $this->assertSame(2, collect($r)->where('resultado', 'GRUPO_NO_PERTENECE')->count(), 'el hermano accesible jamás se autoriza por esta vía');
        $this->assertSame([1, 1], [DB::table('cf_decisiones_identidad')->where('estado', 'vigente')->count(), DB::table('cf_decisiones_identidad_correos')->where('vigente', 1)->count()]);
    }
}
