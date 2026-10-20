<?php

namespace App\Support\CredentialFlow\Importacion;

use Illuminate\Support\Facades\DB;

/**
 * Trazabilidad de la importación inteligente: que `fila_origen` guarde la fila REAL de la hoja de Excel y no la línea del CSV intermedio.
 *
 * El importador de siempre numera según el CSV revisado que se le entrega (encabezado = línea 1, primera persona = línea 2…). Ese CSV lo genera `ArchivoRevisado`
 * en el mismo orden de `$incluidos`, así que la correspondencia es inequívoca: la persona en la posición `i` quedó importada con `fila_origen = i + 2`. Antes de
 * reemplazar nada se COMPRUEBA esa correspondencia (mismas cantidades, ninguna línea repetida o ausente); si no cuadra, se lanza una excepción y la transacción
 * de la confirmación deshace TODO. Nunca se deduce por nombres ni documentos. Solo se toca `fila_origen` (no `updated_at` ni ningún otro dato), y no se tocan los QA.
 */
final class FilasDeOrigen
{
    /** Línea del CSV revisado en la que queda la persona de la posición 0 (la línea 1 es el encabezado). */
    public const PRIMERA_LINEA = 2;

    /**
     * @param  list<array<string,mixed>>  $incluidos  las personas, en el orden exacto del CSV revisado; cada una trae su `fila` real del Excel
     * @return array<int,int> posición (desde 0) → id del participante
     *
     * @throws ImportacionException
     */
    public static function asignar(int $loteId, array $incluidos): array
    {
        $esperadas = [];
        foreach ($incluidos as $i => $p) {
            $fila = $p['fila'] ?? null;
            if (! is_int($fila) || $fila < 1 || isset($esperadas[$fila])) {
                throw self::inconsistente('Una de las filas del Excel falta o está repetida en el borrador');
            }
            $esperadas[$fila] = $i;
        }

        $registros = DB::table('cf_participantes')->where('lote_id', $loteId)->where('es_control_qa', false)->whereNull('deleted_at')->get(['id', 'fila_origen']);
        if ($registros->count() !== count($incluidos)) {
            throw self::inconsistente('La cantidad de personas importadas no coincide con la de las revisadas');
        }

        // Línea del CSV → id. Debe haber exactamente una persona por cada línea 2…n+1.
        $porLinea = [];
        foreach ($registros as $r) {
            $linea = $r->fila_origen === null ? null : (int) $r->fila_origen;
            if ($linea === null || isset($porLinea[$linea])) {
                throw self::inconsistente('Una persona importada quedó sin línea de origen o con una repetida');
            }
            $porLinea[$linea] = (int) $r->id;
        }
        $ids = [];
        foreach (array_keys($incluidos) as $i) {
            $id = $porLinea[$i + self::PRIMERA_LINEA] ?? null;
            if ($id === null) {
                throw self::inconsistente('Falta la persona de una de las líneas importadas');
            }
            $ids[$i] = $id;
        }

        foreach ($ids as $i => $id) {
            DB::table('cf_participantes')->where('id', $id)->update(['fila_origen' => $incluidos[$i]['fila']]);
        }

        // Comprobación final contra lo realmente guardado.
        $guardadas = DB::table('cf_participantes')->where('lote_id', $loteId)->where('es_control_qa', false)->whereNull('deleted_at')->pluck('fila_origen')->map(fn ($f) => (int) $f)->sort()->values()->all();
        $filasExcel = array_keys($esperadas);
        sort($filasExcel);
        if ($guardadas !== $filasExcel) {
            throw self::inconsistente('Las filas guardadas no coinciden con las del Excel');
        }

        return $ids;
    }

    private static function inconsistente(string $detalle): ImportacionException
    {
        return new ImportacionException(
            ImportacionException::FILA_ORIGEN_INCONSISTENTE,
            "No se pudo comprobar de qué fila del Excel viene cada persona ({$detalle}). No se creó la base ni ningún participante; inténtalo de nuevo y, si sigue igual, avisa al equipo técnico.",
            409,
        );
    }
}
