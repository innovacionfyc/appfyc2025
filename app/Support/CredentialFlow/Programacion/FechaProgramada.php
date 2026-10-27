<?php

namespace App\Support\CredentialFlow\Programacion;

use Carbon\CarbonImmutable;
use DateTimeZone;

/**
 * Interpreta la fecha y hora de una programación de forma ESTRICTA y siempre en la zona de Colombia (`credential_flow.programacion.zona`).
 *
 * Solo se acepta «AAAA-MM-DD HH:MM» o «AAAA-MM-DDTHH:MM» (lo que envía un `<input type="datetime-local">`), con «:00» opcional como segundos. Se rechaza todo lo
 * que podría leerse de dos maneras: otros órdenes de día y mes, horas de 12 h, zonas u offsets explícitos («Z», «-05:00»), textos y fechas que no existen en el
 * calendario («2026-02-30», «24:00»). Colombia no tiene horario de verano, así que en su zona no hay horas repetidas ni inexistentes.
 */
final class FechaProgramada
{
    private const PATRON = '/^(\d{4})-(\d{2})-(\d{2})[ T](\d{2}):(\d{2})(?::00)?$/';

    public static function zona(): string
    {
        return (string) config('credential_flow.programacion.zona', 'America/Bogota');
    }

    /** @throws ProgramacionException */
    public static function interpretar(mixed $valor): CarbonImmutable
    {
        self::exigirZonaDeLaAplicacion();
        $texto = is_string($valor) ? trim($valor) : '';
        if (preg_match(self::PATRON, $texto, $m) !== 1) {
            throw new ProgramacionException(ProgramacionException::FECHA_INVALIDA, 'Indica la fecha y la hora exactas de Colombia con el formato AAAA-MM-DD HH:MM (24 horas).', 422);
        }
        [, $a, $mes, $d, $h, $min] = array_map('intval', $m);
        if (! checkdate($mes, $d, $a) || $h > 23 || $min > 59) {
            throw new ProgramacionException(ProgramacionException::FECHA_INVALIDA, 'Esa fecha u hora no existe en el calendario. Revísala.', 422);
        }

        return CarbonImmutable::create($a, $mes, $d, $h, $min, 0, new DateTimeZone(self::zona()));
    }

    /**
     * La hora debe estar entre `minimo_minutos` desde ahora y `maximo_dias` en el futuro.
     *
     * @throws ProgramacionException
     */
    public static function exigirRango(CarbonImmutable $fecha): void
    {
        $ahora = CarbonImmutable::now(self::zona());
        if ($fecha->lessThanOrEqualTo($ahora)) {
            throw new ProgramacionException(ProgramacionException::FECHA_PASADA, 'Esa fecha y hora ya pasaron en Colombia. Elige una hora futura.', 422);
        }
        $minimo = (int) config('credential_flow.programacion.minimo_minutos', 5);
        if ($fecha->lessThan($ahora->addMinutes($minimo))) {
            throw new ProgramacionException(ProgramacionException::FECHA_MUY_PRONTO, "Programa con al menos {$minimo} minutos de anticipación (o usa «Emitir ahora»).", 422);
        }
        $maximo = (int) config('credential_flow.programacion.maximo_dias', 90);
        if ($fecha->greaterThan($ahora->addDays($maximo))) {
            throw new ProgramacionException(ProgramacionException::FECHA_MUY_LEJANA, "Solo se puede programar hasta {$maximo} días adelante.", 422);
        }
    }

    /** Lo que se guarda: la hora de Colombia en la zona de la aplicación (que debe ser la misma). */
    public static function paraGuardar(CarbonImmutable $fecha): string
    {
        return $fecha->setTimezone(self::zona())->format('Y-m-d H:i:s');
    }

    /**
     * Las fechas de la aplicación (DATETIME sin zona) se escriben en `app.timezone`: si no es la de Colombia, una hora programada se leería con otra hora.
     *
     * @throws ProgramacionException
     */
    private static function exigirZonaDeLaAplicacion(): void
    {
        if ((string) config('app.timezone') !== self::zona()) {
            throw new ProgramacionException(ProgramacionException::ZONA_NO_CONFIGURADA, 'La aplicación no está configurada con la hora de Colombia: no se puede programar.', 500);
        }
    }
}
