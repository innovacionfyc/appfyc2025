<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Support\CredentialFlow\Portal\NombreConservador;

/**
 * Excepción controlada a «un documento por base»: en un evento AUTORIZADO de forma expresa (`credential_flow.doble_modalidad.eventos`), una misma persona puede
 * tener una participación por modalidad (p. ej. una presencial y otra virtual), cada una con su certificado. Es la ÚNICA regla que usan el importador y el
 * formulario de participantes, para que nunca difieran.
 *
 *  - Fuera de los eventos autorizados (y en bases sin evento) no se usa: el duplicado sigue siendo el mismo documento, como siempre.
 *  - Dos participaciones del mismo documento conviven solo si sus modalidades EFECTIVAS (la propia o, si no tiene, la general de la base) son distintas tras
 *    normalizar mayúsculas y espacios, ninguna está vacía y los nombres son del mismo grupo de identidad (NombreConservador, la regla del portal): un número
 *    repetido con otro nombre nunca pasa por esta vía.
 *  - No cambia el criterio entre bases del mismo evento (DuplicadosEnEvento) ni la emisión: cada participación es un participante con su propia emisión.
 */
final class DobleModalidad
{
    public const MISMA_MODALIDAD = 'misma_modalidad';

    public const SIN_MODALIDAD = 'sin_modalidad';

    public const OTRO_NOMBRE = 'otro_nombre';

    /** ¿El evento está autorizado expresamente? Sin evento, nunca. */
    public static function autorizado(?int $eventoId): bool
    {
        if ($eventoId === null || $eventoId <= 0) {
            return false;
        }

        return in_array($eventoId, array_map('intval', (array) config('credential_flow.doble_modalidad.eventos', [])), true);
    }

    /** Modalidad efectiva normalizada (minúsculas, espacios colapsados): la propia de la persona o, si no tiene, la general de la base. Vacía si ninguna. */
    public static function efectiva(?string $propia, ?string $general): string
    {
        $m = Texto::limpiar((string) $propia);
        if ($m === '') {
            $m = Texto::limpiar((string) $general);
        }

        return mb_strtolower($m, 'UTF-8');
    }

    /**
     * ¿Puede esta participación convivir con las ya registradas del MISMO documento en la base? Devuelve null si sí, o el motivo por el que no.
     *
     * @param  iterable<array{nombre:string, modalidad:string}>  $otras  las demás participaciones del documento (modalidad ya normalizada con `efectiva`)
     */
    public static function conflicto(string $nombre, string $modalidad, iterable $otras): ?string
    {
        $grupo = NombreConservador::grupoId($nombre);
        foreach ($otras as $o) {
            if ($modalidad === '' || $o['modalidad'] === '') {
                return self::SIN_MODALIDAD;
            }
            if ($o['modalidad'] === $modalidad) {
                return self::MISMA_MODALIDAD;
            }
            if (NombreConservador::grupoId($o['nombre']) !== $grupo) {
                return self::OTRO_NOMBRE;
            }
        }

        return null;
    }

    /** Texto para el administrador según el motivo. */
    public static function mensaje(string $motivo): string
    {
        return match ($motivo) {
            self::MISMA_MODALIDAD => 'ya hay una participación de ese documento con la misma modalidad',
            self::SIN_MODALIDAD => 'para registrar dos participaciones del mismo documento, cada una debe tener su modalidad',
            default => 'el mismo documento ya está registrado con otro nombre',
        };
    }
}
