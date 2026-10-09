<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Rehearsal\VerificadorRehearsal;
use Illuminate\Console\Command;

/** Verifica (solo lectura, sin PII) que la BD y el storage reconstruidos coinciden con el BASELINE certificado; OK / NO-GO por check. Con --huella calcula huellas deterministas. */
class CredentialFlowRehearsalVerificar extends Command
{
    protected $signature = 'credential-flow:rehearsal:verificar
        {--json : Salida estructurada}
        {--huella : Calcula las huellas deterministas (BD por tabla, global, manifiesto y storage de imágenes)}
        {--sin-imagenes : Omite los checks de imágenes y storage}
        {--sin-via=esperar : «esperar» exige los casos de grupos sin vía; «omitir» exige que no existan}
        {--disco=local : Disco privado donde están las imágenes}
        {--baseline= : Ruta relativa a otro baseline JSON}';

    protected $description = 'Verifica la reconstrucción del rehearsal contra el baseline certificado (conteos, casos, imágenes, códigos, flags, APP_KEY). Código 1 = NO-GO.';

    public function handle(VerificadorRehearsal $verificador): int
    {
        $r = $verificador->verificar([
            'imagenes' => ! $this->option('sin-imagenes'), 'sin_via' => $this->option('sin-via') !== 'omitir', 'huella' => (bool) $this->option('huella'),
            'disco' => (string) $this->option('disco'), 'baseline' => $this->option('baseline') ?: null,
        ]);
        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($r['checks'] as $c) {
                $this->line(sprintf('%s %-34s %s', $c['ok'] ? '[ OK ]' : '[NO-GO]', $c['id'], $c['ok'] ? '' : 'esperado '.json_encode($c['esperado'], JSON_UNESCAPED_UNICODE).' · obtenido '.json_encode($c['obtenido'], JSON_UNESCAPED_UNICODE)));
            }
            foreach (($r['huellas'] ?? []) as $k => $v) {
                if ($k !== 'bd_por_tabla') {
                    $this->line("huella {$k}: ".$v);
                }
            }
            $this->line($r['ok'] ? 'REHEARSAL: OK' : 'REHEARSAL: NO-GO');
        }

        return $r['ok'] ? self::SUCCESS : self::FAILURE;
    }
}
