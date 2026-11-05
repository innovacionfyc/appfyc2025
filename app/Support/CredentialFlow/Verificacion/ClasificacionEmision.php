<?php

namespace App\Support\CredentialFlow\Verificacion;

use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * ¿Una emisión es una PRUEBA INTERNA (QA)? Solo por relaciones estructuradas, nunca por textos (nombre de plantilla, de base o de evento):
 *  - su participante está marcado como contacto de control (`cf_participantes.es_control_qa`), o
 *  - se generó con una plantilla de pruebas internas (`cf_plantillas.es_plantilla_qa`, por el `plantilla_id` guardado en la emisión).
 * Basta una de las dos: las históricas (emitidas con una plantilla normal antes de existir la marca QA y marcadas después) se reconocen por el participante,
 * y las modernas siguen reconociéndose aunque después se quite una de las marcas. La revocación se decide ANTES y prevalece (esto no mira el estado).
 * Sin las columnas (código desplegado antes de su migración) nada es prueba interna, como el resto de módulos QA.
 */
final class ClasificacionEmision
{
    public static function esPruebaInterna(int $participanteId, int $plantillaId): bool
    {
        if (Schema::hasColumn('cf_participantes', 'es_control_qa')
            && (bool) DB::table('cf_participantes')->where('id', $participanteId)->value('es_control_qa')) {
            return true;
        }

        return Schema::hasColumn('cf_plantillas', 'es_plantilla_qa')
            && (bool) DB::table('cf_plantillas')->where('id', $plantillaId)->value('es_plantilla_qa');
    }
}
