<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Identidad\GruposSinVia;
use Illuminate\Console\Command;

/** Reporte de SOLO LECTURA (10B-3A): grupos de documentos multi-grupo accesibles que no tienen ninguna vía propia. No abre casos ni escribe nada. */
class CredentialFlowIdentidadGruposSinVia extends Command
{
    protected $signature = 'credential-flow:identidad-grupos-sin-via {--detalle : Lista los grupos (hashes abreviados, sin datos personales)}';

    protected $description = 'Reporta (solo lectura) los grupos de documentos multi-grupo accesibles que hoy no tienen vía de acceso al portal';

    public function handle(GruposSinVia $reporte): int
    {
        $r = $reporte->reporte();
        $this->line('documentos multi-grupo: '.$r['documentos_multigrupo'].' · con caso de identidad: '.$r['con_caso_identidad'].' · accesibles sin caso: '.$r['accesibles_sin_caso']);
        $this->line('grupos en documentos accesibles: '.$r['grupos_en_accesibles'].' · accesibles: '.$r['grupos_accesibles'].' · SIN vía: '.$r['grupos_sin_via'].' · por motivo '.json_encode($r['por_motivo']));
        if ($this->option('detalle')) {
            foreach ($r['items'] as $i) {
                $this->line(substr($i['documento_hash'], 0, 10).' '.substr($i['grupo_hash'], 0, 10).' certs '.$i['certificados'].' '.$i['motivo']);
            }
        }

        return self::SUCCESS;
    }
}
