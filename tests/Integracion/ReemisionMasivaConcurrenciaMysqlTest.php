<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Emisiones\CambioPlantillaEmitida;
use App\Support\CredentialFlow\Emisiones\EmisorLote;
use App\Support\CredentialFlow\Emisiones\ReemisorBase;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Qa\ServicioQa;
use Database\Seeders\AreaFormacionSeeder;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\EstadosSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\TipoDocumentosSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\PdfBase;
use Tests\TestCase;

/**
 * Corrección de certificados emitidos con CONCURRENCIA REAL: procesos PHP independientes contra MySQL/MariaDB real (los bloqueos de fila y el índice único
 * `participante_vigente` solo se prueban de verdad aquí), y el recorrido completo (JSON de MariaDB incluido) del cambio de plantilla y la reemisión masiva.
 * Datos ficticios; storage en una carpeta temporal; nunca SMTP ni red. Solo con una base local de pruebas, por ejemplo la MariaDB 10.6 desechable del puerto 3307:
 *
 *   DB_HOST=127.0.0.1 DB_PORT=3307 DB_USERNAME=root DB_PASSWORD= TEST_MYSQL_DATABASE=cf_reemision_test php artisan test tests/Integracion/ReemisionMasivaConcurrenciaMysqlTest.php
 */
class ReemisionMasivaConcurrenciaMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private const FIJO_NUEVO = 'por su asistencia a la';

    private const MOTIVO = 'Corrección de redacción (prueba de concurrencia)';

    private string $base = '';

    private string $dir = '';

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
            'database.connections.'.self::CONEXION => [...$config, 'database' => $this->base],
            'database.default' => self::CONEXION,
        ]);
        DB::purge(self::CONEXION);
        // Red de seguridad: lo que sigue BORRA tablas; solo en una base de pruebas.
        $this->assertMatchesRegularExpression('/_(dev|test|testing)$/', (string) DB::connection()->getDatabaseName());
        $this->assertNotSame(config('database.connections.mysql.database'), DB::connection()->getDatabaseName());

        $this->assertSame(0, Artisan::call('migrate:fresh', ['--force' => true]), Artisan::output());
        foreach ([EstadosSeeder::class, TipoDocumentosSeeder::class, AreaFormacionSeeder::class,
            EquipoSeeder::class, RolSeeder::class, UsuarioSeeder::class] as $seeder) {
            $this->assertSame(0, Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]));
        }

        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'reemision_mysql_'.bin2hex(random_bytes(6));
        foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $d) {
            mkdir($this->dir.DIRECTORY_SEPARATOR.$d, 0777, true);
        }
        config(['filesystems.disks.local.root' => $this->dir.'/storage/app/private', 'credential_flow.emision.exige_vista_previa' => false,
            'credential_flow.qa.exigir_catalogo' => false, 'credential_flow.qa.contactos_esperados' => 8]);
        Storage::forgetDisk('local');

        for ($i = 1; $i <= 8; $i++) {
            $doc = (string) (70000000 + $i);
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA SINTÉTICO {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
                'correo' => "qa{$i}@example.test", 'correo_normalizado' => "qa{$i}@example.test", 'activo' => true, 'created_at' => now(), 'updated_at' => now()]);
        }
    }

    protected function tearDown(): void
    {
        if ($this->dir !== '') {
            File::deleteDirectory($this->dir);
        }
        parent::tearDown();
    }

    private function admin(): Usuario
    {
        return Usuario::where('correo_principal', 'erik@fycconsultores.com')->firstOrFail();
    }

    /** Plantilla institucional con un texto FIJO (como la #15 de producción) y su variante QA. */
    private function plantilla(string $fijo): Plantilla
    {
        $bytes = PdfBase::crear();
        $p = Plantilla::create(['nombre' => 'Plantilla '.uniqid(), 'descripcion' => 'Prueba', 'archivo_pdf' => 'pendiente', 'nombre_archivo_original' => 'base.pdf', 'hash_sha256' => hash('sha256', $bytes)]);
        Storage::disk('local')->put($p->rutaPdfEsperada(), $bytes);
        $elementos = [];
        foreach (['nombre_completo', 'documento', 'evento', 'fecha', 'intensidad_horaria', null] as $i => $campo) {
            $elementos[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i + 1), 'type' => 'text', 'field' => $campo, 'text' => $campo === null ? $fijo : '', 'x' => 96.5, 'y' => 60 + $i * 60,
                'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 16, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center'];
        }
        $p->update(['archivo_pdf' => $p->rutaPdfEsperada(), 'diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos], 'schema_version' => 1]);
        $this->assertSame(0, Artisan::call('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true]), Artisan::output());

        return $p->fresh();
    }

    /** Base emitida (oficiales + 8 QA) con la plantilla «al», y ya cambiada (autorizado) a la plantilla «a la»: lista para reemitir. */
    private function baseParaReemitir(int $reales): Lote
    {
        $lote = Lote::create(['plantilla_id' => $this->plantilla('por su asistencia al')->id, 'nombre' => 'Base concurrencia '.uniqid(),
            'datos_comunes' => ['evento' => 'JORNADA DE CONCURRENCIA', 'fecha' => '14 de octubre de 2026', 'intensidad_horaria' => '5 horas']]);
        for ($i = 1; $i <= $reales; $i++) {
            $doc = 'C.C. '.number_format(50_000_000 + $lote->id * 1000 + $i, 0, ',', '.');
            $lote->participantes()->create(['nombre_completo' => "PERSONA FICTICIA {$i}", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc)]);
        }
        app(ServicioQa::class)->incorporar($lote);
        app(EmisorLote::class)->emitirTodo($lote->fresh(), $this->admin()->id);

        $nueva = $this->plantilla(self::FIJO_NUEVO);
        $plan = app(CambioPlantillaEmitida::class)->planear($lote->fresh(), $nueva);
        app(CambioPlantillaEmitida::class)->ejecutar($lote->fresh(), $nueva, $plan['huella'], self::MOTIVO, $this->admin()->id);

        return $lote->fresh();
    }

    private function escribirTrabajador(): string
    {
        $raiz = var_export(base_path(), true);
        $go = var_export($this->dir.DIRECTORY_SEPARATOR.'go', true);
        $codigo = <<<PHP
        <?php
        require {$raiz}.'/vendor/autoload.php';
        \$app = require {$raiz}.'/bootstrap/app.php';
        \$app->make(Illuminate\\Contracts\\Console\\Kernel::class)->bootstrap();
        [, \$modo, \$loteId, \$a, \$b, \$retraso] = \$argv + [null, null, null, '', '', '0'];
        \$admin = App\\Models\\Usuario::where('correo_principal', 'erik@fycconsultores.com')->firstOrFail();
        \$lote = App\\Models\\CredentialFlow\\Lote::findOrFail((int) \$loteId);
        while (! file_exists({$go})) { usleep(2000); }
        usleep(1000 * (int) \$retraso);
        try {
            if (\$modo === 'reemitir') {
                \$r = app(App\\Support\\CredentialFlow\\Emisiones\\ReemisorBase::class)->reemitir(\$lote, \$a, \$b, 'Corrección concurrente de prueba', \$admin->id);
                echo json_encode(['ok' => true, 'total' => \$r['total']]);
            } elseif (\$modo === 'comando') {
                \$c = Illuminate\\Support\\Facades\\Artisan::call('credential-flow:base-reemitir', ['base' => \$lote->id, '--usuario' => \$admin->id, '--motivo' => 'Corrección concurrente de prueba', '--plan' => \$a, '--ejecutar' => true]);
                \$salida = Illuminate\\Support\\Facades\\Artisan::output();
                preg_match('/\\b([A-Z][A-Z_]{5,})\\b:/', \$salida, \$m);
                echo json_encode(['ok' => \$c === 0, 'codigo' => \$m[1] ?? null], JSON_INVALID_UTF8_SUBSTITUTE);
            } elseif (\$modo === 'cambiar') {
                \$p = App\\Models\\CredentialFlow\\Plantilla::findOrFail((int) \$a);
                app(App\\Support\\CredentialFlow\\Emisiones\\CambioPlantillaEmitida::class)->ejecutar(\$lote, \$p, \$b, 'Cambio concurrente de prueba', \$admin->id);
                echo json_encode(['ok' => true]);
            } else {
                \$e = App\\Models\\CredentialFlow\\Emision::findOrFail((int) \$a);
                \$n = app(App\\Support\\CredentialFlow\\Emisiones\\EmisorCredencial::class)->reemitir(\$e, 'Reemisión individual concurrente', \$admin->id);
                echo json_encode(['ok' => true, 'id' => \$n->id]);
            }
        } catch (App\\Support\\CredentialFlow\\Emisiones\\EmisionException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e->codigo]);
        } catch (Throwable \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 400)], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /**
     * @param  list<list<string>>  $argumentos
     * @return list<array<string,mixed>>
     */
    private function enParalelo(array $argumentos, string $cache = 'array'): array
    {
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => $cache, 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->dir.DIRECTORY_SEPARATOR.'storage', 'CREDENTIAL_FLOW_EXIGE_VISTA_PREVIA' => 'false',
        ]);
        $procesos = [];
        foreach ($argumentos as $args) {
            $p = proc_open([PHP_BINARY, $script, ...$args], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, base_path(), $entorno);
            $this->assertIsResource($p);
            $procesos[] = [$p, $pipes];
        }
        usleep(2_500_000);
        file_put_contents($this->dir.DIRECTORY_SEPARATOR.'go', '1');

        $salidas = [];
        foreach ($procesos as [$p, $pipes]) {
            $salida = stream_get_contents($pipes[1]);
            $error = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $codigo = proc_close($p);
            $json = json_decode((string) $salida, true);
            $this->assertIsArray($json, "salida inesperada de un trabajador (código de salida {$codigo}): ".substr($salida.' | '.$error, 0, 1500));
            $salidas[] = $json;
        }

        return $salidas;
    }

    /** Invariantes que deben cumplirse SIEMPRE, gane quien gane: una vigente por persona, versiones sin saltos ni repetidas, archivos y huellas coherentes. */
    private function assertCoherente(Lote $lote, int $personas): void
    {
        $this->assertSame($personas, Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->count(), 'una vigente por persona');
        $this->assertSame($personas, Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->distinct()->count('participante_id'));
        $repetidas = DB::table('cf_emisiones')->where('lote_id', $lote->id)->selectRaw('participante_id, version')->groupBy('participante_id', 'version')->havingRaw('COUNT(*) > 1')->get()->count();
        $this->assertSame(0, $repetidas, 'ninguna versión repetida');
        $this->assertSame(0, Artisan::call('credential-flow:verificar-emisiones'), Artisan::output());
    }

    public function test_recorrido_completo_en_mariadb_y_repetir_no_duplica(): void
    {
        $lote = $this->baseParaReemitir(4);
        $simular = fn () => [Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id]), Artisan::output()];
        [$c, $sim] = $simular();
        $this->assertSame(0, $c, $sim);
        $this->assertMatchesRegularExpression('/OFICIALES .*vigentes 4 · desactualizados 4 .*a reemitir ahora 4/', $sim);
        $this->assertMatchesRegularExpression('/QA .*vigentes 8 · desactualizados 8 .*a reemitir ahora 8/', $sim);
        preg_match('/Huella del plan: ([0-9a-f]{64})/', $sim, $m);

        $this->assertSame(0, Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id, '--usuario' => $this->admin()->id, '--motivo' => self::MOTIVO, '--plan' => $m[1], '--ejecutar' => true]), Artisan::output());

        $this->assertSame(12, Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->where('version', 2)->count());
        $this->assertSame(12, Emision::where('lote_id', $lote->id)->where('estado', Emision::REVOCADA)->where('motivo_revocacion', 'Reemplazada por la versión 2. Motivo: '.self::MOTIVO)->count());
        // Con el JSON de MariaDB (LONGTEXT) los snapshots recién guardados se reconocen al día: la segunda simulación no encuentra nada.
        [$c, $sim] = $simular();
        $this->assertSame(0, $c, $sim);
        $this->assertMatchesRegularExpression('/OFICIALES .*desactualizados 0/', $sim);
        $this->assertMatchesRegularExpression('/QA .*desactualizados 0/', $sim);
        $this->assertCoherente($lote, 12);
    }

    public function test_dos_reemisiones_masivas_a_la_vez_sin_cerrojo_comun_solo_una_reemite(): void
    {
        $lote = $this->baseParaReemitir(6);
        $huella = app(ReemisorBase::class)->planear($lote, ReemisorBase::OFICIALES)['huella'];

        // CACHE_STORE=array: cada proceso tiene su propio cerrojo (como si fallara la caché). La protección es la de la base de datos.
        $salidas = $this->enParalelo([['reemitir', (string) $lote->id, ReemisorBase::OFICIALES, $huella, '0'], ['reemitir', (string) $lote->id, ReemisorBase::OFICIALES, $huella, '0']]);

        $ganadores = array_values(array_filter($salidas, fn ($s) => $s['ok']));
        $this->assertCount(1, $ganadores, json_encode($salidas));
        $this->assertSame(6, $ganadores[0]['total']);
        $perdedor = array_values(array_filter($salidas, fn ($s) => ! $s['ok']))[0];
        $this->assertContains($perdedor['codigo'], ['DATOS_CAMBIARON_DURANTE_EMISION', 'PLAN_DESACTUALIZADO'], json_encode($salidas));
        $this->assertSame(6, Emision::where('lote_id', $lote->id)->where('version', 2)->count(), 'nadie recibió dos versiones nuevas');
        $this->assertCoherente($lote, 14);
    }

    public function test_dos_administradores_con_el_comando_y_cerrojo_compartido(): void
    {
        $lote = $this->baseParaReemitir(3);
        Artisan::call('credential-flow:base-reemitir', ['base' => $lote->id]);
        preg_match('/Huella del plan: ([0-9a-f]{64})/', Artisan::output(), $m);

        // CACHE_STORE=file: el cerrojo de la base es común a los dos procesos.
        $salidas = $this->enParalelo([['comando', (string) $lote->id, $m[1], '', '0'], ['comando', (string) $lote->id, $m[1], '', '0']], 'file');

        $this->assertCount(1, array_filter($salidas, fn ($s) => $s['ok']), json_encode($salidas));
        $perdedor = array_values(array_filter($salidas, fn ($s) => ! $s['ok']))[0];
        $this->assertContains($perdedor['codigo'], ['OPERACION_EN_CURSO', 'PLAN_DESACTUALIZADO'], json_encode($salidas));
        $this->assertSame(11, Emision::where('lote_id', $lote->id)->where('version', 2)->count());
        $this->assertCoherente($lote, 11);
    }

    public function test_reemision_masiva_y_reemision_individual_a_la_vez_nunca_dejan_dos_vigentes(): void
    {
        foreach ([0, 300, 900] as $ronda => $retraso) {
            $lote = $this->baseParaReemitir(4);
            $huella = app(ReemisorBase::class)->planear($lote, ReemisorBase::OFICIALES)['huella'];
            $una = Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)->orderBy('id')->firstOrFail();

            [$masiva, $individual] = $this->enParalelo([['reemitir', (string) $lote->id, ReemisorBase::OFICIALES, $huella, '0'], ['individual', (string) $lote->id, (string) $una->id, '', (string) $retraso]]);

            if ($masiva['ok']) {
                $this->assertFalse($individual['ok'], "ronda {$ronda}: ".json_encode([$masiva, $individual]));
                $this->assertSame('EMISION_YA_REVOCADA', $individual['codigo']);
            } else {
                $this->assertTrue($individual['ok'], "ronda {$ronda}: ".json_encode([$masiva, $individual]));
                $this->assertContains($masiva['codigo'], ['DATOS_CAMBIARON_DURANTE_EMISION', 'PLAN_DESACTUALIZADO']);
                $this->assertSame(1, Emision::where('lote_id', $lote->id)->where('version', 2)->count(), 'solo la individual');
            }
            $this->assertCoherente($lote, 12);
        }
    }

    public function test_dos_cambios_de_plantilla_a_la_vez_solo_uno_gana(): void
    {
        $lote = $this->baseParaReemitir(2);
        $a = $this->plantilla('por su participación en la');
        $b = $this->plantilla('por haber asistido a la');
        $ha = app(CambioPlantillaEmitida::class)->planear($lote, $a)['huella'];
        $hb = app(CambioPlantillaEmitida::class)->planear($lote, $b)['huella'];
        $emisiones = DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all();

        $salidas = $this->enParalelo([['cambiar', (string) $lote->id, (string) $a->id, $ha, '0'], ['cambiar', (string) $lote->id, (string) $b->id, $hb, '0']]);

        $this->assertCount(1, array_filter($salidas, fn ($s) => $s['ok']), json_encode($salidas));
        $this->assertSame('PLAN_DESACTUALIZADO', array_values(array_filter($salidas, fn ($s) => ! $s['ok']))[0]['codigo']);
        $this->assertContains($lote->fresh()->plantilla_id, [$a->id, $b->id]);
        $this->assertSame($emisiones, DB::table('cf_emisiones')->orderBy('id')->get()->map(fn ($e) => (array) $e)->all(), 'cambiar la plantilla no toca certificados');
        $this->assertSame(2, DB::table('movimientos')->where('descripcion', 'like', 'Se cambió la plantilla de una base con certificados emitidos%')->count(), 'el de la preparación + el del ganador');
    }
}
