<?php

namespace App\Support\CredentialFlow\Portal;

use App\Models\CredentialFlow\Correo;
use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\Participante;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Certificados MODERNOS (participantes nativos con una emisión oficial) vistos desde el portal público. Solo lee.
 *
 * Una fila = un participante con su emisión vigente (o, si ya no la tiene, la última revocada). Tiene el mismo papel que una fila de
 * `CertificadoLegado` en `AccesoPortal`: documento (clave), GRUPO DE NOMBRE CONSERVADOR y correos válidos, de modo que las reglas de identidad
 * (documento + correo autenticado + grupo, homónimos, alcance ambiguo) son EXACTAMENTE las mismas y no se duplican ni se relajan.
 *
 * Quedan fuera:
 *  - los participantes cuyo certificado ya es el REEMPLAZO de un histórico (`reemplazado_por_emision_id`): esos ya se muestran como «actualizado» desde
 *    el histórico y no deben contarse dos veces ni cambiar su alcance;
 *  - los participantes eliminados (soft delete): sin participante no hay identidad contra la que autenticar.
 *
 * La emisión moderna NUNCA se alcanza por su id ni por su código: solo a través del documento y del grupo de la sesión.
 */
final class EmisionesModernas
{
    /**
     * @return Collection<int,object{participante_id:int,emision_id:int,codigo:string,estado:string,nombre:string,grupo:string,correos:array<string,bool>,datos:array<string,mixed>,emitido_at:?string}>
     */
    public static function filas(string $clave, ?int $desde = null): Collection
    {
        $participantes = DB::table('cf_participantes as p')
            ->where('p.documento_clave', $clave)
            ->whereNull('p.deleted_at')
            // Corrección de correo: una sesión abierta antes del cambio (`$desde`) no hereda el certificado de ese participante; debe entrar de nuevo con el correo vigente.
            ->when($desde !== null && Schema::hasColumn('cf_participantes', 'correo_actualizado_at'), fn ($q) => $q->where(fn ($w) => $w->whereNull('p.correo_actualizado_at')->orWhere('p.correo_actualizado_at', '<=', Carbon::createFromTimestamp($desde, config('app.timezone'))->toDateTimeString())))
            ->whereExists(fn ($q) => $q->selectRaw('1')->from('cf_emisiones as e')->whereColumn('e.participante_id', 'p.id'))
            ->whereNotExists(fn ($q) => $q->selectRaw('1')->from('cf_emisiones as e2')->join('cf_certificados_legado as c', 'c.reemplazado_por_emision_id', '=', 'e2.id')->whereColumn('e2.participante_id', 'p.id'))
            ->get(['p.id', 'p.nombre_completo', 'p.correo_normalizado', 'p.correo_estado']);
        if ($participantes->isEmpty()) {
            return collect();
        }
        $ids = $participantes->pluck('id')->all();

        // Por participante: la emisión vigente o, si no la hay, la última (revocada).
        $emisiones = [];
        foreach (DB::table('cf_emisiones')->whereIn('participante_id', $ids)->orderBy('id')->get(['id', 'codigo', 'estado', 'participante_id', 'datos_snapshot', 'emitido_at']) as $e) {
            $actual = $emisiones[$e->participante_id] ?? null;
            if ($actual === null || $actual->estado !== Emision::EMITIDA) {
                $emisiones[$e->participante_id] = $e;
            }
        }

        $correos = [];
        foreach (DB::table('cf_correos')->whereIn('participante_id', $ids)->where('estado', Correo::ESTADO_VALIDO)->get(['participante_id', 'correo_normalizado']) as $c) {
            $correos[$c->participante_id][$c->correo_normalizado] = true;
        }

        // Vínculos explícitos de identidad (misma persona, grafía distinta): solo cambian el GRUPO con el que se lee la fila moderna; nunca las históricas.
        $vinculos = VinculosIdentidadModerna::mapa($clave, $desde);

        return $participantes->map(function ($p) use ($emisiones, $correos, $vinculos) {
            $e = $emisiones[$p->id];
            $propios = $correos[$p->id] ?? [];
            if ($p->correo_estado === Participante::CORREO_VALIDO && $p->correo_normalizado !== null) {
                $propios[$p->correo_normalizado] = true;
            }

            return (object) [
                'participante_id' => (int) $p->id,
                'emision_id' => (int) $e->id,
                'codigo' => (string) $e->codigo,
                'estado' => (string) $e->estado,
                'nombre' => (string) $p->nombre_completo,
                'grupo' => $vinculos[NombreConservador::grupoId((string) $p->nombre_completo)] ?? NombreConservador::grupoId((string) $p->nombre_completo),
                'correos' => $propios,
                'datos' => is_array($e->datos_snapshot) ? $e->datos_snapshot : (json_decode((string) $e->datos_snapshot, true) ?: []),
                'emitido_at' => $e->emitido_at,
            ];
        })->values();
    }

    /** ¿Esta fila habilita el acceso? Solo una emisión VIGENTE: un participante con todo revocado no obtiene OTP por ella. */
    public static function habilita(object $fila): bool
    {
        return $fila->estado === Emision::EMITIDA;
    }

    /** Emisión moderna del documento por id, o null: la pertenencia (documento + grupo) la decide quien llama con `perteneceA`. */
    public static function pertenece(object $fila, string|array|null $grupos): bool
    {
        if ($grupos === null) {
            return true;
        }
        $lista = array_values(array_unique(array_map('strval', is_array($grupos) ? $grupos : [$grupos])));

        return $lista !== [] && in_array($fila->grupo, $lista, true);
    }
}
