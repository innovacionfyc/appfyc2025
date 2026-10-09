<?php

namespace App\Support\CredentialFlow\Reemplazo;

use Illuminate\Support\Facades\DB;

/**
 * Certificado LÓGICO (Fase 10B-2B-2C.1): un `grupo_duplicado` es UN solo certificado aunque tenga varias filas históricas (cada una con su id y, a veces,
 * su propio caso). Un certificado sin grupo es su propio certificado lógico. NO se inventa otra normalización: es la misma agrupación que ya usan
 * el portal (`grupo_duplicado ?? 'id:'.id`) y la consolidación.
 *
 * RAÍZ del certificado lógico = la fila que lleva el reemplazo moderno (solo ella tiene `reemplazado_por_emision_id`; el UNIQUE se conserva):
 *   1. el canónico que ya marcó el mapa de migración (`cf_migraciones_map` relación `canonico` → `cf_certificados_legado`, MISMA regla de
 *      `ElegibilidadLegado::canonico` y `ConsolidacionVariantes`), restringido a los miembros del grupo;
 *   2. si el mapa no marca ninguno, la fila de menor id (determinista).
 * Las demás filas son VARIANTES: nunca emiten ni cargan el enlace; resuelven a través de la raíz.
 */
final class CertificadoLogico
{
    /**
     * @return array{grupo:?string,miembros:list<int>,raiz:int}
     */
    public static function de(int $certificadoId): array
    {
        $grupo = DB::table('cf_certificados_legado')->where('id', $certificadoId)->value('grupo_duplicado');

        return self::deGrupo($certificadoId, $grupo === null ? null : (string) $grupo);
    }

    /**
     * Igual que `de`, pero sin leer el certificado otra vez cuando el grupo ya se conoce.
     *
     * @return array{grupo:?string,miembros:list<int>,raiz:int}
     */
    public static function deGrupo(int $certificadoId, ?string $grupo): array
    {
        if ($grupo === null) {
            return ['grupo' => null, 'miembros' => [$certificadoId], 'raiz' => $certificadoId];
        }
        $miembros = DB::table('cf_certificados_legado')->where('grupo_duplicado', $grupo)->orderBy('id')->pluck('id')->map(fn ($i) => (int) $i)->all();
        if ($miembros === []) {
            return ['grupo' => $grupo, 'miembros' => [$certificadoId], 'raiz' => $certificadoId];
        }
        $marcado = DB::table('cf_migraciones_map')->where('destino_tabla', 'cf_certificados_legado')->where('relacion', 'canonico')
            ->whereIn('destino_id', $miembros)->orderBy('destino_id')->value('destino_id');

        return ['grupo' => $grupo, 'miembros' => $miembros, 'raiz' => $marcado === null ? $miembros[0] : (int) $marcado];
    }

    /** ¿Es esta fila una variante (no la raíz) de un certificado lógico de varias filas? */
    public static function esVariante(int $certificadoId): bool
    {
        $l = self::de($certificadoId);

        return count($l['miembros']) > 1 && $l['raiz'] !== $certificadoId;
    }

    /**
     * Fila del certificado lógico que YA fue reemplazada (con emisión), o null. Con la política «solo la raíz lleva el enlace» es la raíz, pero se
     * busca entre todos los miembros para no depender de ella ante datos previos.
     *
     * @return object{id:int,estado:string,reemplazado_por_emision_id:int|string}|null
     */
    public static function reemplazada(int $certificadoId): ?object
    {
        $l = self::de($certificadoId);

        return DB::table('cf_certificados_legado')->whereIn('id', $l['miembros'])->whereNotNull('reemplazado_por_emision_id')->orderByRaw('id = ? desc', [$l['raiz']])->orderBy('id')->first(['id', 'estado', 'reemplazado_por_emision_id']);
    }

    /**
     * Caso(s) de la raíz y de las variantes para mostrar al administrador (ids de caso y estado; sin datos personales).
     *
     * @return array{raiz:int,caso_raiz:?array{id:int,estado:string,resolucion:?string},reemplazo_emision_id:?int}
     */
    public static function referencia(int $certificadoId): array
    {
        $l = self::de($certificadoId);
        $caso = DB::table('cf_conciliaciones as c')->join('cf_conciliaciones_certificados as k', 'k.conciliacion_id', '=', 'c.id')->where('k.certificado_legado_id', $l['raiz'])
            ->where('c.tipo', 'revision_documento')->orderBy('c.id')->first(['c.id', 'c.estado', 'c.resolucion']);
        $emision = DB::table('cf_certificados_legado')->where('id', $l['raiz'])->value('reemplazado_por_emision_id');

        return [
            'raiz' => $l['raiz'],
            'caso_raiz' => $caso === null ? null : ['id' => (int) $caso->id, 'estado' => (string) $caso->estado, 'resolucion' => $caso->resolucion],
            'reemplazo_emision_id' => $emision === null ? null : (int) $emision,
        ];
    }
}
