<?php

namespace Tests\Integracion;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Importacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Models\Usuario;
use App\Support\CredentialFlow\Eventos\AutorizacionesDobleModalidad;
use App\Support\CredentialFlow\Importacion\ServicioImportaciones;
use App\Support\CredentialFlow\Participantes\DobleModalidad;
use App\Support\CredentialFlow\Participantes\Texto;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Database\Seeders\AreaFormacionSeeder;
use Database\Seeders\EquipoSeeder;
use Database\Seeders\EstadosSeeder;
use Database\Seeders\RolSeeder;
use Database\Seeders\TipoDocumentosSeeder;
use Database\Seeders\UsuarioSeeder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\Feature\CredentialFlow\Support\PdfBase;
use Tests\Feature\CredentialFlow\Support\XlsxSintetico;
use Tests\TestCase;

/**
 * Concurrencia REAL (MySQL/MariaDB, procesos PHP en paralelo) de la autorización de doble modalidad por evento. Requiere TEST_MYSQL_DATABASE con una base local
 * *_test (se BORRA y se recrea); sin ella, se omite. Datos 100 % sintéticos.
 *
 *  - Varios super-admin autorizando el mismo evento a la vez dejan UNA sola autorización vigente.
 *  - Revocar mientras se mueve una base con participaciones dobles, mientras se confirma una importación con una persona doble o mientras se guarda la
 *    segunda participación de una persona: en ningún orden queda una base con participaciones dobles en un evento sin autorización, ni datos a medias.
 */
class DobleModalidadConcurrenciaMysqlTest extends TestCase
{
    private const CONEXION = 'mysql_pruebas';

    private string $base = '';

    private string $dir = '';

    private ?Plantilla $plantilla = null;

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
        foreach ([EstadosSeeder::class, TipoDocumentosSeeder::class, AreaFormacionSeeder::class, EquipoSeeder::class, RolSeeder::class, UsuarioSeeder::class] as $seeder) {
            $this->assertSame(0, Artisan::call('db:seed', ['--class' => $seeder, '--force' => true]));
        }
        $this->dir = sys_get_temp_dir().DIRECTORY_SEPARATOR.'doble_modalidad_mysql_'.bin2hex(random_bytes(6));
        foreach (['storage/app/private', 'storage/framework/cache/data', 'storage/framework/views', 'storage/framework/sessions', 'storage/logs'] as $d) {
            mkdir($this->dir.DIRECTORY_SEPARATOR.$d, 0777, true);
        }
        // Storage propio (el de los trabajadores es el mismo): nunca el de desarrollo.
        config(['filesystems.disks.local.root' => $this->dir.'/storage/app/private', 'credential_flow.emision.exige_vista_previa' => false,
            'credential_flow.importacion_inteligente.activa' => true, 'credential_flow.qa.contactos_esperados' => 8]);
        Storage::forgetDisk('local');
        for ($i = 1; $i <= 8; $i++) {
            $doc = 'C.C. 8.000.00'.$i;
            DB::table('cf_contactos_qa')->insert(['nombre_completo' => "QA {$i} PRUEBA", 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc),
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

    // ── Utilidades ────────────────────────────────────────────────────────────

    private function super(): Usuario
    {
        return Usuario::with('perfilOrganizador.rol')->where('correo_principal', 'jhoann@fycconsultores.com')->firstOrFail();
    }

    private function autorizaciones(): AutorizacionesDobleModalidad
    {
        return app(AutorizacionesDobleModalidad::class);
    }

    private function evento(string $nombre): EventoCertificacion
    {
        return EventoCertificacion::create(['nombre' => $nombre, 'nombre_normalizado' => NombreConservador::normalizar($nombre), 'anio' => 2026,
            'fecha_texto' => '10 DE NOVIEMBRE DE 2026', 'estado' => EventoCertificacion::ESTADO_ACTIVO, 'origen' => EventoCertificacion::ORIGEN_CREDENTIAL_FLOW]);
    }

    /** Plantilla real (PDF base en el storage de la prueba, diseño con modalidad y variante QA), creada una vez. */
    private function plantilla(): Plantilla
    {
        if ($this->plantilla !== null) {
            return $this->plantilla;
        }
        $bytes = PdfBase::crear();
        $p = Plantilla::create(['nombre' => 'Plantilla concurrencia '.uniqid(), 'descripcion' => 'Prueba', 'archivo_pdf' => 'pendiente', 'nombre_archivo_original' => 'base.pdf', 'hash_sha256' => hash('sha256', $bytes)]);
        Storage::disk('local')->put($p->rutaPdfEsperada(), $bytes);
        $elementos = [];
        foreach (['nombre_completo', 'documento', 'evento', 'fecha', 'modalidad', 'intensidad_horaria'] as $i => $campo) {
            $elementos[] = ['id' => sprintf('00000000-0000-4000-8000-%012d', $i + 1), 'type' => 'text', 'field' => $campo, 'text' => '', 'x' => 96, 'y' => 40 + $i * 60,
                'width' => 600, 'height' => 40, 'fontFamily' => 'outfit', 'fontSize' => 20, 'fontWeight' => 700, 'color' => '#000000', 'align' => 'center'];
        }
        $p->update(['archivo_pdf' => $p->rutaPdfEsperada(), 'diseno' => ['page' => ['width' => 792, 'height' => 612], 'elements' => $elementos], 'schema_version' => 1]);
        $this->assertSame(0, Artisan::call('credential-flow:plantilla-variante-qa', ['origen' => $p->id, '--ejecutar' => true]), Artisan::output());

        return $this->plantilla = $p->fresh();
    }

    /** Base del evento con participantes [nombre, documento, modalidad] creados directamente. */
    private function base(EventoCertificacion $evento, array $personas): Lote
    {
        $lote = Lote::create(['plantilla_id' => $this->plantilla()->id, 'nombre' => 'Base concurrencia '.uniqid(), 'evento_id' => $evento->id,
            'datos_comunes' => ['evento' => 'TALLER DE CONCURRENCIA', 'fecha' => '10 de noviembre de 2026', 'intensidad_horaria' => '8 horas']]);
        foreach ($personas as [$nombre, $doc, $modalidad]) {
            $lote->participantes()->create(['nombre_completo' => $nombre, 'documento' => $doc, 'documento_clave' => Texto::claveDocumento($doc), 'modalidad' => $modalidad]);
        }

        return $lote;
    }

    /** Borrador del asistente LISTO para confirmar: una persona presencial y virtual y otra persona, asociado al evento (autorizado). */
    private function borrador(EventoCertificacion $evento, int $ronda): Importacion
    {
        $c = ['B2' => 'Nombre del Evento', 'D2' => "Seminario sintético de concurrencia {$ronda}", 'B3' => 'Fecha del Evento', 'D3' => '10 de noviembre de 2026',
            'B4' => 'Intensidad de horas', 'D4' => '8 Horas'];
        foreach (['B' => 'Item', 'C' => 'Tipo de documento', 'D' => 'Número de identificación', 'E' => 'Nombres y Apellidos', 'F' => 'Correo Electrónico', 'G' => 'Modalidad'] as $col => $t) {
            $c[$col.'6'] = $t;
        }
        foreach ([['ANA FICTICIA DOBLE', '5100001', 'Presencial'], ['ANA FICTICIA DOBLE', '5100001', 'Virtual'], ['BETO FICTICIO UNO', '5100002', 'Presencial']] as $i => [$n, $d, $m]) {
            $f = 7 + $i;
            [$c['B'.$f], $c['C'.$f], $c['D'.$f], $c['E'.$f], $c['F'.$f], $c['G'.$f]] = [$i + 1, 'C.C.', $d.$ronda, $n, "p{$d}{$ronda}@example.test", $m];
        }
        $ruta = $this->dir.DIRECTORY_SEPARATOR."doble_{$ronda}.xlsx";
        file_put_contents($ruta, XlsxSintetico::libro([['nombre' => 'Solicitud', 'celdas' => $c]]));
        $servicio = app(ServicioImportaciones::class);
        [$imp] = $servicio->analizar(new UploadedFile($ruta, "doble_{$ronda}.xlsx", null, null, true), $this->super()->id);
        $imp = $servicio->corregir($imp, $imp->version, [
            'plantilla_id' => $this->plantilla()->id, 'evento' => ['decision' => 'asociar', 'evento_id' => $evento->id],
            'confirmados' => ['datos_comunes' => ['fecha', 'intensidad_horaria'], 'valores_desactualizados' => true, 'valores_ambiguos' => true],
            'participantes' => ['7' => ['elegibilidad' => 'elegible'], '8' => ['elegibilidad' => 'elegible'], '9' => ['elegibilidad' => 'elegible']],
        ]);
        $e = $servicio->evaluar($imp);
        $this->assertTrue($e['listo'], json_encode($e['bloqueos'], JSON_UNESCAPED_UNICODE));

        return $imp->fresh();
    }

    /** Huella de lo que una confirmación fallida NO debe dejar: bases, participantes, correos y el borrador. */
    private function foto(Importacion $imp): string
    {
        return hash('sha256', json_encode([Lote::count(), DB::table('cf_participantes')->count(), DB::table('cf_correos')->count(),
            DB::table('cf_importaciones')->where('id', $imp->id)->get(['estado', 'version', 'lote_id'])->toArray()]));
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
        [, \$modo, \$a, \$b, \$retraso] = \$argv + [null, null, null, null, '0'];
        \$super = App\\Models\\Usuario::with('perfilOrganizador.rol')->where('correo_principal', 'jhoann@fycconsultores.com')->firstOrFail();
        \$admin = App\\Models\\Usuario::with('perfilOrganizador.rol')->where('correo_principal', 'erik@fycconsultores.com')->firstOrFail();
        while (! file_exists({$go})) { usleep(2000); }
        usleep(1000 * (int) \$retraso);
        \$t0 = microtime(true);
        try {
            \$aut = app(App\\Support\\CredentialFlow\\Eventos\\AutorizacionesDobleModalidad::class);
            if (\$modo === 'autorizar') {
                \$r = \$aut->autorizar(App\\Models\\CredentialFlow\\EventoCertificacion::findOrFail((int) \$a), \$super, 'Autorización concurrente sintética');
                echo json_encode(['ok' => true, 'id' => \$r['autorizacion_id']]);
            } elseif (\$modo === 'revocar') {
                \$aut->revocar(App\\Models\\CredentialFlow\\EventoCertificacion::findOrFail((int) \$a), \$super, 'Revocación concurrente sintética');
                echo json_encode(['ok' => true, 't_fin' => microtime(true)]);
            } elseif (\$modo === 'revocar_directo') {
                // Revocación que NO pasa por las comprobaciones del servicio (por ejemplo, una corrección manual): bloquea el evento como el servicio,
                // marca la autorización revocada y mantiene el bloqueo \$b ms antes de confirmar. Sirve para comprobar que los demás esperan y releen.
                \$t = Illuminate\\Support\\Facades\\DB::transaction(function () use (\$a, \$b) {
                    App\\Models\\CredentialFlow\\EventoCertificacion::query()->whereKey((int) \$a)->lockForUpdate()->first();
                    \$tLock = microtime(true);
                    App\\Models\\CredentialFlow\\AutorizacionDobleModalidad::query()->where('vigente_evento_id', (int) \$a)->update(['estado' => 'revocada', 'vigente_evento_id' => null,
                        'revocada_por' => 1, 'revocada_at' => now(), 'motivo_revocacion' => 'Revocación directa sintética']);
                    usleep(1000 * (int) \$b);

                    return \$tLock;
                });
                echo json_encode(['ok' => true, 't_lock' => \$t, 't_fin' => microtime(true)]);
            } elseif (\$modo === 'confirmar') {
                \$imp = App\\Models\\CredentialFlow\\Importacion::findOrFail((int) \$a);
                \$lote = app(App\\Support\\CredentialFlow\\Importacion\\ServicioImportaciones::class)->confirmar(\$imp, \$imp->version, \$admin->id);
                echo json_encode(['ok' => true, 'lote' => \$lote->id, 't_fin' => microtime(true)]);
            } elseif (\$modo === 'alta') {
                // El alta REAL por HTTP (ParticipanteRequest + ParticipanteController), como la hace el formulario de la base.
                \$app['auth']->guard('web')->setUser(\$admin);
                \$req = Illuminate\\Http\\Request::create('/admin/credential-flow/lotes/'.(int) \$a.'/participantes', 'POST',
                    ['nombre_completo' => 'ANA FICTICIA DOBLE', 'documento' => \$b, 'modalidad' => 'Virtual']);
                \$req->headers->set('Accept', 'text/html');
                \$res = \$app->make(Illuminate\\Contracts\\Http\\Kernel::class)->handle(\$req);
                \$errores = \$req->hasSession() ? (\$req->session()->get('errors')?->keys() ?? []) : [];
                \$creado = App\\Models\\CredentialFlow\\Participante::query()->where('lote_id', (int) \$a)->count() === 2;
                echo json_encode(['ok' => \$creado, 'status' => \$res->getStatusCode(), 'errores' => \$errores, 't_fin' => microtime(true)]);
            } else {
                \$app['auth']->guard('web')->setUser(\$super);
                app(App\\Support\\CredentialFlow\\Eventos\\ServicioEventos::class)->asociar(App\\Models\\CredentialFlow\\Lote::findOrFail((int) \$a), App\\Models\\CredentialFlow\\EventoCertificacion::findOrFail((int) \$b));
                echo json_encode(['ok' => true]);
            }
        } catch (App\\Support\\CredentialFlow\\Eventos\\AutorizacionDobleModalidadException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e->codigo, 'bloqueos' => array_column(\$e->bloqueos, 'code')]);
        } catch (App\\Support\\CredentialFlow\\Importacion\\ImportacionException \$e) {
            echo json_encode(['ok' => false, 'codigo' => \$e->codigo, 't_fin' => microtime(true)]);
        } catch (InvalidArgumentException \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'RECHAZADO']);
        } catch (Throwable \$e) {
            echo json_encode(['ok' => false, 'codigo' => 'EXCEPCION', 'clase' => get_class(\$e), 'mensaje' => substr(\$e->getMessage(), 0, 400)], JSON_INVALID_UTF8_SUBSTITUTE);
        }
        PHP;
        $ruta = $this->dir.DIRECTORY_SEPARATOR.'trabajador.php';
        file_put_contents($ruta, $codigo);

        return $ruta;
    }

    /**
     * Arranca los procesos, espera a que todos estén listos, los suelta a la vez y devuelve lo que respondió cada uno.
     *
     * @param  list<list<string>>  $argumentos
     * @return list<array<string,mixed>>
     */
    private function enParalelo(array $argumentos): array
    {
        @unlink($this->dir.DIRECTORY_SEPARATOR.'go');
        $script = $this->escribirTrabajador();
        $entorno = array_merge(getenv(), [
            'DB_CONNECTION' => 'mysql', 'DB_DATABASE' => $this->base, 'APP_ENV' => 'testing', 'MAIL_MAILER' => 'array', 'CACHE_STORE' => 'array', 'SESSION_DRIVER' => 'array',
            'LARAVEL_STORAGE_PATH' => $this->dir.DIRECTORY_SEPARATOR.'storage', 'CREDENTIAL_FLOW_EXIGE_VISTA_PREVIA' => 'false', 'CREDENTIAL_FLOW_IMPORTACION_INTELIGENTE' => 'true',
            'CREDENTIAL_FLOW_QA_CONTACTOS_ESPERADOS' => '8',
        ]);
        unset($entorno['CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS']);
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

    /** Invariante de todo el módulo: ninguna base con participaciones dobles reales queda en un evento SIN autorización. */
    private function sinDoblesHuerfanas(string $contexto): void
    {
        foreach (Lote::query()->whereNotNull('evento_id')->get() as $l) {
            if (AutorizacionesDobleModalidad::documentosDobles(null, $l->id) > 0) {
                $this->assertTrue(DobleModalidad::autorizado((int) $l->evento_id), "{$contexto}: la base #{$l->id} tiene participaciones dobles en un evento sin autorización");
            }
        }
    }

    // ── Pruebas ───────────────────────────────────────────────────────────────

    public function test_cinco_super_admin_autorizando_a_la_vez_dejan_una_sola_autorizacion_vigente(): void
    {
        $evento = $this->evento('Evento sintético de concurrencia');
        $salidas = $this->enParalelo(array_map(fn () => ['autorizar', (string) $evento->id, '', '0'], range(1, 5)));

        $this->assertCount(1, array_filter($salidas, fn ($s) => $s['ok']), json_encode($salidas));
        foreach (array_filter($salidas, fn ($s) => ! $s['ok']) as $s) {
            $this->assertSame('YA_AUTORIZADO', $s['codigo'], json_encode($s));
        }
        $this->assertSame(1, AutorizacionDobleModalidad::where('evento_id', $evento->id)->count());
        $this->assertTrue(DobleModalidad::autorizado($evento->id));
    }

    public function test_revocar_y_mover_una_base_doble_a_la_vez_nunca_la_deja_en_un_evento_sin_autorizacion(): void
    {
        $resultados = [];
        // [retraso del traslado, retraso de la revocación] en ms: los dos órdenes y el arranque simultáneo.
        foreach ([[0, 0], [0, 30], [0, 0], [120, 0], [300, 0], [0, 0]] as $ronda => [$retrasoMover, $retrasoRevocar]) {
            $origen = $this->evento("Evento origen {$ronda}");
            $destino = $this->evento("Evento destino {$ronda}");
            $this->autorizaciones()->autorizar($origen, $this->super(), 'Autorización sintética del origen');
            $this->autorizaciones()->autorizar($destino, $this->super(), 'Autorización sintética del destino');
            $lote = $this->base($origen, [['PERSONA FICTICIA DOBLE', 'C.C. 52.000.001', 'Presencial'], ['PERSONA FICTICIA DOBLE', 'C.C. 52.000.001', 'Virtual']]);

            [$mover, $revocar] = $this->enParalelo([['asociar', (string) $lote->id, (string) $destino->id, (string) $retrasoMover], ['revocar', (string) $destino->id, '', (string) $retrasoRevocar]]);
            $lote->refresh();
            $destinoAutorizado = DobleModalidad::autorizado($destino->id);
            $resultados[] = [$mover['ok'], $revocar['ok']];

            $this->sinDoblesHuerfanas("ronda {$ronda}: ".json_encode([$mover, $revocar]));
            $this->assertNotSame($mover['ok'], $revocar['ok'], "ronda {$ronda}: ".json_encode([$mover, $revocar]));
            if ($mover['ok']) {
                $this->assertSame($destino->id, (int) $lote->evento_id);
                $this->assertTrue($destinoAutorizado);
                $this->assertContains('PARTICIPACIONES_DOBLES', $revocar['bloqueos'] ?? [], json_encode($revocar));
            } else {
                $this->assertSame('RECHAZADO', $mover['codigo'], json_encode($mover));
                $this->assertSame($origen->id, (int) $lote->evento_id);
                $this->assertFalse($destinoAutorizado);
            }
        }
        fwrite(STDERR, PHP_EOL.'traslado vs revocación [traslado, revocación]: '.json_encode($resultados).PHP_EOL);
        $this->assertContains([true, false], $resultados, 'alguna ronda la gana el traslado');
        $this->assertContains([false, true], $resultados, 'alguna ronda la gana la revocación');
    }

    /**
     * Confirmar un borrador con una persona doble mientras un super-admin revoca por el panel: el propio borrador en revisión bloquea la revocación y, si
     * la confirmación ya terminó, la bloquean las participaciones dobles. En ningún orden se revoca dejando la base doble, ni la base queda a medias.
     */
    public function test_confirmar_una_importacion_y_revocar_por_el_panel_a_la_vez_en_ambos_ordenes(): void
    {
        $resultados = [];
        foreach ([[0, 0], [0, 400], [400, 0], [0, 1500]] as $ronda => [$retrasoConfirmar, $retrasoRevocar]) {
            $evento = $this->evento("Evento importación {$ronda}");
            $this->autorizaciones()->autorizar($evento, $this->super(), 'Autorización sintética para importar');
            $imp = $this->borrador($evento, $ronda);

            [$confirmar, $revocar] = $this->enParalelo([['confirmar', (string) $imp->id, '', (string) $retrasoConfirmar], ['revocar', (string) $evento->id, '', (string) $retrasoRevocar]]);
            $resultados[] = [$confirmar['ok'], $revocar['bloqueos'] ?? $revocar['codigo'] ?? 'ok'];

            $this->assertTrue($confirmar['ok'], "ronda {$ronda}: ".json_encode([$confirmar, $revocar]));
            $this->assertFalse($revocar['ok'], "ronda {$ronda}: la revocación nunca pasa: ".json_encode($revocar));
            $this->assertNotEmpty(array_intersect(['BORRADOR_EN_REVISION', 'PARTICIPACIONES_DOBLES'], $revocar['bloqueos'] ?? []), json_encode($revocar));
            $lote = Lote::findOrFail($confirmar['lote']);
            $this->assertSame(1, AutorizacionesDobleModalidad::documentosDobles(null, $lote->id));
            $this->assertSame(3, $lote->participantes()->where('es_control_qa', false)->count(), 'base completa: 3 participaciones reales');
            $this->assertTrue(DobleModalidad::autorizado($evento->id));
            $this->sinDoblesHuerfanas("ronda {$ronda}");
        }
        fwrite(STDERR, PHP_EOL.'confirmar vs revocar por el panel [confirmó, motivo del rechazo]: '.json_encode($resultados).PHP_EOL);
        $this->assertContains(['BORRADOR_EN_REVISION'], array_column($resultados, 1), 'en alguna ronda la revocación llega antes que la confirmación');
        $this->assertContains(['PARTICIPACIONES_DOBLES'], array_column($resultados, 1), 'en alguna ronda llega después');
    }

    /**
     * Confirmar mientras otra transacción revoca SIN pasar por las comprobaciones del servicio (la peor carrera posible). La confirmación relee la
     * autorización con bloqueo: si la revocación confirmó antes, la importación se rechaza entera (nada a medias); si la confirmación terminó antes, la
     * revocación tuvo que esperarla (serialización por el bloqueo del evento).
     */
    public function test_confirmar_una_importacion_contra_una_revocacion_directa_se_serializa_y_no_deja_nada_a_medias(): void
    {
        $resultados = [];
        foreach ([[150, 0, 900], [0, 0, 300], [0, 60, 300], [0, 200, 300], [400, 0, 1200]] as $ronda => [$retrasoConfirmar, $retrasoRevocar, $mantener]) {
            $evento = $this->evento("Evento revocación directa {$ronda}");
            $this->autorizaciones()->autorizar($evento, $this->super(), 'Autorización sintética para importar');
            $imp = $this->borrador($evento, $ronda);
            $antes = $this->foto($imp);

            [$confirmar, $revocar] = $this->enParalelo([['confirmar', (string) $imp->id, '', (string) $retrasoConfirmar], ['revocar_directo', (string) $evento->id, (string) $mantener, (string) $retrasoRevocar]]);
            $this->assertTrue($revocar['ok'], json_encode($revocar));
            $resultados[] = $confirmar['ok'] ? 'confirmó antes' : 'revocación antes';

            if ($confirmar['ok']) {
                $this->assertGreaterThanOrEqual($confirmar['t_fin'] - 0.05, $revocar['t_lock'], "ronda {$ronda}: la revocación tomó el evento antes de que terminara la confirmación");
                $this->assertSame(1, AutorizacionesDobleModalidad::documentosDobles(null, $confirmar['lote']));
            } else {
                $this->assertSame('IMPORTACION_INVALIDA', $confirmar['codigo'], json_encode($confirmar));
                $this->assertGreaterThanOrEqual($revocar['t_lock'], $confirmar['t_fin']);
                $this->assertSame($antes, $this->foto($imp), "ronda {$ronda}: una confirmación rechazada no deja nada (ni base, ni participantes, ni correos, ni cambios del borrador)");
                $this->assertSame(Importacion::EN_REVISION, $imp->fresh()->estado);
                // (No se usa sinDoblesHuerfanas: la revocación DIRECTA de rondas anteriores se salta a propósito el bloqueo por participaciones dobles.)
                $this->assertSame(0, Lote::query()->where('evento_id', $evento->id)->count(), "ronda {$ronda}: ninguna base en el evento revocado");
            }
        }
        fwrite(STDERR, PHP_EOL.'confirmar vs revocación directa: '.json_encode($resultados).PHP_EOL);
        $this->assertContains('confirmó antes', $resultados);
        $this->assertContains('revocación antes', $resultados);
    }

    /**
     * Guardar la segunda participación (presencial + virtual) por el formulario real mientras un super-admin revoca por el panel: o gana el alta (y la
     * revocación se bloquea por la participación doble) o gana la revocación (y el alta se rechaza sin guardar nada). Nunca las dos.
     */
    public function test_guardar_un_participante_y_revocar_a_la_vez_en_ambos_ordenes(): void
    {
        $resultados = [];
        foreach ([[0, 900], [0, 0], [500, 0], [0, 1500], [0, 0], [900, 0]] as $ronda => [$retrasoAlta, $retrasoRevocar]) {
            $evento = $this->evento("Evento alta {$ronda}");
            $this->autorizaciones()->autorizar($evento, $this->super(), 'Autorización sintética para el alta');
            $doc = 'C.C. 53.000.0'.str_pad((string) $ronda, 2, '0', STR_PAD_LEFT);
            $lote = $this->base($evento, [['ANA FICTICIA DOBLE', $doc, 'Presencial']]);

            [$alta, $revocar] = $this->enParalelo([['alta', (string) $lote->id, $doc, (string) $retrasoAlta], ['revocar', (string) $evento->id, '', (string) $retrasoRevocar]]);
            $resultados[] = [$alta['ok'], $revocar['ok']];
            $n = $lote->participantes()->count();

            $this->assertNotSame($alta['ok'], $revocar['ok'], "ronda {$ronda}: ".json_encode([$alta, $revocar]));
            if ($alta['ok']) {
                $this->assertSame(2, $n);
                $this->assertTrue(DobleModalidad::autorizado($evento->id));
                $this->assertContains('PARTICIPACIONES_DOBLES', $revocar['bloqueos'] ?? [], json_encode($revocar));
            } else {
                $this->assertSame(1, $n, "ronda {$ronda}: el alta rechazada no deja nada");
                $this->assertContains('documento', $alta['errores'], json_encode($alta));
                $this->assertFalse(DobleModalidad::autorizado($evento->id));
            }
            $this->sinDoblesHuerfanas("ronda {$ronda}: ".json_encode([$alta, $revocar]));
        }
        fwrite(STDERR, PHP_EOL.'alta vs revocación [alta, revocación]: '.json_encode($resultados).PHP_EOL);
        $this->assertContains([true, false], $resultados, 'alguna ronda la gana el alta');
        $this->assertContains([false, true], $resultados, 'alguna ronda la gana la revocación');
    }
}
