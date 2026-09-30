<?php

namespace App\Support\CredentialFlow\Participantes;

use OpenSpout\Common\Entity\Row;
use OpenSpout\Common\Entity\Style\Style;
use OpenSpout\Writer\XLSX\Options;
use OpenSpout\Writer\XLSX\Writer;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

/**
 * Plantilla .xlsx descargable de participantes: hoja «Participantes» con las columnas exactas y SIN filas
 * de ejemplo (importarían), y hoja «Instrucciones». OpenSpout no permite dar formato de texto a una columna
 * entera sin escribir filas; por eso las instrucciones piden formatear la columna «documento» como Texto.
 */
final class PlantillaExcelParticipantes
{
    public const NOMBRE_ARCHIVO = 'plantilla-participantes.xlsx';

    public static function respuesta(): BinaryFileResponse
    {
        $ruta = tempnam(sys_get_temp_dir(), 'cfp_');

        $opciones = new Options;
        $opciones->setColumnWidth(34, 1);
        $opciones->setColumnWidth(30, 2);
        $writer = new Writer($opciones);
        $writer->openToFile($ruta);

        $writer->getCurrentSheet()->setName('Participantes');
        $texto = (new Style)->withFontBold(true)->withFormat('@');
        $writer->addRow(Row::fromValuesWithStyles(['nombre_completo', 'documento'], [0 => $texto, 1 => $texto]));

        $writer->addNewSheetAndMakeItCurrent()->setName('Instrucciones');
        $negrita = (new Style)->withFontBold(true);
        foreach (self::instrucciones() as [$linea, $enNegrita]) {
            $writer->addRow(Row::fromValuesWithStyles([$linea], [0 => $enNegrita ? $negrita : new Style], 15));
        }

        $writer->close();

        return response()->download($ruta, self::NOMBRE_ARCHIVO, [
            'Content-Type' => 'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ])->deleteFileAfterSend(true);
    }

    /** @return array<int,array{0:string,1:bool}> */
    private static function instrucciones(): array
    {
        return [
            ['Cómo llenar el archivo de participantes', true],
            ['', false],
            ['1. Escribe los participantes en la hoja «Participantes», una persona por fila, debajo del encabezado.', false],
            ['2. Solo hay dos columnas: nombre_completo y documento. El orden no importa.', false],
            ['3. El evento, la fecha y la intensidad horaria NO van en el archivo: se escriben una sola vez al crear el lote.', false],
            ['4. El documento se escribe tal como debe imprimirse. Ejemplos: C.C. 1.023.456.789 · PASAPORTE AB1234567 · NIT 900.123.456-7', false],
            ['5. Antes de pegar los documentos, formatea la columna «documento» como Texto (clic derecho > Formato de celdas > Texto).', false],
            ['   Así Excel no convierte los documentos en números, no borra los ceros iniciales ni usa notación científica.', false],
            ['6. Los nombres se guardan en MAYÚSCULAS. Se admiten tildes, ñ y otros caracteres del alfabeto latino.', false],
            ['7. No repitas un documento dentro del mismo archivo. Las celdas no pueden empezar por = + - o @ (parecen fórmulas).', false],
            ['8. Máximo 1000 participantes y 2 MB por archivo. Formatos admitidos: .xlsx y .csv.', false],
        ];
    }
}
