<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\Usuario;
use Closure;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;

/**
 * Quién y cómo se corrigen, desde la consola, los certificados YA emitidos de una base (cambio autorizado de plantilla y reemisión masiva).
 *
 *  - Solo un usuario existente con el MISMO rol que exige el panel de Credential Flow (super-admin o admin; ver routes/web/CredentialFlow/credential_flow.php).
 *    Ese usuario queda como autor en la auditoría: Movimiento, created_by/update_by, emitido_por y revocado_por.
 *  - Una sola corrección a la vez por base: un cerrojo con el mismo mecanismo que el duplicador de plantillas. Es solo la primera barrera (falla rápido y con un
 *    mensaje claro); la definitiva es el bloqueo de filas y la huella del plan dentro de cada transacción.
 */
final class AdministradorAutorizado
{
    public const ROLES = ['super-admin', 'admin'];

    /** Más que el peor caso de una operación (200 PDF); se libera siempre al terminar. */
    private const SEGUNDOS_CERROJO = 1800;

    /** @throws EmisionException */
    public static function exigir(mixed $id): Usuario
    {
        $usuario = is_numeric($id) && (int) $id > 0 ? Usuario::query()->with('perfilOrganizador.rol')->find((int) $id) : null;
        if ($usuario === null || ! in_array($usuario->rol, self::ROLES, true)) {
            throw new EmisionException(EmisionException::NO_AUTORIZADO, '--usuario debe ser el id de un administrador de Credential Flow (rol super-admin o admin).', 403);
        }

        return $usuario;
    }

    /**
     * Ejecuta la corrección como ese administrador y con la base reservada. Si otra corrección de la misma base está en curso, no empieza.
     *
     * @template T
     *
     * @param  Closure():T  $accion
     * @return T
     *
     * @throws EmisionException
     */
    public static function corregir(Usuario $usuario, int $loteId, Closure $accion): mixed
    {
        $cerrojo = Cache::lock('credential-flow:correccion-base:'.$loteId, self::SEGUNDOS_CERROJO);
        if (! $cerrojo->get()) {
            throw new EmisionException(EmisionException::OPERACION_EN_CURSO, 'Otra corrección de esta base (cambio de plantilla o reemisión) está en curso. Espera a que termine y vuelve a simular.', 409);
        }
        $anterior = Auth::user();
        Auth::setUser($usuario);

        try {
            return $accion();
        } finally {
            $anterior === null ? Auth::forgetUser() : Auth::setUser($anterior);
            $cerrojo->release();
        }
    }
}
