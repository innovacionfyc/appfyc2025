<?php

namespace App\Support\CredentialFlow\Encuestas;

use OpenSpout\Common\Entity\Cell;
use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Color;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\CSV\Options as CsvOptions;
use OpenSpout\Writer\CSV\Writer as CsvWriter;
use OpenSpout\Writer\XLSX\Options as XlsxOptions;
use OpenSpout\Writer\XLSX\Properties;
use OpenSpout\Writer\XLSX\Writer as XlsxWriter;

/**
 * Exportaciones de los informes de encuestas (XLSX y CSV) con los MISMOS filtros que la pantalla. Escribe en streaming (OpenSpout) sin cargar el resultado en
 * memoria. Solo agregados: nunca texto libre individual, documentos, correos, HMAC ni identificadores internos.
 *
 * Todo texto que llegue al archivo se neutraliza contra la inyección de fórmulas (CSV/Excel): si, ignorando espacios y caracteres de control iniciales, empieza por
 * `=`, `+`, `-`, `@`, tabulación o retorno de carro, se antepone una comilla simple. Los números se escriben como números, nunca como texto.
 */
final class ExportadorEncuestas
{
    public const CONJUNTOS_CSV = ['resumen', 'preguntas', 'distribucion'];

    public function __construct(private readonly InformeEncuestas $informe = new InformeEncuestas) {}

    /** Neutraliza un valor de texto para que ninguna hoja de cálculo lo interprete como fórmula. */
    public static function neutralizar(mixed $v): mixed
    {
        if (! is_string($v) || $v === '') {
            return $v;
        }
        $v = str_replace("\0", '', $v);
        // Falso positivo preferible: un `preg_match` fallido (UTF-8 inválido) también antepone la comilla.
        if (preg_match('/^[\s\x00-\x1F\x7F\x{00A0}\x{FEFF}]*[=+\-@]/u', $v) !== 0 || preg_match('/^[\t\r]/', $v) === 1) {
            return "'".$v;
        }

        return $v;
    }

    /** @throws ExportacionExcedida */
    public function validar(array $f): void
    {
        $filas = $this->informe->contarFilasDistribucion($f);
        if ($filas > InformeEncuestas::MAX_FILAS_EXPORTACION) {
            throw new ExportacionExcedida('La exportación supera el límite de '.number_format(InformeEncuestas::MAX_FILAS_EXPORTACION, 0, ',', '.').' filas ('.number_format($filas, 0, ',', '.').'). Reducir el alcance con los filtros.');
        }
    }

    public function xlsx(array $f, string $destino): void
    {
        $this->validar($f);
        $w = new XlsxWriter(new XlsxOptions(properties: new Properties(title: 'Informe de encuestas', application: 'F&C Credential Flow', creator: 'F&C Consultores', lastModifiedBy: 'F&C Consultores')));
        $w->openToFile($destino);
        $cab = (new Style)->withFontBold(true)->withFontColor(Color::WHITE)->withBackgroundColor('942934');
        $titulo = (new Style)->withFontBold(true)->withFontSize(14)->withFontColor('942934');
        $negrita = (new Style)->withFontBold(true);

        // Hoja 1: información del informe y filtros aplicados.
        $hoja = $w->getCurrentSheet();
        $hoja->setName('Información');
        $hoja->setColumnWidth(38, 1);
        $hoja->setColumnWidth(80, 2);
        foreach ($this->filasInformacion($f) as [$a, $b, $tipo]) {
            $w->addRow($this->fila([$a, $b], $tipo === 't' ? $titulo : ($tipo === 'h' ? $cab : ($tipo === 'n' ? $negrita : null))));
        }

        $this->hoja($w, 'Resumen por evento', [60, 8, 14, 14, 12, 14, 10, 14, 14, 20, 20],
            ['Evento', 'Año', 'Fecha de inicio', 'Fecha de fin', 'Respuestas', 'Participaciones únicas', 'Versiones', 'Respuestas históricas', 'Respuestas modernas', 'Primera respuesta', 'Última respuesta'],
            $this->informe->filasResumenEventos($f), $cab);
        $this->hoja($w, 'Resultados por pregunta', [10, 34, 8, 14, 70, 14, 14, 12, 12, 14],
            ['Versión', 'Título de la versión', 'Orden', 'Clave', 'Pregunta', 'Tipo', 'Respuestas de la versión', 'Respondidas', 'Omitidas', '% respondidas'],
            $this->informe->filasPorPregunta($f), $cab);
        $this->hoja($w, 'Distribución de opciones', [50, 8, 10, 8, 14, 60, 28, 12, 18, 14],
            ['Evento', 'Año', 'Versión', 'Orden', 'Clave', 'Pregunta', 'Opción', 'Respuestas', 'Base (respondidas)', 'Porcentaje (%)'],
            $this->informe->filasDistribucion($f), $cab);

        $w->close();
    }

    /** CSV (UTF-8 con BOM y `;` como separador, compatible con Excel en español). */
    public function csv(array $f, string $conjunto, string $destino): void
    {
        abort_unless(in_array($conjunto, self::CONJUNTOS_CSV, true), 404);
        $this->validar($f);
        $w = new CsvWriter(new CsvOptions(FIELD_DELIMITER: ';', SHOULD_ADD_BOM: true));
        $w->openToFile($destino);
        [$cabecera, $filas] = match ($conjunto) {
            'resumen' => [['Evento', 'Año', 'Fecha de inicio', 'Fecha de fin', 'Respuestas', 'Participaciones únicas', 'Versiones', 'Respuestas históricas', 'Respuestas modernas', 'Primera respuesta', 'Última respuesta'], $this->informe->filasResumenEventos($f)],
            'preguntas' => [['Versión', 'Título de la versión', 'Orden', 'Clave', 'Pregunta', 'Tipo', 'Respuestas de la versión', 'Respondidas', 'Omitidas', '% respondidas'], $this->informe->filasPorPregunta($f)],
            'distribucion' => [['Evento', 'Año', 'Versión', 'Orden', 'Clave', 'Pregunta', 'Opción', 'Respuestas', 'Base (respondidas)', 'Porcentaje (%)'], $this->informe->filasDistribucion($f)],
        };
        $w->addRow($this->fila($cabecera));
        foreach ($filas as $fila) {
            $w->addRow($this->fila($fila));
        }
        $w->close();
    }

    /** @param  array<int,mixed>  $filas */
    private function hoja(XlsxWriter $w, string $nombre, array $anchos, array $cabecera, iterable $filas, Style $cab): void
    {
        $hoja = $w->addNewSheetAndMakeItCurrent();
        $hoja->setName($nombre);
        foreach ($anchos as $i => $ancho) {
            $hoja->setColumnWidth($ancho, $i + 1);
        }
        $w->addRow($this->fila($cabecera, $cab));
        foreach ($filas as $fila) {
            $w->addRow($this->fila($fila));
        }
    }

    /** @param  array<int,mixed>  $valores */
    private function fila(array $valores, ?Style $estilo = null): Row
    {
        return new Row(array_map(fn ($v) => Cell::fromValue(self::neutralizar($v), $estilo), array_values($valores)));
    }

    /** @return list<array{0:string,1:mixed,2:string}> t=título, h=encabezado, n=negrita, x=normal */
    private function filasInformacion(array $f): array
    {
        $r = $this->informe->resumen($f);
        $evento = match (true) {
            $f['evento'] === InformeEncuestas::SIN_EVENTO => 'Eventos no disponibles',
            $f['evento'] !== null => ($this->informe->evento($f['evento'])['nombre'] ?? 'Evento '.$f['evento']),
            $f['q'] !== null => 'Nombre que contiene «'.$f['q'].'»',
            default => 'Todos',
        };
        $version = 'Todas';
        if ($f['version'] !== null) {
            $v = collect($this->informe->opciones()['versiones'])->firstWhere('id', $f['version']);
            $version = $v ? 'Versión '.$v['numero'].' · '.$v['titulo'] : 'Versión '.$f['version'];
        }
        $origen = ['legado' => 'Históricas (sistema anterior)', 'credential_flow' => 'Modernas (portal)'][$f['origen'] ?? ''] ?? 'Todos';
        $rango = fn ($d, $h) => ($d === null && $h === null) ? 'Sin restricción' : ($d ?? '…').' a '.($h ?? '…');

        return [
            ['Informe de encuestas · F&C Consultores', '', 't'],
            ['Generado el', now()->format('Y-m-d H:i'), 'x'],
            ['', '', 'x'],
            ['Filtros aplicados', '', 'h'],
            ['Evento', $evento, 'x'],
            ['Año del evento', $f['anio'] ?? 'Todos', 'x'],
            ['Fecha de inicio del evento', $rango($f['evento_desde'], $f['evento_hasta']), 'x'],
            ['Fecha de respuesta', $rango($f['resp_desde'], $f['resp_hasta']), 'x'],
            ['Versión de la encuesta', $version, 'x'],
            ['Origen', $origen, 'x'],
            ['', '', 'x'],
            ['Totales', '', 'h'],
            ['Respuestas (registros)', $r['respuestas'], 'x'],
            ['Participaciones únicas (persona + evento)', $r['participaciones_unicas'], 'x'],
            ['Eventos evaluados', $r['eventos_evaluados'], 'x'],
            ['Respuestas de eventos no disponibles', $r['sin_evento'], 'x'],
            ['Respuestas históricas', $r['por_origen']['legado'] ?? 0, 'x'],
            ['Respuestas modernas', $r['por_origen']['credential_flow'] ?? 0, 'x'],
            ['Periodo de respuestas', ($r['desde'] ? substr((string) $r['desde'], 0, 10) : '—').' a '.($r['hasta'] ? substr((string) $r['hasta'], 0, 10) : '—'), 'x'],
            ['', '', 'x'],
            ['Notas', '', 'h'],
            ['Respuestas y participaciones', 'Las respuestas históricas duplicadas se conservan; las participaciones únicas cuentan una persona una sola vez por evento.', 'x'],
            ['Porcentajes', 'Se calculan sobre las respuestas con contenido de cada pregunta (columna «Base»).', 'x'],
            ['Participación', 'No disponible: no existe un denominador verificable de personas elegibles para todos los eventos.', 'x'],
            ['Texto libre', 'No se incluye en esta exportación. Se consulta únicamente en la vista restringida.', 'x'],
            ['Versiones', 'Las versiones con estructuras distintas no se fusionan: cada una conserva sus propias preguntas.', 'x'],
            ['Fecha del evento', 'Los filtros por fecha de evento usan la fecha de inicio registrada del evento.', 'x'],
        ];
    }
}
