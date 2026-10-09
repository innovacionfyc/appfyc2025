<?php

namespace App\Support\CredentialFlow\Rehearsal;

/**
 * Huella TÉCNICA y NO reversible de la `APP_KEY` con la que se generaron los datos derivados (Fase 11A). Todo hash persistido de Credential Flow (HMAC de documento,
 * correo, grupo, pares de códigos, referencia de los casos de identidad) depende de esa clave: una BD derivada con una clave NO es portable a otra. La huella sirve solo
 * para responder «¿esta BD derivada se creó con la clave actual?». Es un HMAC-SHA256 con la clave sobre un dominio fijo: de la huella no se puede recuperar la clave.
 * Jamás se registra, imprime ni persiste la `APP_KEY`.
 */
final class HuellaClave
{
    public const DOMINIO = 'credential-flow:huella-clave:v1';

    public static function actual(): string
    {
        return hash_hmac('sha256', self::DOMINIO, (string) config('app.key'));
    }
}
