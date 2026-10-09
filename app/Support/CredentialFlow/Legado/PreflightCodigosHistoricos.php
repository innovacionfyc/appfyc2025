<?php

namespace App\Support\CredentialFlow\Legado;

use Illuminate\Support\Facades\DB;

/**
 * Preflight de los códigos históricos (Fase 10B-1.5). SOLO LECTURA. Debe pasar antes del cutover de la Fase 11: confirma que ningún código
 * del sistema viejo ha entrado en el rango reservado a Credential Flow, que no hay colisiones ni pares con dos códigos, que el contador es
 * coherente con lo asignado y que el rango alcanza para los pares que aún no tienen código. No asigna ni modifica nada.
 */
final class PreflightCodigosHistoricos
{
    /** @return array{ok:bool,comprobaciones:list<array{nombre:string,ok:bool,detalle:string}>} */
    public function verificar(): array
    {
        $c = [];
        $agregar = function (string $nombre, bool $ok, string $detalle) use (&$c): void {
            $c[] = ['nombre' => $nombre, 'ok' => $ok, 'detalle' => $detalle];
        };

        $contador = DB::table('cf_codigo_historico_contador')->where('id', 1)->first();
        $agregar('contador de una sola fila', $contador !== null && DB::table('cf_codigo_historico_contador')->count() === 1, $contador === null ? 'no existe' : 'existe');
        if ($contador === null) {
            return ['ok' => false, 'comprobaciones' => $c];
        }
        [$inicio, $fin, $siguiente] = [(int) $contador->inicio, (int) $contador->fin, (int) $contador->siguiente];
        $agregar('rango reservado válido (5 dígitos)', $inicio >= 1000 && $fin <= 99999 && $inicio <= $fin && $siguiente >= $inicio, "inicio {$inicio}, fin {$fin}, siguiente {$siguiente}");

        // El punto clave del cutover: ningún código legado dentro del rango reservado (se compara como número; el legado es 4 o 5 dígitos).
        $legados = DB::table('cf_certificados_legado')->whereNotNull('codigo_legado')->pluck('codigo_legado')->map(fn ($x) => (int) $x);
        $enRango = $legados->filter(fn ($n) => $n >= $inicio && $n <= $fin)->count();
        $agregar('ningún código legado dentro del rango reservado', $enRango === 0, "{$enRango} certificados con código legado en {$inicio}–{$fin} (máximo legado: ".($legados->max() ?? 0).')');

        $asignados = DB::table('cf_codigos_historicos')->pluck('codigo')->map(fn ($x) => (int) $x);
        $fuera = $asignados->filter(fn ($n) => $n < $inicio || $n > $fin)->count();
        $agregar('todos los códigos asignados están en el rango', $fuera === 0, "{$fuera} fuera de rango");

        $choques = DB::table('cf_codigos_historicos as h')->join('cf_certificados_legado as k', 'k.codigo_legado', '=', 'h.codigo')->count();
        $agregar('ningún código asignado coincide con uno legado', $choques === 0, "{$choques} coincidencias");

        $agregar('el contador está por encima de lo asignado', ($asignados->max() ?? 0) < $siguiente, 'máximo asignado '.($asignados->max() ?? 0).", siguiente {$siguiente}");

        $paresConVarios = DB::table('cf_certificados_legado')->whereNotNull('codigo_legado')->groupBy('evento_id', 'documento_clave')->havingRaw('COUNT(DISTINCT codigo_legado) > 1')->get(['evento_id'])->count();
        $agregar('ningún par con varios códigos legados', $paresConVarios === 0, "{$paresConVarios} pares");

        $paresDoble = DB::table('cf_codigos_historicos as h')->join('cf_certificados_legado as k', 'k.id', '=', 'h.certificado_canonico_id')
            ->join('cf_certificados_legado as o', fn ($j) => $j->on('o.evento_id', '=', 'k.evento_id')->on('o.documento_clave', '=', 'k.documento_clave'))->whereNotNull('o.codigo_legado')->distinct()->count('h.id');
        $agregar('ningún par con código legado y código asignado a la vez', $paresDoble === 0, "{$paresDoble} pares");

        $paresSinCodigo = DB::table('cf_certificados_legado')->select('evento_id', 'documento_clave')->groupBy('evento_id', 'documento_clave')->havingRaw('SUM(CASE WHEN codigo_legado IS NULL THEN 0 ELSE 1 END) = 0')->get()->count()
            - DB::table('cf_codigos_historicos')->count();
        $libres = max(0, $fin - $siguiente + 1);
        $agregar('el rango alcanza para los pares sin código', $libres >= max(0, $paresSinCodigo), "{$libres} números libres para ".max(0, $paresSinCodigo).' pares aún sin código');

        return ['ok' => collect($c)->every(fn ($x) => $x['ok']), 'comprobaciones' => $c];
    }
}
