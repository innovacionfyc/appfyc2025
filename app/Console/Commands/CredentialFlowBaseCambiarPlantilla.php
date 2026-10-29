<?php

namespace App\Console\Commands;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Plantilla;
use App\Support\CredentialFlow\Emisiones\AdministradorAutorizado;
use App\Support\CredentialFlow\Emisiones\CambioPlantillaEmitida;
use App\Support\CredentialFlow\Emisiones\EmisionException;
use App\Support\CredentialFlow\LogSeguro;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Cambio AUTORIZADO de la plantilla de una base que ya emitió certificados (ver CambioPlantillaEmitida). Por defecto SOLO simula y muestra la huella del plan;
 * para ejecutar hacen falta --ejecutar, la huella de la simulación (--plan), un administrador (--usuario) y un motivo. No modifica ningún certificado.
 */
class CredentialFlowBaseCambiarPlantilla extends Command
{
    protected $signature = 'credential-flow:base-cambiar-plantilla
        {base : Id de la base (cf_lotes) que ya emitió certificados}
        {plantilla : Id de la plantilla institucional nueva (con su variante QA)}
        {--motivo= : Por qué se cambia (10 a 500 caracteres)}
        {--usuario= : Id del administrador que autoriza (rol super-admin o admin)}
        {--plan= : Huella que mostró la simulación (obligatoria para ejecutar)}
        {--ejecutar : Cambia la plantilla de verdad (sin esta opción solo simula)}';

    protected $description = 'Cambia, con autorización, la plantilla de una base que ya emitió certificados (sin tocar los certificados); por defecto solo simula';

    public function handle(CambioPlantillaEmitida $cambio): int
    {
        $lote = Lote::query()->find((int) $this->argument('base'));
        $nueva = Plantilla::query()->find((int) $this->argument('plantilla'));
        if ($lote === null || $nueva === null) {
            $this->error($lote === null ? 'La base no existe (o está eliminada).' : 'La plantilla no existe (o está eliminada).');

            return self::FAILURE;
        }

        $plan = $cambio->planear($lote, $nueva);
        $this->line(($this->option('ejecutar') ? 'PLAN' : 'SIMULACIÓN (no se escribió nada)').": base #{$plan['lote_id']} «{$plan['base']}»");
        $this->line("  Plantilla actual: #{$plan['actual']['id']} «{$plan['actual']['nombre']}»");
        $this->line("  Plantilla nueva:  #{$plan['nueva']['id']} «{$plan['nueva']['nombre']}» · variante QA ".($plan['variante_qa']['id'] ? "#{$plan['variante_qa']['id']} «{$plan['variante_qa']['nombre']}»" : 'NINGUNA'));
        $this->line("  Certificados vigentes: {$plan['vigentes_oficiales']} oficiales + {$plan['vigentes_qa']} QA. NO cambian: siguen con su PDF hasta que se reemitan.");
        $this->line('  Después del cambio: revisar y confirmar la vista previa de la plantilla nueva en la base antes de reemitir los oficiales.');
        foreach ($plan['bloqueos'] as $b) {
            $this->error('  Bloqueo: '.$b);
        }
        $this->line("  Huella del plan: {$plan['huella']}");

        if (! $this->option('ejecutar')) {
            if ($plan['bloqueos'] === []) {
                $this->line("Para ejecutar: --usuario=ID --motivo=\"...\" --plan={$plan['huella']} --ejecutar (requiere autorización).");
            }

            return $plan['bloqueos'] === [] ? self::SUCCESS : self::FAILURE;
        }

        if (! is_string($this->option('plan')) || $this->option('plan') === '') {
            $this->error('La simulación es obligatoria: ejecuta primero sin --ejecutar y pasa su huella con --plan=...');

            return self::FAILURE;
        }

        try {
            $usuario = AdministradorAutorizado::exigir($this->option('usuario'));
            $hecho = AdministradorAutorizado::corregir($usuario, $lote->id, fn () => $cambio->ejecutar($lote, $nueva, (string) $this->option('plan'), (string) $this->option('motivo'), $usuario->id));
        } catch (EmisionException $e) {
            $this->error("{$e->codigo}: {$e->getMessage()}");

            return self::FAILURE;
        } catch (Throwable $e) {
            Log::error('Credential Flow: error en el cambio autorizado de plantilla', ['lote' => $lote->id, 'error' => LogSeguro::resumen($e)]);
            $this->error('ERROR_INESPERADO: el cambio se revirtió completo (la base sigue con su plantilla). Revisa storage/logs/laravel.log.');

            return self::FAILURE;
        }

        $this->info("CAMBIO REALIZADO: la base #{$hecho['lote_id']} usa ahora la plantilla #{$hecho['nueva']['id']} (antes #{$hecho['actual']['id']}). Ningún certificado cambió.");
        $this->line('Siguiente: en la base, abre la vista previa de un participante, revísala y confirma la plantilla; luego simula credential-flow:base-reemitir.');

        return self::SUCCESS;
    }
}
