<?php

namespace App\Support\CredentialFlow\Portal;

/** Interruptor maestro del portal público de certificados (Fase 11C.1). Falla cerrado: solo un `true` estricto lo enciende. */
final class PortalFlag
{
    public static function habilitado(): bool
    {
        return config('credential_flow.portal_enabled', false) === true;
    }
}
