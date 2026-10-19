<?php

namespace App\Support\CredentialFlow\Participantes\Excel;

/** Resultado de leer un libro: sus hojas con los valores resueltos y el diagnóstico de las fórmulas. */
final class LibroLeido
{
    /** @param list<HojaLeida> $hojas */
    public function __construct(
        public readonly array $hojas,
        public readonly bool $erroresVerificados,
        public readonly int $memoriaPicoBytes,
        public readonly float $segundos,
    ) {}

    public function hoja(string $nombre): ?HojaLeida
    {
        foreach ($this->hojas as $h) {
            if ($h->nombre === $nombre) {
                return $h;
            }
        }

        return null;
    }

    public function truncado(): bool
    {
        foreach ($this->hojas as $h) {
            if ($h->truncada) {
                return true;
            }
        }

        return false;
    }

    /**
     * Diagnóstico agregado (solo números): cuántas fórmulas hay, cuántas se recuperaron y cuántas no. Seguro para registros e informes.
     *
     * @return array<string,mixed>
     */
    public function resumen(): array
    {
        $total = [
            'hojas' => count($this->hojas), 'celdas' => 0, 'literales' => 0, 'formulas' => 0,
            'recuperadas_guardadas' => 0, 'recuperadas_reserva' => 0, 'vacias_resultado_vacio' => 0, 'vacias_por_contexto' => 0, 'errores' => 0, 'sin_valor' => 0,
            'importrange' => 0, 'discrepancias' => 0, 'errores_literales' => 0,
        ];
        foreach ($this->hojas as $h) {
            $r = $h->resumen();
            $total['celdas'] += $r['celdas'];
            $total['literales'] += $r['literales'];
            $total['formulas'] += $r['formulas'];
            $total['recuperadas_guardadas'] += $r[CeldaLeida::FORMULA_GUARDADA];
            $total['recuperadas_reserva'] += $r[CeldaLeida::FORMULA_RESERVA];
            $total['vacias_resultado_vacio'] += $r[CeldaLeida::FORMULA_VACIA];
            $total['vacias_por_contexto'] += $r[CeldaLeida::FORMULA_VACIA_CONTEXTO];
            $total['errores'] += $r[CeldaLeida::FORMULA_ERROR];
            $total['sin_valor'] += $r[CeldaLeida::FORMULA_SIN_VALOR];
            $total['importrange'] += $r['importrange'];
            $total['discrepancias'] += $r['discrepancias'];
            $total['errores_literales'] += $r['errores_literales'];
        }
        $total['recuperadas'] = $total['recuperadas_guardadas'] + $total['recuperadas_reserva'];
        $total['vacias_comprobadas'] = $total['vacias_resultado_vacio'] + $total['vacias_por_contexto'];
        $total['truncado'] = $this->truncado();
        $total['errores_verificados'] = $this->erroresVerificados;
        $total['memoria_pico_mb'] = round($this->memoriaPicoBytes / 1048576, 1);
        $total['segundos'] = round($this->segundos, 2);

        return $total;
    }
}
