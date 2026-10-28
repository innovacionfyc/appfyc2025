<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Portal\FamiliaDocumento;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Informe de SOLO LECTURA de los certificados que el portal NO muestra porque su identidad es ambigua: el mismo número y el mismo nombre registrados con
 * tipos de documento explícitos distintos (C.C. y T.I., C.E. y C.C.…). El portal nunca los une por número; quedan fuera hasta que un administrador
 * resuelva cuál es el tipo correcto. Solo imprime ids y tipos (sin nombres, documentos ni correos). NO modifica nada.
 */
class CredentialFlowPortalAmbiguedades extends Command
{
    protected $signature = 'credential-flow:portal-ambiguedades';

    protected $description = 'Lista (solo lectura) los certificados que el portal no muestra por tipos de documento distintos con el mismo número y nombre';

    public function handle(): int
    {
        // Solo un número que aparece con algún prefijo de tipo puede tener ambigüedad (FamiliaDocumento::excluidos).
        $raices = [];
        foreach (DB::table('cf_participantes')->whereNull('deleted_at')->select('documento_clave')->distinct()->pluck('documento_clave') as $clave) {
            if (FamiliaDocumento::tipoDeClave((string) $clave) !== null) {
                $raices[FamiliaDocumento::raiz((string) $clave)] = true;
            }
        }
        foreach (DB::table('cf_certificados_legado')->select('documento_clave')->distinct()->pluck('documento_clave') as $clave) {
            if (FamiliaDocumento::tipoDeClave((string) $clave) !== null) {
                $raices[FamiliaDocumento::raiz((string) $clave)] = true;
            }
        }

        $unidades = 0;
        $filas = [];
        foreach (array_keys($raices) as $raiz) {
            $x = FamiliaDocumento::excluidos((string) $raiz);
            $unidades += $x['unidades'];
            foreach (array_keys($x['participantes']) as $id) {
                $p = DB::table('cf_participantes')->where('id', $id)->first(['id', 'lote_id', 'documento_clave', 'es_control_qa']);
                $e = DB::table('cf_emisiones')->where('participante_id', $id)->orderByDesc('id')->first(['id', 'estado']);
                $filas[] = ['Participante', "#{$p->id}", "base #{$p->lote_id}", $p->es_control_qa ? 'QA' : 'oficial', FamiliaDocumento::tipoDeClave((string) $p->documento_clave), $e ? "#{$e->id} ({$e->estado})" : '-'];
            }
            foreach (array_keys($x['historicos']) as $id) {
                $h = DB::table('cf_certificados_legado')->where('id', $id)->first(['id', 'evento_id', 'documento_clave']);
                $filas[] = ['Histórico', "#{$h->id}", "evento #{$h->evento_id}", '-', FamiliaDocumento::tipoDeClave((string) $h->documento_clave), '-'];
            }
        }

        if ($filas === []) {
            $this->info('Sin ambigüedades de tipo de documento: el portal no está ocultando ningún certificado por este motivo.');

            return self::SUCCESS;
        }
        $this->warn("Unidades ambiguas (mismo número y nombre con tipos distintos): {$unidades}. Registros que el portal NO muestra: ".count($filas).'.');
        $this->table(['Registro', 'Id', 'Base / evento', 'Clase', 'Tipo guardado', 'Última emisión'], $filas);
        $this->line('Para resolverlo: confirmar el tipo correcto de cada persona y corregir el documento del registro equivocado (o revocar su emisión si era un error).');

        return self::SUCCESS;
    }
}
