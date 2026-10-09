<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Support\CredentialFlow\Migracion\RollbackNoPermitido;
use Illuminate\Support\Facades\DB;

/**
 * ROLLBACK TÉCNICO de la Fase 10A (solo el comando credential-flow:conciliaciones:rollback; sin ruta ni pantalla). Elimina ÚNICAMENTE lo que
 * creó el detector en las tres tablas de conciliación; jamás toca una tabla histórica.
 *
 * Es TODO O NADA: se NIEGA (sin tocar nada) si cualquier caso tiene una decisión humana o actividad posterior a la detección: estado distinto
 * de «abierto», resolución, resolutor, o cualquier evento que no sea el «detectado» automático (sin actor). Los eventos son de solo añadir
 * para la aplicación; este rollback es la única excepción y borra por SQL, dentro de una transacción, en el orden eventos → pivote → casos.
 */
final class RollbackConciliaciones
{
    /** @return array{eventos:int,certificados:int,casos:int} conteos borrados */
    public function revertir(): array
    {
        return DB::transaction(function () {
            // Bloquea los casos mientras se revisa y se borra (en MySQL; SQLite ignora el bloqueo).
            $casos = DB::table('cf_conciliaciones')->lockForUpdate()->get(['id', 'estado', 'resolucion', 'resuelto_por', 'resuelto_at']);
            if ($casos->isEmpty()) {
                return ['eventos' => 0, 'certificados' => 0, 'casos' => 0];
            }

            $modificados = $casos->filter(fn ($c) => $c->estado !== Conciliacion::ABIERTO || $c->resolucion !== null || $c->resuelto_por !== null || $c->resuelto_at !== null)->count();
            // Cualquier evento que no sea el «detectado» automático.
            $eventosHumanos = DB::table('cf_conciliaciones_eventos')->where(fn ($q) => $q->where('accion', '!=', ConciliacionEvento::DETECTADO)->orWhereNotNull('actor_id'))->count();
            // Más de un «detectado» por caso tampoco es lo que escribe el detector.
            $repetidos = DB::table('cf_conciliaciones_eventos')->where('accion', ConciliacionEvento::DETECTADO)->groupBy('conciliacion_id')->havingRaw('COUNT(*) > 1')->get(['conciliacion_id'])->count();

            if ($modificados > 0 || $eventosHumanos > 0 || $repetidos > 0) {
                throw new RollbackNoPermitido(RollbackNoPermitido::CASOS_CON_DECISIONES, "Hay casos con decisiones o eventos posteriores a la detección (casos modificados: {$modificados}, eventos no automáticos: {$eventosHumanos}). No se revierte nada.");
            }

            $borrado = [];
            $borrado['eventos'] = DB::table('cf_conciliaciones_eventos')->delete();
            $borrado['certificados'] = DB::table('cf_conciliaciones_certificados')->delete();
            $borrado['casos'] = DB::table('cf_conciliaciones')->delete();

            return $borrado;
        });
    }
}
