<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\FuentesCredential;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera (o verifica con --verify) resources/fonts/credential-flow/metricas.json a partir de los
 * TTF de Outfit. Es la tabla que comparten el editor (JS) y el futuro generador de PDF (PHP).
 */
class CredentialFlowFuentesMetricas extends Command
{
    protected $signature = 'credential-flow:fuentes-metricas {--verify : Solo comprueba que metricas.json coincide con los TTF; no escribe nada}';

    protected $description = 'Genera o verifica la tabla de métricas de las fuentes de Credential Flow';

    public function handle(): int
    {
        try {
            $esperado = FuentesCredential::serializarMetricas(FuentesCredential::generarMetricas());
        } catch (Throwable $e) {
            $this->error('No se pudieron leer las fuentes: '.$e->getMessage());

            return self::FAILURE;
        }

        $ruta = FuentesCredential::rutaMetricas();

        if ($this->option('verify')) {
            if (! is_file($ruta)) {
                $this->error('Falta metricas.json.');

                return self::FAILURE;
            }
            if (file_get_contents($ruta) !== $esperado) {
                $this->error('metricas.json NO coincide con los TTF. Regenera con: php artisan credential-flow:fuentes-metricas');

                return self::FAILURE;
            }
            $this->info('metricas.json coincide con los TTF.');

            return self::SUCCESS;
        }

        file_put_contents($ruta, $esperado);
        $this->info('metricas.json generado ('.number_format(strlen($esperado)).' bytes).');

        return self::SUCCESS;
    }
}
