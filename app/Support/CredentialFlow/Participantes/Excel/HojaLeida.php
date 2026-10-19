<?php

namespace App\Support\CredentialFlow\Participantes\Excel;

/** Una hoja del libro: solo guarda las celdas que existen (dispersa) y sabe resumirse sin datos personales. */
final class HojaLeida
{
    /** @var array<int, array<int, CeldaLeida>> fila => (columna => celda) */
    private array $celdas = [];

    public int $filasLeidas = 0;

    public int $columnasRecortadas = 0;

    public bool $truncada = false;

    public ?string $motivoTruncada = null;

    public function __construct(public readonly string $nombre, public readonly bool $visible) {}

    public function poner(CeldaLeida $celda): void
    {
        $this->celdas[$celda->fila][$celda->columna] = $celda;
    }

    public function celda(int $fila, int $columna): ?CeldaLeida
    {
        return $this->celdas[$fila][$columna] ?? null;
    }

    /** @return array<int, CeldaLeida> las celdas de una fila, por columna */
    public function fila(int $fila): array
    {
        return $this->celdas[$fila] ?? [];
    }

    /** @return list<int> números de fila que tienen alguna celda */
    public function filas(): array
    {
        $filas = array_keys($this->celdas);
        sort($filas);

        return $filas;
    }

    /** @return iterable<CeldaLeida> */
    public function todas(): iterable
    {
        foreach ($this->celdas as $fila) {
            yield from $fila;
        }
    }

    public function cantidad(): int
    {
        $n = 0;
        foreach ($this->celdas as $fila) {
            $n += count($fila);
        }

        return $n;
    }

    /** ¿La fila tiene al menos un dato utilizable? (las fórmulas sin resultado no cuentan) */
    public function filaConDatos(int $fila): bool
    {
        foreach ($this->celdas[$fila] ?? [] as $c) {
            if ($c->tieneDato()) {
                return true;
            }
        }

        return false;
    }

    /** @param  callable(CeldaLeida): CeldaLeida  $f  reemplaza celdas de una fila (usa el mismo orden de columnas) */
    public function reemplazarFila(int $fila, callable $f): void
    {
        foreach ($this->celdas[$fila] ?? [] as $col => $c) {
            $this->celdas[$fila][$col] = $f($c);
        }
    }

    /**
     * Conteos por origen y tipo. Sin ningún valor de celda (nada de datos personales).
     *
     * @return array<string,int|bool|string|null>
     */
    public function resumen(): array
    {
        $r = [
            'celdas' => 0, 'literales' => 0, 'formulas' => 0,
            CeldaLeida::FORMULA_GUARDADA => 0, CeldaLeida::FORMULA_RESERVA => 0, CeldaLeida::FORMULA_VACIA => 0, CeldaLeida::FORMULA_VACIA_CONTEXTO => 0,
            CeldaLeida::FORMULA_ERROR => 0, CeldaLeida::FORMULA_SIN_VALOR => 0, 'importrange' => 0, 'discrepancias' => 0, 'errores_literales' => 0,
        ];
        foreach ($this->todas() as $c) {
            $r['celdas']++;
            if ($c->origen === CeldaLeida::LITERAL) {
                $r['literales']++;
                if ($c->tipo === CeldaLeida::ERROR) {
                    $r['errores_literales']++;
                }

                continue;
            }
            $r['formulas']++;
            $r[$c->origen]++;
            $r['importrange'] += $c->importrange ? 1 : 0;
            $r['discrepancias'] += $c->discrepancia ? 1 : 0;
        }

        return ['hoja_visible' => $this->visible, 'filas_leidas' => $this->filasLeidas, 'columnas_recortadas' => $this->columnasRecortadas, 'truncada' => $this->truncada, 'motivo_truncada' => $this->motivoTruncada] + $r;
    }
}
