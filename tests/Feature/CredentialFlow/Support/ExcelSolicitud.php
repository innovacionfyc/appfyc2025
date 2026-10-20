<?php

namespace Tests\Feature\CredentialFlow\Support;

/**
 * Réplica SINTÉTICA (datos ficticios) del formato F-SC-DC-08 exportado de Google Sheets: bloque del evento arriba, tabla desde la fila 14 y fórmulas
 * `IFERROR(__xludf.DUMMYFUNCTION(…), valor)`. No contiene ningún dato real.
 */
final class ExcelSolicitud
{
    public const EVENTO_631 = 'Módulo de actualización en violencias basadas en género para funcionarios públicos — Ley 2605 de 2026';

    /** Segunda línea del nombre en el Excel: agrega el texto «(Ley Jineth Bedoya Lima)» que el evento registrado no tiene. */
    public const LINEA_EXCEL = 'Ley 2605 de 2026 (Ley Jineth Bedoya Lima)';

    public const MODALIDAD = 'Virtual-Sincrónico y Remoto';

    private static function reserva(string $literal): string
    {
        return 'IFERROR(__xludf.DUMMYFUNCTION("IMPORTRANGE(D10,""Hoja!A1"")"),'.$literal.')';
    }

    private static function texto(string $t): array
    {
        return ['f' => self::reserva('"'.str_replace('"', '""', $t).'"'), 't' => 'str', 'v' => $t];
    }

    private static function soloReserva(string $t): array
    {
        return ['f' => self::reserva('"'.str_replace('"', '""', $t).'"')];
    }

    /**
     * @param  array{documento_base?: int, documentos?: array<int,string>, correos?: array<int,string>, linea_evento?: string, sin_importrange?: bool}  $opc
     */
    public static function bytes(int $personas = 17, array $opc = []): string
    {
        $c = [
            'B7' => 'Nombre del Evento', 'D7' => self::texto("Módulo de actualización en violencias basadas en género para funcionarios públicos\n".($opc['linea_evento'] ?? self::LINEA_EXCEL)),
            'B8' => 'Gestora Comercial Encargada', 'D8' => self::texto('Gestora Ficticia'),
            'F8' => 'Modalidad', 'G8' => self::texto(self::MODALIDAD),
            'B9' => 'Fecha del Evento', 'D9' => ['f' => self::reserva('46311'), 's' => 1, 'v' => '46311'],
            'F9' => 'Intensidad de horas', 'G9' => self::texto('Módulo 5 Horas'),
            'B10' => 'URL hoja de Calculo Formato Base Inscritos', 'D10' => 'abc123',
        ];
        foreach (['B' => 'Item', 'C' => 'Identificaciòn', 'D' => 'Nombres y Apellidos', 'E' => 'Correo Electronico', 'F' => 'Diploma', 'G' => 'Sesión', 'H' => 'Certificado con intensidad completa', 'I' => 'Selección de Sesiones o Cupo a Certificar ', 'J' => 'Observaciones o comentarios adicionales'] as $col => $t) {
            $c[$col.'14'] = $t;
        }
        $base = $opc['documento_base'] ?? 1000000;
        for ($i = 1; $i <= $personas; $i++) {
            $f = 14 + $i;
            $cupo = $i === $personas - 1 && $personas >= 2;
            $c['B'.$f] = $i;
            $doc = $opc['documentos'][$i] ?? null;
            $c['C'.$f] = $doc !== null ? $doc : ['f' => self::reserva((string) ($base + $i)), 't' => 'n', 'v' => (string) ($base + $i)];
            $c['D'.$f] = self::soloReserva(sprintf('PERSONA %02d PRUEBA', $i));
            $c['E'.$f] = self::texto($opc['correos'][$i] ?? sprintf('persona%02d@example.test', $i));
            $c['F'.$f] = $i % 2 === 0;
            $c['G'.$f] = self::texto($cupo ? 'Cupos' : 'Completo');
            if ($cupo) {
                $c['I'.$f] = self::texto('X');
            } else {
                $c['H'.$f] = self::texto('X');
            }
        }
        for ($i = $personas + 1; $i <= $personas + 4; $i++) {      // filas numeradas de relleno, como las deja la plantilla
            $c['B'.(14 + $i)] = $i;
            $c['D'.(14 + $i)] = ['f' => self::reserva('""'), 't' => 'str', 'v' => ''];
        }

        return XlsxSintetico::libro([['nombre' => 'Solicitud', 'celdas' => $c]]);
    }
}
