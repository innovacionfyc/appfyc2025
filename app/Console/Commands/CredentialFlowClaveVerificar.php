<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Rehearsal\GuardiaClave;
use Illuminate\Console\Command;

/**
 * Preflight de la APP_KEY (Fase 11A): ¿la clave actual es la misma con la que se generaron los HMAC persistidos? Nunca imprime la clave ni su huella completa.
 * Sale con 1 y APP_KEY_NO_COINCIDE_CON_DATOS_DERIVADOS si no coincide (NO-GO). Una BD derivada con una clave NO es portable a otra clave.
 */
class CredentialFlowClaveVerificar extends Command
{
    protected $signature = 'credential-flow:clave:verificar {--estricto : También falla si la corrida no registró huella (datos anteriores a 11A)} {--json}';

    protected $description = 'Verifica que la APP_KEY actual coincide con la de los datos derivados de Credential Flow (sin revelar la clave).';

    public function handle(): int
    {
        $e = GuardiaClave::estado();
        $ok = $e['estado'] === 'coincide' || $e['estado'] === 'sin_datos' || ($e['estado'] === 'sin_registro' && ! $this->option('estricto'));
        $salida = ['estado' => $e['estado'], 'corrida_id' => $e['corrida_id'], 'huella_actual' => substr($e['huella_actual'], 0, 12), 'huella_registrada' => $e['huella_registrada'] === null ? null : substr($e['huella_registrada'], 0, 12), 'ok' => $ok];
        $this->line($this->option('json') ? (string) json_encode($salida) : 'APP_KEY: '.$e['estado'].($ok ? ' · OK' : ' · NO-GO '.GuardiaClave::CODIGO));

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
