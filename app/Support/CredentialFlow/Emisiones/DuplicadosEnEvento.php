<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use Illuminate\Support\Facades\DB;

/**
 * Evita certificar dos veces a la misma persona en el MISMO evento desde bases distintas (por ejemplo, al importar un Excel corregido en una base nueva).
 *
 *  - Solo mira emisiones OFICIALES VIGENTES de OTRAS bases del mismo evento; los contactos de control (QA) nunca cuentan, ni de un lado ni del otro.
 *  - Coincidencia INEQUÍVOCA = la misma clave de documento (`documento_clave`). Coincidencia DUDOSA = mismos dígitos con otra clave (otro tipo de
 *    documento): se pide revisarla. En ambos casos se bloquea la emisión; nunca se elimina ni se fusiona a nadie.
 *  - No prohíbe varias bases por evento ni toca lo ya emitido. La reemisión (nueva versión de la MISMA persona) no pasa por aquí. Una base sin evento
 *    no tiene con qué comparar y no se revisa.
 */
final class DuplicadosEnEvento
{
    /**
     * @param  iterable<Participante>  $participantes
     * @return list<array{participante_id:int, nombre:string, mensaje:string, tipo:string}>
     */
    public static function buscar(Lote $lote, iterable $participantes): array
    {
        $eventoId = $lote->evento_id ?? null;
        $personas = [];
        foreach ($participantes as $p) {
            if (! (bool) ($p->es_control_qa ?? false)) {
                $personas[] = $p;
            }
        }
        if ($eventoId === null || $personas === []) {
            return [];
        }

        $previas = DB::table('cf_emisiones as e')
            ->join('cf_participantes as p', 'p.id', '=', 'e.participante_id')
            ->join('cf_lotes as l', 'l.id', '=', 'e.lote_id')
            ->where('l.evento_id', $eventoId)
            ->where('e.lote_id', '<>', $lote->id)
            ->where('e.estado', Emision::EMITIDA)
            ->where('p.es_control_qa', false)
            ->get(['p.documento_clave', 'l.nombre as base']);
        if ($previas->isEmpty()) {
            return [];
        }

        $porClave = [];
        $porDigitos = [];
        foreach ($previas as $f) {
            $clave = (string) $f->documento_clave;
            if ($clave === '') {
                continue;
            }
            $porClave[$clave] ??= (string) $f->base;
            if (($d = self::digitos($clave)) !== '') {
                $porDigitos[$d] ??= (string) $f->base;
            }
        }

        $conflictos = [];
        foreach ($personas as $p) {
            $clave = (string) $p->documento_clave;
            if ($clave !== '' && isset($porClave[$clave])) {
                $conflictos[] = ['participante_id' => (int) $p->id, 'nombre' => (string) $p->nombre_completo, 'tipo' => 'mismo_documento',
                    'mensaje' => "ya tiene un certificado oficial vigente de este evento en la base «{$porClave[$clave]}». Para no duplicarlo, márcalo como no elegible en esta base (o revoca el otro si era un error)."];
            } elseif (($d = self::digitos($clave)) !== '' && isset($porDigitos[$d])) {
                $conflictos[] = ['participante_id' => (int) $p->id, 'nombre' => (string) $p->nombre_completo, 'tipo' => 'revisar',
                    'mensaje' => "su número de documento coincide con el de una persona ya certificada en la base «{$porDigitos[$d]}» (con otro tipo de documento). Revisa si es la misma persona antes de emitir."];
            }
        }

        return $conflictos;
    }

    /**
     * @param  iterable<Participante>  $participantes
     *
     * @throws EmisionException
     */
    public static function exigir(Lote $lote, iterable $participantes): void
    {
        $conflictos = self::buscar($lote, $participantes);
        if ($conflictos === []) {
            return;
        }
        $n = count($conflictos);

        throw new EmisionException(
            EmisionException::DUPLICADO_EN_EVENTO,
            ($n === 1 ? 'Una persona ya tiene' : "{$n} personas ya tienen").' un certificado de este mismo evento en otra base (o hay que revisar si es la misma persona). No se emitió ningún certificado: revisa la lista.',
            409,
            array_slice($conflictos, 0, 50)
        );
    }

    private static function digitos(string $clave): string
    {
        return (string) preg_replace('/\D/', '', $clave);
    }
}
