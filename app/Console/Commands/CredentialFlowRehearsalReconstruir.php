<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Rehearsal\ReconstructorRehearsal;
use Illuminate\Console\Command;
use Throwable;

/**
 * Reconstruye Credential Flow desde una BD limpia, con la secuencia oficial y la verificación final (Fase 11A). SOLO LOCAL: se niega en producción y exige BD, storage y
 * APP_KEY de rehearsal explícitos. No toca ninguna BD que no se llame «…rehearsal…» (o «…_test» si está vacía).
 */
class CredentialFlowRehearsalReconstruir extends Command
{
    protected $signature = 'credential-flow:rehearsal:reconstruir
        {--bd= : BD destino (contiene «rehearsal» o termina en «_test»)}
        {--staging-bd= : BD de staging local de la que se lee (contiene «staging»)}
        {--imagenes= : Carpeta con las imágenes del snapshot (certImages)}
        {--manifest= : Manifiesto JSON de las imágenes}
        {--raiz-storage= : Carpeta dedicada del storage privado del rehearsal (con «rehearsal» en el nombre)}
        {--app-key-env=REHEARSAL_APP_KEY : Variable de entorno con la APP_KEY de rehearsal (base64:…)}
        {--recrear : Si la BD ya existe, borrarla y crearla (solo «…rehearsal…»)}
        {--confirmo-borrar= : Nombre exacto de la BD que se autoriza borrar}
        {--memoria=128M : memory_limit de cada paso}
        {--json : Salida estructurada}';

    protected $description = 'Reconstruye Credential Flow desde una BD limpia (solo local, con guardas) y la verifica contra el baseline certificado.';

    public function handle(): int
    {
        $o = [
            'bd' => $this->option('bd'), 'staging_bd' => $this->option('staging-bd'), 'imagenes' => $this->option('imagenes'), 'manifest' => $this->option('manifest'),
            'raiz_storage' => $this->option('raiz-storage'), 'app_key_env' => $this->option('app-key-env'), 'recrear' => (bool) $this->option('recrear'),
            'confirmo_borrar' => $this->option('confirmo-borrar'), 'memoria' => $this->option('memoria'),
        ];
        try {
            $r = (new ReconstructorRehearsal($o))->ejecutar(fn (string $paso, array $x) => $this->option('json') ? null : $this->line(sprintf('%-22s código %d · %.1fs · pico %s MB', $paso, $x['codigo'], $x['segundos'], $x['pico_mb'] ?? '?')));
        } catch (Throwable $e) {
            $this->error('REHEARSAL_RECHAZADO: '.($e instanceof \RuntimeException ? $e->getMessage() : $e::class));

            return self::FAILURE;
        }
        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_UNESCAPED_UNICODE));
        } else {
            $this->line($r['ok'] ? 'RECONSTRUCCIÓN: OK' : 'RECONSTRUCCIÓN: NO-GO');
        }

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
