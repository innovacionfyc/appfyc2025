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

    /** Un id de evento: entero positivo decimal, sin ceros a la izquierda ni signo, de hasta 18 cifras (siempre cabe exacto en un entero de PHP). */
    private const ID = '/^[1-9][0-9]{0,17}$/D';

    /** ¿El evento está autorizado expresamente? Sin evento, nunca. */
    public static function autorizado(?int $eventoId): bool
    {
        if ($eventoId === null || $eventoId <= 0) {
            return false;
        }

        return in_array($eventoId, self::eventosAutorizados(), true);
    }

    /** @return list<int> los eventos autorizados por la configuración (vacío = ninguno) */
    public static function eventosAutorizados(): array
    {
        return self::interpretar(config('credential_flow.doble_modalidad.eventos'));
    }

    /**
     * Lectura ESTRICTA de `CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS`: ids separados solo por comas («636» o «636,637»). No se recortan ni se toleran espacios,
     * signos, ceros a la izquierda, decimales, notación científica, sufijos, otros separadores, elementos vacíos, repetidos ni ids de más de 18 cifras: si
     * CUALQUIER elemento no es válido, no se autoriza NINGÚN evento (fallo cerrado). Cada elemento se valida como texto antes de convertirlo a número.
     *
     * @return list<int>
     */
    public static function interpretar(mixed $valor): array
    {
        if (! is_string($valor) || $valor === '') {
            return [];
        }
        $ids = [];
        foreach (explode(',', $valor) as $parte) {
            if (preg_match(self::ID, $parte) !== 1 || in_array($parte, $ids, true)) {
                return [];
            }
            $ids[] = $parte;
        }

        return array_map('intval', $ids);
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
