<?php

namespace App\Support\CredentialFlow\Eventos;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Support\CredentialFlow\Legado\ElegibilidadLegado;
use Generator;

/**
 * Qué certificados HISTÓRICOS de un evento se pueden descargar en un ZIP, con las reglas de elegibilidad que ya rigen el histórico
 * (`ElegibilidadLegado`): vigentes, conciliados, con plantilla utilizable, sin revocar ni reemplazar. SOLO LECTURA: no genera, congela, ni asigna
 * códigos. Solo entran los que YA tienen su PDF congelado (se generan al abrirlos individualmente). Los duplicados idénticos consolidados no suman: su
 * PDF vive en el canónico.
 */
final class DescargaHistorica
{
    private const COLUMNAS = ['id', 'evento_id', 'nombre_completo', 'documento', 'estado', 'conciliacion_estado', 'plantilla_legado_id', 'snapshot_legado', 'reemplazado_por_emision_id', 'grupo_duplicado', 'pdf_archivo', 'pdf_hash', 'pdf_bytes'];

    /**
     * @return array{elegibles:int,disponibles:int,sin_pdf:int,duplicados:int,excluidos:array<string,int>}
     */
    public function resumen(EventoCertificacion $evento): array
    {
        $r = ['elegibles' => 0, 'disponibles' => 0, 'sin_pdf' => 0, 'duplicados' => 0, 'excluidos' => []];
        foreach ($this->certificados($evento) as $c) {
            if ($c->conciliacion_estado === CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO) {
                $r['duplicados']++;

                continue;
            }
            $motivo = ElegibilidadLegado::motivo($c);
            if ($motivo !== null) {
                $r['excluidos'][$motivo] = ($r['excluidos'][$motivo] ?? 0) + 1;

                continue;
            }
            $r['elegibles']++;
            $c->pdf_archivo !== null ? $r['disponibles']++ : $r['sin_pdf']++;
        }
        ksort($r['excluidos']);

        return $r;
    }

    /** Certificados elegibles con PDF ya congelado, por nombre (los datos del PDF no se tocan). @return Generator<int,CertificadoLegado> */
    public function disponibles(EventoCertificacion $evento): Generator
    {
        $filas = [];
        foreach ($this->certificados($evento) as $c) {
            if ($c->conciliacion_estado !== CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO && $c->pdf_archivo !== null && ElegibilidadLegado::motivo($c) === null) {
                $filas[] = [mb_strtoupper((string) $c->nombre_completo), $c->id, $c];
            }
        }
        usort($filas, fn ($a, $b) => [$a[0], $a[1]] <=> [$b[0], $b[1]]);
        foreach ($filas as [, , $c]) {
            yield $c;
        }
    }

    /** Todos los certificados del evento, por bloques (poca memoria). @return Generator<int,CertificadoLegado> */
    private function certificados(EventoCertificacion $evento): Generator
    {
        yield from CertificadoLegado::query()->where('evento_id', $evento->id)->select(self::COLUMNAS)->orderBy('id')->lazyById(200);
    }
}
