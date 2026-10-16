<?php

namespace App\Support\CredentialFlow\Encuestas;

use App\Models\CredentialFlow\EncuestaVersion;
use App\Support\CredentialFlow\Portal\Hmac;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Elegibilidad de la encuesta OPCIONAL dentro del portal. Solo decide si se ofrece y a qué certificado pertenece; la definición, la validación y el
 * guardado son del motor (`ServicioEncuestas`) y la autorización del certificado sigue siendo de `AccesoPortal` (las tarjetas ya salen de su alcance).
 *
 * Una tarjeta es elegible cuando es DESCARGABLE (certificado entregable dentro del alcance de la sesión), su certificado tiene evento y hay una
 * encuesta activa con versión vigente. La versión del portal es CONTINUIDAD de la encuesta histórica, no una campaña nueva: si el certificado ya
 * tiene CUALQUIER respuesta vinculada (legado o credential_flow, de cualquier versión) se considera respondido y no se vuelve a ofrecer.
 * Solo cuentan las relaciones COMPROBADAS: la respuesta ligada al certificado (o a una copia consolidada de su mismo grupo de duplicados y mismo
 * documento). Las respuestas huérfanas, ambiguas o sin participante NO bloquean nada: nunca se infiere un vínculo. La protección contra
 * escrituras duplicadas (certificado + versión) sigue siendo del motor. «Más tarde» solo vive en la sesión del portal: no escribe en la base.
 */
final class EncuestaPortal
{
    public const PENDIENTE = 'pendiente';

    public const RESPONDIDA = 'respondida';

    public const DESPUES = 'despues';

    /** Clave de sesión con los certificados cuya invitación se pospuso en esta sesión. */
    public const SESION_DESPUES = 'cf_portal.encuesta_despues';

    private const MAX_POSPUESTOS = 200;

    public function __construct(private readonly ServicioEncuestas $motor = new ServicioEncuestas) {}

    /** Versión vigente de la encuesta activa o null (sin encuesta no se ofrece nada). */
    public function version(): ?EncuestaVersion
    {
        return $this->motor->activa();
    }

    /**
     * Estado de la encuesta por certificado para las tarjetas dadas (solo las elegibles aparecen en el resultado). Dos consultas como máximo.
     *
     * @param  list<array<string,mixed>>  $tarjetas
     * @return array<int,string> id de certificado → pendiente | respondida | despues
     */
    public function estados(array $tarjetas, Session $sesion, ?EncuestaVersion $version = null): array
    {
        $version ??= $this->version();
        $ids = array_values(array_map(fn ($t) => (int) $t['id'], array_filter($tarjetas, fn ($t) => ($t['descargable'] ?? false) === true)));
        if ($version === null || $ids === []) {
            return [];
        }

        $filas = DB::table('cf_certificados_legado')->whereIn('id', $ids)->whereNotNull('evento_id')->get(['id', 'evento_id', 'documento_clave', 'grupo_duplicado']);
        if ($filas->isEmpty()) {
            return [];
        }
        $conEvento = $filas->pluck('id')->map(fn ($i) => (int) $i)->all();
        $respondidas = array_flip($this->respondidos($filas));
        $despues = array_flip($this->pospuestos($sesion));

        $estados = [];
        foreach ($conEvento as $id) {
            $estados[$id] = isset($respondidas[$id]) ? self::RESPONDIDA : (isset($despues[$id]) ? self::DESPUES : self::PENDIENTE);
        }

        return $estados;
    }

    /**
     * Contexto de un certificado dentro del alcance: la tarjeta elegible y su evento, o null (404 genérico en el controlador).
     *
     * @param  list<array<string,mixed>>  $tarjetas  tarjetas YA autorizadas por `AccesoPortal::tarjetas`
     * @return array{tarjeta:array<string,mixed>,evento_id:int}|null
     */
    public function contexto(int $certificado, array $tarjetas): ?array
    {
        foreach ($tarjetas as $t) {
            if ((int) $t['id'] !== $certificado) {
                continue;
            }
            if (($t['descargable'] ?? false) !== true) {
                return null;
            }
            $evento = DB::table('cf_certificados_legado as c')->join('cf_eventos as e', 'e.id', '=', 'c.evento_id')->where('c.id', $certificado)->value('e.id');

            return $evento === null ? null : ['tarjeta' => $t, 'evento_id' => (int) $evento];
        }

        return null;
    }

    /** ¿La persona ya respondió el evento de este certificado (respuesta vinculada, histórica o moderna, de cualquier versión)? */
    public function respondida(int $certificado): bool
    {
        $filas = DB::table('cf_certificados_legado')->where('id', $certificado)->get(['id', 'evento_id', 'documento_clave', 'grupo_duplicado']);

        return $this->respondidos($filas) !== [];
    }

    /**
     * Ids (de las filas dadas) cuyo EVENTO ya fue respondido por la misma persona. Regla: UNA respuesta por persona y evento, sin importar cuántos
     * certificados tenga ni la versión de la encuesta. Se considera respondido cuando existe una respuesta:
     *   1. ligada al propio certificado o a otra fila de su MISMO grupo de duplicados y documento (vínculo comprobado), o
     *   2. del MISMO evento y la MISMA identidad (HMAC del documento), siempre que sea moderna o histórica `vinculada`.
     * Las respuestas históricas ambiguas, sin participante o de evento eliminado NO cuentan: nunca se infiere identidad desde ellas. Otro evento
     * de la misma persona no se ve afectado.
     *
     * @param  Collection<int,object>  $filas  id, evento_id, documento_clave, grupo_duplicado
     * @return list<int>
     */
    private function respondidos($filas): array
    {
        $ids = $filas->pluck('id')->map(fn ($i) => (int) $i)->all();
        $grupos = $filas->filter(fn ($f) => $f->grupo_duplicado !== null)->pluck('grupo_duplicado')->unique()->values()->all();
        $miembros = [];   // fila de grupo → ids del grupo (mismo documento)
        if ($grupos !== []) {
            $docs = $filas->pluck('documento_clave')->unique()->values()->all();
            $rel = DB::table('cf_certificados_legado')->whereIn('grupo_duplicado', $grupos)->whereIn('documento_clave', $docs)->get(['id', 'documento_clave', 'grupo_duplicado']);
            foreach ($rel as $m) {
                $miembros[$m->documento_clave.'|'.$m->grupo_duplicado][] = (int) $m->id;
            }
        }
        $todos = array_values(array_unique(array_merge($ids, ...array_values($miembros) ?: [[]])));
        $con = array_flip(DB::table('cf_encuestas_respuestas')->whereIn('certificado_legado_id', $todos)->distinct()->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all());

        // Misma persona + mismo evento (HMAC del documento): solo respuestas modernas o históricas vinculadas.
        $hash = [];
        foreach ($filas->pluck('documento_clave')->unique() as $doc) {
            $hash[$doc] = Hmac::de('documento', (string) $doc);
        }
        $eventos = $filas->pluck('evento_id')->filter()->unique()->values()->all();
        $porEvento = [];
        if ($eventos !== [] && $hash !== []) {
            $rs = DB::table('cf_encuestas_respuestas')->whereIn('evento_id', $eventos)->whereIn('documento_hash', array_values($hash))
                ->where(fn ($q) => $q->whereNull('clasificacion')->orWhere('clasificacion', 'vinculada'))->get(['evento_id', 'documento_hash']);
            foreach ($rs as $r) {
                $porEvento[$r->evento_id.'|'.$r->documento_hash] = true;
            }
        }

        $resultado = [];
        foreach ($filas as $f) {
            if ($f->evento_id !== null && isset($porEvento[$f->evento_id.'|'.$hash[$f->documento_clave]])) {
                $resultado[] = (int) $f->id;

                continue;
            }
            $grupo = $f->grupo_duplicado === null ? [(int) $f->id] : array_merge([(int) $f->id], $miembros[$f->documento_clave.'|'.$f->grupo_duplicado] ?? []);
            foreach ($grupo as $id) {
                if (isset($con[$id])) {
                    $resultado[] = (int) $f->id;
                    break;
                }
            }
        }

        return $resultado;
    }

    /**
     * Clave de exclusión de la PERSONA EN UN EVENTO (evento + HMAC del documento): dos certificados distintos del mismo evento y la misma persona
     * comparten clave, así que dos solicitudes simultáneas se serializan y la segunda ve la respuesta de la primera. Sin evento, cae al certificado.
     */
    public function claveEvento(int $certificado): string
    {
        $f = DB::table('cf_certificados_legado')->where('id', $certificado)->first(['documento_clave', 'evento_id']);
        if ($f === null || $f->evento_id === null) {
            return 'c:'.$certificado;
        }

        return 'e:'.$f->evento_id.':'.Hmac::de('documento', (string) $f->documento_clave);
    }

    public function posponer(Session $sesion, int $certificado): void
    {
        $lista = array_values(array_unique(array_merge($this->pospuestos($sesion), [$certificado])));
        $sesion->put(self::SESION_DESPUES, array_slice($lista, -self::MAX_POSPUESTOS));
    }

    /** @return list<int> */
    private function pospuestos(Session $sesion): array
    {
        $v = $sesion->get(self::SESION_DESPUES, []);

        return is_array($v) ? array_values(array_map('intval', array_filter($v, 'is_numeric'))) : [];
    }
}
