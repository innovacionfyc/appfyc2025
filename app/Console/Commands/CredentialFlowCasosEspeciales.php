<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Conciliaciones\CasosEspeciales;
use Illuminate\Console\Command;

/** Foto de SOLO LECTURA (10B-3C-4): todos los casos por estado real, categoría y destino conceptual. No escribe nada ni aplica ninguna decisión. Sin datos personales. */
class CredentialFlowCasosEspeciales extends Command
{
    protected $signature = 'credential-flow:casos-especiales {--json : Salida JSON}';

    protected $description = 'Foto (solo lectura) de los casos de conciliación y su destino: resueltos, soporte, no resolubles, dependencias técnicas y pendientes de evidencia externa';

    public function handle(CasosEspeciales $especiales): int
    {
        $foto = $especiales->foto() + ['identidades_originales' => $especiales->identidadesOriginales()];
        if ($this->option('json')) {
            $this->line(json_encode($foto, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

            return self::SUCCESS;
        }
        $this->line('casos: '.$foto['casos'].' · por estado '.json_encode($foto['por_estado']));
        $this->line('por destino conceptual: '.json_encode($foto['por_destino'], JSON_UNESCAPED_UNICODE));
        foreach ($foto['categoria_destino'] as $k => $v) {
            $this->line("  {$k}: ".json_encode($v));
        }
        $this->line('identidades originales: '.json_encode($foto['identidades_originales'], JSON_UNESCAPED_UNICODE));
        $this->line('grupos sin vía: '.json_encode($foto['grupos_sin_via'], JSON_UNESCAPED_UNICODE));

        return self::SUCCESS;
    }
}
