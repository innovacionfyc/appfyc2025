<?php

namespace App\Support\CredentialFlow\Envios;

use Symfony\Component\Mime\Exception\ExceptionInterface as MimeException;
use Throwable;

/**
 * Clasifica un fallo del transporte de correo en TEMPORAL o PERMANENTE y le asigna un código técnico corto. Solo se lee el código de
 * respuesta SMTP y el tipo de excepción: el MENSAJE de la excepción se descarta siempre, porque puede contener la dirección del
 * destinatario u otros datos personales.
 *
 *  - Temporal (se puede reintentar): conexión, tiempo de espera, SMTP 4xx, error desconocido.
 *  - Permanente (no se reintenta): SMTP 5xx, autenticación, dirección o mensaje inválidos.
 */
final class ClasificadorErrorEnvio
{
    public const TEMPORAL = 'temporal';

    public const PERMANENTE = 'permanente';

    /** @return array{clase:string,codigo:string} */
    public static function clasificar(Throwable $e): array
    {
        if ($e instanceof MimeException) {
            return ['clase' => self::PERMANENTE, 'codigo' => 'DIRECCION_INVALIDA'];
        }
        $mensaje = $e->getMessage();

        if (preg_match('/but got code "?(\d{3})"?/i', $mensaje, $m) === 1 || preg_match('/^\s*(\d{3})[ -]/', $mensaje, $m) === 1) {
            $codigo = (int) $m[1];
            if (in_array($codigo, [530, 534, 535, 538], true) || stripos($mensaje, 'authenticate') !== false) {
                return ['clase' => self::PERMANENTE, 'codigo' => 'AUTENTICACION'];
            }

            return ['clase' => $codigo >= 500 ? self::PERMANENTE : self::TEMPORAL, 'codigo' => 'SMTP_'.$codigo];
        }
        if (stripos($mensaje, 'authenticate') !== false) {
            return ['clase' => self::PERMANENTE, 'codigo' => 'AUTENTICACION'];
        }
        if (stripos($mensaje, 'timed out') !== false || stripos($mensaje, 'timeout') !== false) {
            return ['clase' => self::TEMPORAL, 'codigo' => 'TIMEOUT'];
        }
        if (stripos($mensaje, 'connection') !== false || stripos($mensaje, 'could not be established') !== false || stripos($mensaje, 'unable to connect') !== false) {
            return ['clase' => self::TEMPORAL, 'codigo' => 'CONEXION'];
        }
        if ($e instanceof \LogicException) {
            return ['clase' => self::PERMANENTE, 'codigo' => 'MENSAJE_INVALIDO'];
        }

        return ['clase' => self::TEMPORAL, 'codigo' => 'ERROR_DESCONOCIDO'];
    }
}
