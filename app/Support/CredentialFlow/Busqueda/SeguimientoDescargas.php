<?php

namespace App\Support\CredentialFlow\Busqueda;

use App\Models\Movimiento;
use Illuminate\Support\Facades\DB;

/**
 * Seguimiento de descargas de cada certificado del buscador, SOLO con los registros que realmente existen (nada se infiere ni se completa):
 *  - portal: filas de cf_descargas con vía «portal» (incluye las importadas del sistema anterior, contadas aparte en `sistema_anterior`);
 *  - correo: filas de cf_descargas con vía «correo»;
 *  - panel: descargas ADMINISTRATIVAS desde este buscador, auditadas en Movimientos (antes del buscador las descargas del panel no se registraban).
 * Solo lee. Por registro: `seguimiento` = {portal:{total, ultima, sistema_anterior}, correo:{total, ultima}, panel:{total, ultima}}.
 */
final class SeguimientoDescargas
{
    public const DESCRIPCION_PANEL = 'Descarga administrativa de un certificado desde el buscador por documento';

    /**
     * @param  array<string,mixed>  $r  resultado válido de ConsultaPorDocumento::consultar
     * @return array<string,mixed>
     */
    public function agregar(array $r): array
    {
        $todos = collect($r['identidades'])->flatMap(fn ($i) => $i['certificados'])->merge($r['excluidos']);
        $ids = ['moderno' => $todos->where('origen', 'moderno')->pluck('id')->all(), 'historico' => $todos->where('origen', 'historico')->pluck('id')->all()];

        $vias = [];
        foreach (['moderno' => 'emision_id', 'historico' => 'certificado_legado_id'] as $origen => $col) {
            if ($ids[$origen] === []) {
                continue;
            }
            $filas = DB::table('cf_descargas')->whereIn($col, $ids[$origen])->whereIn('via', ['portal', 'correo'])
                ->select($col.' as rid', 'via', 'origen', DB::raw('COUNT(*) as n'), DB::raw('MAX(descargado_at) as ultima'))->groupBy($col, 'via', 'origen')->get();
            foreach ($filas as $f) {
                $k = $origen.':'.$f->rid;
                $v = $vias[$k][$f->via] ?? ['total' => 0, 'ultima' => null, 'sistema_anterior' => 0];
                $v['total'] += (int) $f->n;
                $v['ultima'] = max((string) $v['ultima'], (string) $f->ultima) ?: null;
                if ($f->origen === 'legado_importado') {
                    $v['sistema_anterior'] += (int) $f->n;
                }
                $vias[$k][$f->via] = $v;
            }
        }

        $claves = $todos->map(fn ($c) => $c['origen'].':'.$c['id'])->values()->all();
        $panel = [];
        if ($claves !== []) {
            $movs = Movimiento::query()->where('tipo', 'descarga')->where('modulo', 'credential-flow')->where('descripcion', self::DESCRIPCION_PANEL)
                ->whereIn('metadata->registro', $claves)->where('metadata->resultado', 'ok')->get(['metadata', 'created_at']);
            foreach ($movs as $m) {
                $k = (string) ($m->metadata['registro'] ?? '');
                $p = $panel[$k] ?? ['total' => 0, 'ultima' => null];
                $p['total']++;
                $p['ultima'] = max((string) $p['ultima'], $m->created_at->toDateTimeString());
                $panel[$k] = $p;
            }
        }

        $poner = function (array $c) use ($vias, $panel): array {
            $k = $c['origen'].':'.$c['id'];
            $portal = $vias[$k]['portal'] ?? ['total' => 0, 'ultima' => null, 'sistema_anterior' => 0];
            $correo = $vias[$k]['correo'] ?? ['total' => 0, 'ultima' => null];
            $c['seguimiento'] = [
                'portal' => ['total' => $portal['total'], 'ultima' => $portal['ultima'], 'sistema_anterior' => $portal['sistema_anterior']],
                'correo' => ['total' => $correo['total'], 'ultima' => $correo['ultima']],
                'panel' => $panel[$k] ?? ['total' => 0, 'ultima' => null],
            ];

            return $c;
        };
        $r['identidades'] = array_map(fn (array $i) => ['certificados' => array_map($poner, $i['certificados'])] + $i, $r['identidades']);
        $r['excluidos'] = array_map($poner, $r['excluidos']);

        return $r;
    }
}
