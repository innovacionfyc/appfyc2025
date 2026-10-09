<?php

namespace App\Support\CredentialFlow\Rehearsal;

use App\Support\CredentialFlow\Legado\ImportadorImagenesLegado;
use App\Support\CredentialFlow\Legado\RutasLegado;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Storage;

/**
 * VERIFICADOR del rehearsal (Fase 11A): compara una BD reconstruida con el BASELINE CERTIFICADO (`resources/rehearsal/baseline-snapshot-20261005.json`) y devuelve OK / NO-GO por
 * check, sin datos personales. Solo lectura. Además calcula huellas deterministas (por tabla, global y del storage) para comparar dos reconstrucciones.
 *
 * El baseline es del snapshot de DESARROLLO (2026-10-05): NO es la fuente final del cutover.
 */
final class VerificadorRehearsal
{
    public const BASELINE = 'resources/rehearsal/baseline-snapshot-20261005.json';

    /** @var list<array{id:string,descripcion:string,esperado:mixed,obtenido:mixed,ok:bool}> */
    private array $checks = [];

    /**
     * @param  array{imagenes?:bool,sin_via?:bool,huella?:bool,disco?:string,baseline?:?string}  $opciones
     * @return array{ok:bool,checks:list<array<string,mixed>>,huellas?:array<string,mixed>}
     */
    public function verificar(array $opciones = []): array
    {
        $this->checks = [];
        $base = json_decode((string) file_get_contents(base_path($opciones['baseline'] ?? self::BASELINE)), true);
        $disco = (string) ($opciones['disco'] ?? 'local');

        $this->entorno();
        $this->migraciones();
        $this->corrida($base);
        $this->conteos($base);
        $this->casos($base, $opciones['sin_via'] ?? true);
        if ($opciones['imagenes'] ?? true) {
            $this->imagenes($base, $disco);
        }
        $this->codigos();
        $this->flagsYCorreo();
        $this->clave();

        $r = ['ok' => ! in_array(false, array_column($this->checks, 'ok'), true), 'checks' => $this->checks];
        if ($opciones['huella'] ?? false) {
            $r['huellas'] = $this->huellas($disco, $opciones['imagenes'] ?? true);
        }

        return $r;
    }

    private function check(string $id, string $descripcion, mixed $esperado, mixed $obtenido, ?bool $ok = null): void
    {
        $this->checks[] = ['id' => $id, 'descripcion' => $descripcion, 'esperado' => $esperado, 'obtenido' => $obtenido, 'ok' => $ok ?? ($esperado === $obtenido)];
    }

    private function entorno(): void
    {
        $this->check('entorno_no_produccion', 'El entorno no es producción', false, app()->environment('production'));
        $this->check('bd_motor', 'La BD es MySQL/MariaDB', true, in_array(DB::connection()->getDriverName(), ['mysql', 'mariadb'], true));
    }

    private function migraciones(): void
    {
        $archivos = collect(glob(database_path('migrations/*.php')))->map(fn ($f) => basename($f, '.php'))->sort()->values()->all();
        $aplicadas = Schema::hasTable('migrations') ? DB::table('migrations')->pluck('migration')->all() : [];
        $this->check('migraciones_aplicadas', 'Todas las migraciones del repositorio están aplicadas', 0, count(array_diff($archivos, $aplicadas)));
        $this->check('tablas_cf', 'Tablas cf_* presentes', 30, count(array_filter(array_map(fn ($t) => array_values((array) $t)[0], DB::select('show tables')), fn ($t) => str_starts_with($t, 'cf_'))));
    }

    /** @param array<string,mixed> $base */
    private function corrida(array $base): void
    {
        $c = DB::table('cf_migraciones_corridas')->where('tipo', 'legado_evaluaciones')->where('estado', 'completada')->orderByDesc('id')->first();
        $this->check('corrida_completada', 'Existe una corrida de migración histórica completada', true, $c !== null);
        $this->check('corrida_snapshot', 'La corrida es del snapshot certificado (SHA-256 del dump)', $base['snapshot_sha256'], $c->snapshot_sha256 ?? null);
        $this->check('corrida_huella_global', 'Huella global del staging', $base['huella_global'], $c->huella_global ?? null);
        $this->check('corrida_huella_derivada', 'Huella derivada del staging', $base['huella_derivada'], $c->huella_derivada ?? null);
    }

    /** @param array<string,mixed> $base */
    private function conteos(array $base): void
    {
        foreach ($base['conteos'] as $tabla => $n) {
            $this->check('conteo_'.$tabla, "Filas de {$tabla}", $n, Schema::hasTable($tabla) ? DB::table($tabla)->count() : null);
        }
        $this->check('certificados_por_estado', 'Certificados por estado de conciliación', $this->ordenado($base['certificados_por_estado']), $this->ordenado(DB::table('cf_certificados_legado')->selectRaw('conciliacion_estado e, count(*) n')->groupBy('conciliacion_estado')->pluck('n', 'e')->map(fn ($x) => (int) $x)->all()));
        $this->check('plantillas_por_estado', 'Entradas de plantilla por estado', $this->ordenado($base['plantillas_por_estado']), $this->ordenado(DB::table('cf_plantillas_legado')->selectRaw('estado e, count(*) n')->groupBy('estado')->pluck('n', 'e')->map(fn ($x) => (int) $x)->all()));
    }

    /** @param array<string,mixed> $base */
    private function casos(array $base, bool $conSinVia): void
    {
        $porMotivo = DB::table('cf_conciliaciones')->where(fn ($q) => $q->whereNull('motivo_origen')->orWhere('motivo_origen', 'not like', 'GRUPO_SIN_VIA_%'))
            ->selectRaw("concat(tipo, '/', coalesce(motivo_origen, '')) k, count(*) n")->groupBy('tipo', 'motivo_origen')->pluck('n', 'k')->map(fn ($x) => (int) $x)->all();
        $this->check('casos_base', 'Casos de conciliación base (sin los de grupos sin vía)', $base['casos_base'], array_sum($porMotivo));
        $this->check('casos_por_tipo_motivo', 'Casos base por tipo y motivo', $this->ordenado($base['casos_por_tipo_motivo']), $this->ordenado($porMotivo));
        $dif = DB::table('cf_conciliaciones')->where('motivo_origen', 'DIF_VERIF')->count();
        $this->check('dif_verif_casos', 'Casos DIF_VERIF (backfill)', $base['dif_verif']['casos'], $dif);
        $this->check('dif_verif_certificados', 'Certificados afectados por DIF_VERIF', $base['dif_verif']['certificados'],
            (int) DB::table('cf_conciliaciones_certificados as p')->join('cf_conciliaciones as c', 'c.id', '=', 'p.conciliacion_id')->where('c.motivo_origen', 'DIF_VERIF')->count());
        $sv = DB::table('cf_conciliaciones')->where('motivo_origen', 'like', 'GRUPO_SIN_VIA_%')->selectRaw('motivo_origen m, count(*) n')->groupBy('motivo_origen')->pluck('n', 'm')->map(fn ($x) => (int) $x)->all();
        $this->check('casos_sin_via', 'Casos de grupos sin vía', $conSinVia ? $this->ordenado($base['sin_via']) : [], $this->ordenado($sv));
        $this->check('casos_duplicados', 'Sin claves de idempotencia duplicadas', 0, (int) DB::table('cf_conciliaciones')->selectRaw('count(*) - count(distinct clave_idempotencia) d')->value('d'));
    }

    /** @param array<string,mixed> $base */
    private function imagenes(array $base, string $disco): void
    {
        $imp = new ImportadorImagenesLegado($disco);
        $v = $imp->verificar();
        $this->check('imagenes_contenidos', 'Contenidos de plantilla', $base['imagenes']['contenidos'], $v['total']);
        $this->check('imagenes_ruta_no_nula', 'ruta_almacenada no nula', $base['imagenes']['ruta_no_nula'], (int) DB::table('cf_plantillas_legado_contenidos')->whereNotNull('ruta_almacenada')->count());
        $this->check('imagenes_verificadas', 'Imágenes con archivo, bytes, SHA-256 y MIME correctos', $base['imagenes']['contenidos'], $v['ok']);
        $s = $imp->huellaStorage();
        $this->check('storage_archivos', 'Archivos del storage de plantillas = contenidos', $base['imagenes']['contenidos'], $s['archivos']);
        $this->check('storage_sin_temporales', 'Sin temporales huérfanos en el storage', 0, $s['temporales']);
        $dir = Storage::disk($disco)->path(RutasLegado::RAIZ.'/plantillas');
        $this->check('storage_fuera_de_public', 'El storage de plantillas no está bajo public/', false, str_contains(str_replace('\\', '/', $dir), '/public/'));
    }

    private function codigos(): void
    {
        $this->check('codigos_sin_asignar', 'No hay códigos del módulo asignados todavía', 0, (int) DB::table('cf_codigos_historicos')->count());
        $this->check('codigos_preflight', 'Preflight de códigos históricos', 0, Artisan::call('credential-flow:codigos-historicos:preflight'));
    }

    private function flagsYCorreo(): void
    {
        foreach (['decisiones_enabled', 'multi_scope_enabled', 'mass_scope_enabled'] as $f) {
            $this->check('flag_'.$f, "Flag {$f} apagado", false, config('credential_flow.identidad.'.$f) === true);
        }
        $this->check('flag_portal_enabled', 'El portal público (CREDENTIAL_FLOW_PORTAL_ENABLED) está apagado', false, config('credential_flow.portal_enabled') === true);
        $mailer = (string) config('mail.default');
        $this->check('correo_real_deshabilitado', 'El envío real de correo está deshabilitado (mailer log/array o módulo apagado)', true,
            in_array($mailer, ['log', 'array'], true) || config('credential_flow.correo.habilitado') === false);
        $this->check('correo_sin_log_otp', 'El transporte log de OTP no está permitido', false, config('credential_flow.correo.permitir_transporte_log') === true);
    }

    private function clave(): void
    {
        $e = GuardiaClave::estado();
        $this->check('app_key_huella', 'La APP_KEY actual coincide con la de los datos derivados', 'coincide', $e['estado']);
    }

    /**
     * Huellas deterministas: por tabla (filas ordenadas por clave primaria, SIN columnas de fecha `*_at` ni tiempos de la corrida) y global; manifiesto y storage de imágenes.
     * Dos reconstrucciones con el mismo snapshot, la misma APP_KEY y las mismas imágenes deben producir exactamente las mismas.
     *
     * @return array<string,mixed>
     */
    public function huellas(string $disco = 'local', bool $imagenes = true): array
    {
        $tablas = [];
        foreach (DB::select('show tables') as $t) {
            $n = array_values((array) $t)[0];
            if (str_starts_with($n, 'cf_')) {
                $tablas[] = $n;
            }
        }
        sort($tablas);
        $por = [];
        foreach ($tablas as $t) {
            $cols = array_values(array_filter(Schema::getColumnListing($t), fn ($c) => ! str_ends_with($c, '_at') && ! ($t === 'cf_migraciones_corridas' && $c === 'totales')));
            $h = hash_init('sha256');
            $pk = Schema::hasColumn($t, 'id') ? 'id' : $cols[0];
            DB::table($t)->select($cols)->orderBy($pk)->chunkById(2000, function ($filas) use ($h, $cols) {
                foreach ($filas as $f) {
                    hash_update($h, json_encode(array_map(fn ($c) => self::canonico($f->$c), $cols), JSON_UNESCAPED_UNICODE)."\n");
                }
            }, $pk);
            $por[$t] = hash_final($h);
        }
        $r = ['bd_global' => hash('sha256', json_encode($por)), 'bd_por_tabla' => $por, 'huella_clave' => HuellaClave::actual()];
        if ($imagenes) {
            $imp = new ImportadorImagenesLegado($disco);
            $r['manifiesto_imagenes'] = $imp->manifiesto()['huella'];
            $r['storage_imagenes'] = $imp->huellaStorage()['huella'];
        }

        return $r;
    }

    /**
     * Valor neutro respecto del MOTOR: MySQL normaliza el texto de una columna JSON (orden de claves y espacios) y MariaDB lo conserva tal cual (LONGTEXT). Para que la huella
     * compare DATOS y no su serialización, un JSON válido se reescribe con las claves ordenadas y sin espacios. Cualquier otro valor se deja intacto.
     */
    public static function canonico(mixed $v): mixed
    {
        if (! is_string($v) || $v === '' || ($v[0] !== '{' && $v[0] !== '[')) {
            return $v;
        }
        $d = json_decode($v, true);
        if (! is_array($d)) {
            return $v;
        }
        $orden = function (mixed $x) use (&$orden): mixed {
            if (! is_array($x)) {
                return $x;
            }
            $x = array_map($orden, $x);
            if (! array_is_list($x)) {
                ksort($x, SORT_STRING);
            }

            return $x;
        };

        return json_encode($orden($d), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRESERVE_ZERO_FRACTION);
    }

    /** @param array<string,mixed> $a @return array<string,mixed> */
    private function ordenado(array $a): array
    {
        ksort($a);

        return $a;
    }
}
