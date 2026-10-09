<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Emision;
use Illuminate\Support\Collection;

/**
 * Resuelve la emisión moderna VIGENTE ACTUAL de un certificado histórico reemplazado: parte de `reemplazado_por_emision_id` y sigue la cadena de
 * reemisiones (`reemplaza_id`, versión creciente, mismo participante) hasta la última. El histórico NO se reapunta nunca: la cadena moderna decide.
 *
 * El resultado es EXPLÍCITO (nada se corta en silencio):
 *  - `estado`:  `vigente` (la última está emitida) · `revocada` (la última está revocada: no hay vigente) · `anomalia` (cadena inutilizable).
 *  - `anomalia`: null · `ciclo` · `profundidad` (más de MAX_PROFUNDIDAD eslabones) · `emision_faltante` (el punto de partida no existe) ·
 *    `cadena_corrupta` (una bifurcación, un eslabón de otro participante o una versión que no crece).
 *  Con anomalía NO hay `vigente`: ningún llamador debe entregar nada.
 *
 * `resolverLote` resuelve MUCHAS cadenas con una consulta por nivel de la cadena (nunca una por tarjeta): sirve al panel del portal.
 */
final class EmisionVigente
{
    public const MAX_PROFUNDIDAD = 50;

    public const ESTADO_VIGENTE = 'vigente';

    public const ESTADO_REVOCADA = 'revocada';

    public const ESTADO_ANOMALIA = 'anomalia';

    public const ANOMALIA_CICLO = 'ciclo';

    public const ANOMALIA_PROFUNDIDAD = 'profundidad';

    public const ANOMALIA_FALTANTE = 'emision_faltante';

    public const ANOMALIA_CORRUPTA = 'cadena_corrupta';

    /** Columnas mínimas de los eslabones (el PDF y el snapshot no se cargan para resolver una cadena). */
    private const COLUMNAS = ['id', 'codigo', 'estado', 'version', 'participante_id', 'reemplaza_id'];

    /**
     * @return array{ultima:?Emision,vigente:?Emision,cadena:list<int>,anomalia:?string,estado:string}
     */
    public static function desdeEmision(Emision|int $inicio): array
    {
        $id = $inicio instanceof Emision ? (int) $inicio->id : $inicio;

        return self::resolverLote([$id])[$id];
    }

    /**
     * Para un certificado histórico: null si no fue reemplazado.
     *
     * @return array{ultima:?Emision,vigente:?Emision,cadena:list<int>,anomalia:?string,estado:string}|null
     */
    public static function paraCertificado(CertificadoLegado|int $certificado): ?array
    {
        $emisionId = $certificado instanceof CertificadoLegado
            ? $certificado->reemplazado_por_emision_id
            : CertificadoLegado::query()->whereKey($certificado)->value('reemplazado_por_emision_id');
        // Una variante de un certificado lógico no lleva el enlace: resuelve por la fila de su grupo que SÍ lo lleva (10B-2B-2C.1).
        $emisionId ??= CertificadoLogico::reemplazada($certificado instanceof CertificadoLegado ? (int) $certificado->id : $certificado)?->reemplazado_por_emision_id;

        return $emisionId === null ? null : self::desdeEmision((int) $emisionId);
    }

    /**
     * Resuelve varias cadenas a la vez. Consultas: una por el conjunto inicial y UNA POR NIVEL de la cadena más larga (no una por emisión).
     *
     * @param  list<int>  $ids  emisiones de partida (las `reemplazado_por_emision_id` de los históricos)
     * @return array<int,array{ultima:?Emision,vigente:?Emision,cadena:list<int>,anomalia:?string,estado:string}> por id de partida
     */
    public static function resolverLote(array $ids): array
    {
        $ids = array_values(array_unique(array_map('intval', $ids)));
        if ($ids === []) {
            return [];
        }

        /** @var Collection<int,Emision> $nodos */
        $nodos = Emision::query()->whereIn('id', $ids)->get(self::COLUMNAS)->keyBy('id');
        $cursor = [];
        $resultado = [];
        foreach ($ids as $id) {
            if (! $nodos->has($id)) {
                $resultado[$id] = self::resultado(null, [], self::ANOMALIA_FALTANTE);

                continue;
            }
            $cursor[$id] = ['actual' => $id, 'cadena' => [$id], 'vistos' => [$id => true]];
        }

        for ($nivel = 0; $cursor !== [] && $nivel < self::MAX_PROFUNDIDAD; $nivel++) {
            $hijos = Emision::query()->whereIn('reemplaza_id', array_unique(array_column($cursor, 'actual')))->get(self::COLUMNAS)->groupBy('reemplaza_id');
            foreach ($cursor as $inicial => $c) {
                $siguientes = $hijos->get($c['actual']);
                if ($siguientes === null) {
                    $resultado[$inicial] = self::resultado($nodos->get($c['actual']), $c['cadena'], null);
                    unset($cursor[$inicial]);

                    continue;
                }
                $actual = $nodos->get($c['actual']);
                $hijo = $siguientes->first();
                if (isset($c['vistos'][(int) $hijo->id])) {
                    $resultado[$inicial] = self::resultado($actual, $c['cadena'], self::ANOMALIA_CICLO);
                    unset($cursor[$inicial]);
                } elseif ($siguientes->count() > 1 || (int) $hijo->participante_id !== (int) $actual->participante_id || (int) $hijo->version <= (int) $actual->version) {
                    $resultado[$inicial] = self::resultado($actual, $c['cadena'], self::ANOMALIA_CORRUPTA);
                    unset($cursor[$inicial]);
                } else {
                    $nodos->put((int) $hijo->id, $hijo);
                    $cursor[$inicial]['actual'] = (int) $hijo->id;
                    $cursor[$inicial]['cadena'][] = (int) $hijo->id;
                    $cursor[$inicial]['vistos'][(int) $hijo->id] = true;
                }
            }
        }
        // Quien sigue en el cursor agotó la profundidad máxima sin llegar a una última emisión.
        foreach ($cursor as $inicial => $c) {
            $resultado[$inicial] = self::resultado($nodos->get($c['actual']), $c['cadena'], self::ANOMALIA_PROFUNDIDAD);
        }

        return $resultado;
    }

    /** @param list<int> $cadena */
    private static function resultado(?Emision $ultima, array $cadena, ?string $anomalia): array
    {
        if ($anomalia !== null) {
            return ['ultima' => $ultima, 'vigente' => null, 'cadena' => $cadena, 'anomalia' => $anomalia, 'estado' => self::ESTADO_ANOMALIA];
        }
        $vigente = $ultima !== null && $ultima->vigente() ? $ultima : null;

        return ['ultima' => $ultima, 'vigente' => $vigente, 'cadena' => $cadena, 'anomalia' => null, 'estado' => $vigente === null ? self::ESTADO_REVOCADA : self::ESTADO_VIGENTE];
    }
}
