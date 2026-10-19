<?php

namespace App\Support\CredentialFlow\Participantes\Excel\Analisis;

/**
 * Resultado del análisis de un archivo Excel: la tabla de participantes elegida, los datos comunes del evento propuestos y las advertencias.
 * Es solo una PROPUESTA en memoria: no escribe en la base de datos ni decide elegibilidad.
 */
final readonly class AnalisisExcel
{
    /**
     * @param  array<string, ValorDetectado>  $evento  evento, fecha, modalidad, intensidad_horaria, ciudad, lugar, gestora_comercial, entidad
     * @param  list<array{hoja: string, fila_encabezado: int, participantes: int, columnas: int}>  $tablasAlternativas  otras tablas parecidas (no elegidas)
     * @param  list<array{nombre: string, visible: bool, rol: string, celdas: int}>  $hojas
     * @param  list<array{codigo: string, cantidad: int}>  $advertencias
     * @param  array<string,int|float|bool>  $metricas
     */
    public function __construct(
        public ?TablaDetectada $tabla,
        public array $evento,
        public array $tablasAlternativas,
        public array $hojas,
        public array $advertencias,
        public array $metricas = [],
    ) {}

    /** @return list<ParticipanteDetectado> */
    public function participantes(): array
    {
        return $this->tabla?->participantes ?? [];
    }

    public function advertencia(string $codigo): int
    {
        foreach ($this->advertencias as $a) {
            if ($a['codigo'] === $codigo) {
                return $a['cantidad'];
            }
        }

        return 0;
    }

    /**
     * Resumen para registros y reportes: SOLO conteos y códigos fijos. Nunca nombres, documentos, correos, textos del archivo ni nombres de hoja.
     *
     * @return array<string, mixed>
     */
    public function resumenSeguro(): array
    {
        $porSenal = [];
        $porCampo = [];
        $qa = 0;
        $desactualizables = 0;
        foreach ($this->participantes() as $p) {
            $porSenal[$p->senal] = ($porSenal[$p->senal] ?? 0) + 1;
            if ($p->posibleQa !== null) {
                $qa++;
            }
            foreach ($p->campos as $campo => $v) {
                $porCampo[$campo][$v->confianza] = ($porCampo[$campo][$v->confianza] ?? 0) + 1;
                if ($v->desactualizable) {
                    $desactualizables++;
                }
            }
        }
        ksort($porCampo);
        ksort($porSenal);
        $evento = [];
        foreach ($this->evento as $campo => $v) {
            $evento[$campo] = $v->confianza;
            if ($v->desactualizable) {
                $desactualizables++;
            }
        }

        return [
            'tabla_encontrada' => $this->tabla !== null,
            'tabla_confianza' => $this->tabla?->confianza,
            'fila_encabezado' => $this->tabla?->filaEncabezado,
            'participantes' => count($this->participantes()),
            'filas_omitidas' => $this->tabla?->filasOmitidas ?? 0,
            'tabla_truncada' => $this->tabla?->truncada ?? false,
            'columnas_reconocidas' => $this->tabla === null ? [] : array_map(fn (ColumnaDetectada $c) => $c->confianza, $this->tabla->columnas),
            'senales' => $porSenal,
            'campos_por_confianza' => $porCampo,
            'evento_por_confianza' => $evento,
            'posibles_qa' => $qa,
            'valores_desactualizables' => $desactualizables,
            'tablas_alternativas' => count($this->tablasAlternativas),
            'hojas' => count($this->hojas),
            'advertencias' => $this->advertencias,
            'metricas' => $this->metricas,
        ];
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'tabla' => $this->tabla?->toArray(),
            'evento' => array_map(fn (ValorDetectado $v) => $v->toArray(), $this->evento),
            'tablas_alternativas' => $this->tablasAlternativas,
            'hojas' => $this->hojas,
            'advertencias' => $this->advertencias,
            'metricas' => $this->metricas,
        ];
    }
}
