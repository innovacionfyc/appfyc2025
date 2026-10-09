<?php

namespace App\Support\CredentialFlow\Rehearsal;

use Illuminate\Support\Facades\DB;
use RuntimeException;
use Symfony\Component\Process\Process;

/**
 * ORQUESTADOR local de la reconstrucción del rehearsal (Fase 11A). Ejecuta la secuencia oficial en SUBPROCESOS (cada paso con su propio `memory_limit`, `APP_KEY` de rehearsal,
 * BD destino y storage dedicados) y termina con `rehearsal:verificar`. Es deliberadamente restrictivo:
 *  - se niega en producción;
 *  - exige una BD destino EXPLÍCITA cuyo nombre contenga «rehearsal» o termine en «_test», distinta de la BD de la aplicación y de la de staging;
 *  - jamás borra una BD que no se llame «…rehearsal…» y solo con `--recrear` + `--confirmo-borrar=<nombre exacto>`; una BD con tablas sin `--recrear` se rechaza;
 *  - exige una `APP_KEY` de rehearsal explícita (variable de entorno indicada), nunca la de producción;
 *  - usa un storage DEDICADO (carpeta con «rehearsal» en el nombre y marcador `.rehearsal-storage`) y solo vacía carpetas con ese marcador;
 *  - deshabilita el envío real de correo y los tres flags de identidad en los subprocesos.
 */
final class ReconstructorRehearsal
{
    public const MARCADOR = '.rehearsal-storage';

    /** @param array<string,mixed> $o */
    public function __construct(private readonly array $o) {}

    /** @throws RuntimeException con un código estable */
    public function validar(): void
    {
        $bd = (string) ($this->o['bd'] ?? '');
        $stg = (string) ($this->o['staging_bd'] ?? '');
        if (app()->environment('production')) {
            throw new RuntimeException('ENTORNO_PRODUCCION_NO_PERMITIDO');
        }
        if (preg_match('/^[A-Za-z0-9_]{3,64}$/', $bd) !== 1 || (preg_match('/rehearsal/i', $bd) !== 1 && preg_match('/_test$/i', $bd) !== 1)) {
            throw new RuntimeException('BD_DESTINO_NO_RECONOCIDA');
        }
        if ($bd === (string) config('database.connections.'.config('database.default').'.database') && ! ($this->o['permitir_bd_actual'] ?? false)) {
            // La BD de la conexión actual solo sirve si el proceso ya se lanzó apuntando a ella (pruebas); el comando exige que sea otra.
            throw new RuntimeException('BD_DESTINO_ES_LA_ACTUAL');
        }
        if ($bd === $stg || preg_match('/^[A-Za-z0-9_]{3,64}$/', $stg) !== 1 || preg_match('/staging/i', $stg) !== 1) {
            throw new RuntimeException('BD_STAGING_INVALIDA');
        }
        $clave = (string) (getenv((string) ($this->o['app_key_env'] ?? 'REHEARSAL_APP_KEY')) ?: '');
        if (! str_starts_with($clave, 'base64:') || strlen($clave) < 20) {
            throw new RuntimeException('APP_KEY_DE_REHEARSAL_REQUERIDA');
        }
        $raiz = (string) ($this->o['raiz_storage'] ?? '');
        if ($raiz === '' || preg_match('/rehearsal/i', basename($raiz)) !== 1 || realpath($raiz) === realpath(storage_path('app/private'))) {
            throw new RuntimeException('STORAGE_DEDICADO_REQUERIDO');
        }
        if (is_dir($raiz) && count(scandir($raiz)) > 2 && ! is_file($raiz.DIRECTORY_SEPARATOR.self::MARCADOR)) {
            throw new RuntimeException('STORAGE_NO_ES_DE_REHEARSAL');
        }
        $tablas = $this->tablasDe($bd);
        if ($tablas > 0 && ! ($this->o['recrear'] ?? false)) {
            throw new RuntimeException('BD_DESTINO_NO_VACIA');
        }
        if (($this->o['recrear'] ?? false) && ($tablas > 0 || $this->existe($bd))) {
            if (preg_match('/rehearsal/i', $bd) !== 1 || ($this->o['confirmo_borrar'] ?? null) !== $bd) {
                throw new RuntimeException('CONFIRMACION_DE_BORRADO_REQUERIDA');
            }
        }
        if ($this->tablasDe($stg) === 0) {
            throw new RuntimeException('BD_STAGING_VACIA');
        }
    }

    /**
     * @param  callable(string,array<string,mixed>):void|null  $progreso
     * @return array{ok:bool,pasos:list<array<string,mixed>>,verificacion:?array<string,mixed>}
     */
    public function ejecutar(?callable $progreso = null): array
    {
        $this->validar();
        $bd = (string) $this->o['bd'];
        if ($this->existe($bd) && ($this->o['recrear'] ?? false)) {
            DB::statement("DROP DATABASE `{$bd}`");
        }
        if (! $this->existe($bd)) {
            DB::statement("CREATE DATABASE `{$bd}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        }
        $this->prepararStorage();

        $stg = (string) $this->o['staging_bd'];
        $esperadoDif = (int) (json_decode((string) file_get_contents(base_path(VerificadorRehearsal::BASELINE)), true)['dif_verif']['casos'] ?? 0);
        $pasos = [
            ['migrate', ['migrate', '--force']],
            ['legado:migrar', ['credential-flow:legado:migrar', '--staging-bd='.$stg, '--json']],
            ['encuestas:migrar', ['credential-flow:encuestas:migrar', '--staging-bd='.$stg, '--json']],
            ['backfill-dif-verif', ['credential-flow:conciliaciones:backfill-dif-verif', '--esperado='.$esperadoDif]],
            ['conciliaciones:detectar', ['credential-flow:conciliaciones:detectar']],
            ['grupos-sin-via', ['credential-flow:conciliaciones:grupos-sin-via']],
            ['preflight-codigos', ['credential-flow:codigos-historicos:preflight']],
            ['preflight-clave', ['credential-flow:clave:verificar', '--estricto']],
        ];
        if (! empty($this->o['imagenes'])) {
            $args = ['credential-flow:legado:importar-imagenes', '--origen='.$this->o['imagenes']];
            if (! empty($this->o['manifest'])) {
                $args[] = '--manifest='.$this->o['manifest'];
            }
            $pasos[] = ['importar-imagenes', $args];
            $pasos[] = ['verificar-imagenes', ['credential-flow:legado:importar-imagenes', '--verificar']];
        }
        $pasos[] = ['rehearsal:verificar', ['credential-flow:rehearsal:verificar', '--huella', '--json']];

        $resultado = ['ok' => true, 'pasos' => [], 'verificacion' => null];
        foreach ($pasos as [$nombre, $args]) {
            $r = $this->correr($args);
            $resultado['pasos'][] = ['paso' => $nombre] + $r;
            if ($progreso !== null) {
                $progreso($nombre, $r);
            }
            if ($nombre === 'rehearsal:verificar') {
                $resultado['verificacion'] = json_decode($r['salida'], true) ?: null;
            }
            if ($r['codigo'] !== 0) {
                $resultado['ok'] = false;
                break;
            }
        }

        return $resultado;
    }

    /** @param list<string> $args @return array{codigo:int,segundos:float,pico_mb:?float,salida:string} */
    private function correr(array $args): array
    {
        $pico = tempnam(sys_get_temp_dir(), 'pico').'.php';
        file_put_contents($pico, '<?php register_shutdown_function(function () { fwrite(STDERR, "\n[PICO_MB:".round(memory_get_peak_usage(true) / 1048576, 1)."]\n"); });');
        $clave = (string) getenv((string) ($this->o['app_key_env'] ?? 'REHEARSAL_APP_KEY'));
        $env = [
            'DB_DATABASE' => (string) $this->o['bd'], 'APP_KEY' => $clave, 'APP_ENV' => 'local', 'FILESYSTEM_LOCAL_ROOT' => (string) $this->o['raiz_storage'],
            'CREDENTIAL_FLOW_CORREO_HABILITADO' => 'false', 'CREDENTIAL_FLOW_CORREO_PERMITIR_LOG' => 'false', 'MAIL_MAILER' => 'array',
            'CREDENTIAL_FLOW_IDENTIDAD_DECISIONES' => 'false', 'CREDENTIAL_FLOW_IDENTIDAD_MULTI_SCOPE' => 'false', 'CREDENTIAL_FLOW_IDENTIDAD_MASS_SCOPE' => 'false',
        ];
        $p = new Process([PHP_BINARY, '-d', 'memory_limit='.($this->o['memoria'] ?? '128M'), '-d', 'auto_prepend_file='.$pico, base_path('artisan'), ...$args], base_path(), $env, null, 3600);
        $t = microtime(true);
        $p->run();
        @unlink($pico);
        preg_match('/\[PICO_MB:([0-9.]+)\]/', $p->getErrorOutput(), $m);
        $salida = trim($p->getOutput());

        return ['codigo' => (int) $p->getExitCode(), 'segundos' => round(microtime(true) - $t, 1), 'pico_mb' => isset($m[1]) ? (float) $m[1] : null, 'salida' => $salida];
    }

    private function prepararStorage(): void
    {
        $raiz = (string) $this->o['raiz_storage'];
        if (is_dir($raiz) && is_file($raiz.DIRECTORY_SEPARATOR.self::MARCADOR) && ($this->o['recrear'] ?? false)) {
            $this->borrarArbol($raiz);
        }
        if (! is_dir($raiz)) {
            mkdir($raiz, 0775, true);
        }
        file_put_contents($raiz.DIRECTORY_SEPARATOR.self::MARCADOR, "Storage dedicado de un rehearsal de Credential Flow. Se puede borrar completo.\n");
    }

    private function borrarArbol(string $dir): void
    {
        foreach (new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($dir, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST) as $f) {
            $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
        }
    }

    private function existe(string $bd): bool
    {
        return DB::selectOne('select count(*) c from information_schema.schemata where schema_name = ?', [$bd])->c > 0;
    }

    private function tablasDe(string $bd): int
    {
        return (int) DB::selectOne('select count(*) c from information_schema.tables where table_schema = ?', [$bd])->c;
    }
}
