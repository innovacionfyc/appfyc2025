<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\AutorizacionDobleModalidad;
use App\Support\CredentialFlow\Portal\NombreConservador;
use Illuminate\Support\Facades\Schema;

/**
 * Excepción controlada a «un documento por base»: en un evento AUTORIZADO de forma expresa (autorización vigente en `cf_eventos_doble_modalidad`, que
 * administra un super-admin, o la variable heredada `credential_flow.doble_modalidad.eventos`), una misma persona puede
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

    public const ORIGEN_BASE_DE_DATOS = 'base_de_datos';

    public const ORIGEN_HEREDADO = 'configuracion_heredada';

    public const ORIGEN_AMBOS = 'ambos';

    /**
     * ¿El evento está autorizado expresamente? Sin evento, nunca. Fuentes: una autorización VIGENTE en `cf_eventos_doble_modalidad` (la administra un
     * super-admin desde el panel) o, durante la transición, la variable heredada `CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS`. Basta una de las dos.
     *
     * `$bloquear`: lectura con bloqueo compartido de la autorización, para usar DENTRO de una transacción que ya bloqueó la fila del evento (confirmar una
     * importación, guardar un participante, asociar una base). Lee el último estado confirmado aunque la transacción tenga una instantánea anterior.
     */
    public static function autorizado(?int $eventoId, bool $bloquear = false): bool
    {
        if ($eventoId === null || $eventoId <= 0) {
            return false;
        }

        return in_array($eventoId, self::eventosHeredados(), true) || self::enBaseDeDatos($eventoId, $bloquear);
    }

    /** @return list<int> todos los eventos autorizados (base de datos + variable heredada), ordenados y sin repetir (vacío = ninguno) */
    public static function eventosAutorizados(): array
    {
        $ids = array_values(array_unique([...self::eventosHeredados(), ...self::eventosEnBaseDeDatos()]));
        sort($ids);

        return $ids;
    }

    /** @return list<int> los eventos autorizados por la variable heredada (vacío = ninguno o variable inválida) */
    public static function eventosHeredados(): array
    {
        return self::interpretar(config('credential_flow.doble_modalidad.eventos'));
    }

    /** @return list<int> los eventos con una autorización vigente en la base de datos */
    public static function eventosEnBaseDeDatos(): array
    {
        if (! self::tablaDisponible()) {
            return [];
        }

        return AutorizacionDobleModalidad::query()->whereNotNull('vigente_evento_id')->orderBy('vigente_evento_id')->pluck('vigente_evento_id')->map(fn ($id) => (int) $id)->all();
    }

    /** De dónde sale la autorización del evento: base_de_datos, configuracion_heredada, ambos o null (no autorizado). */
    public static function origen(?int $eventoId): ?string
    {
        if ($eventoId === null || $eventoId <= 0) {
            return null;
        }
        $heredado = in_array($eventoId, self::eventosHeredados(), true);
        $bd = self::enBaseDeDatos($eventoId);

        return match (true) {
            $heredado && $bd => self::ORIGEN_AMBOS,
            $bd => self::ORIGEN_BASE_DE_DATOS,
            $heredado => self::ORIGEN_HEREDADO,
            default => null,
        };
    }

    /** La autorización VIGENTE en la base de datos (null si no hay o si la tabla aún no existe). */
    public static function vigente(int $eventoId, bool $bloquear = false): ?AutorizacionDobleModalidad
    {
        if (! self::tablaDisponible()) {
            return null;
        }

        return AutorizacionDobleModalidad::query()->where('vigente_evento_id', $eventoId)->when($bloquear, fn ($q) => $q->sharedLock())->first();
    }

    private static function enBaseDeDatos(int $eventoId, bool $bloquear = false): bool
    {
        return self::vigente($eventoId, $bloquear) !== null;
    }

    /** Antes de la migración la tabla no existe: entonces la única fuente es la variable heredada (comportamiento anterior, sin errores). */
    private static function tablaDisponible(): bool
    {
        return Schema::hasTable('cf_eventos_doble_modalidad');
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
