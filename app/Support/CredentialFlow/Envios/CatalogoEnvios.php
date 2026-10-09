<?php

namespace App\Support\CredentialFlow\Envios;

use App\Models\CredentialFlow\Envio;

/** Textos en español claro para los tipos, estados y errores de envío (lo único que ve el administrador). */
final class CatalogoEnvios
{
    public const TIPOS = [Envio::TIPO_OTP => 'Código de acceso al portal'];

    public const ESTADOS = [
        Envio::PENDIENTE => ['etiqueta' => 'Pendiente', 'tono' => 'slate', 'ayuda' => 'Registrado, todavía no se ha intentado enviar.'],
        Envio::PROCESANDO => ['etiqueta' => 'Procesando', 'tono' => 'sky', 'ayuda' => 'Se está enviando en este momento.'],
        Envio::ACEPTADO => ['etiqueta' => 'Aceptado por el servidor de correo', 'tono' => 'emerald', 'ayuda' => 'El servidor de correo aceptó el mensaje. Esto no confirma que haya llegado a la bandeja de entrada.'],
        Envio::FALLIDO_TEMPORAL => ['etiqueta' => 'Falló (error temporal)', 'tono' => 'amber', 'ayuda' => 'No se pudo enviar por un problema que podría resolverse. Los códigos no se reintentan más tarde: la persona puede pedir uno nuevo.'],
        Envio::FALLIDO_PERMANENTE => ['etiqueta' => 'No se envió', 'tono' => 'rose', 'ayuda' => 'El mensaje no se envió y reintentar no lo resolvería.'],
    ];

    private const ERRORES = [
        'ENVIO_DESACTIVADO' => 'Los envíos de correo están desactivados en la configuración.',
        'TRANSPORTE_NO_PERMITIDO' => 'El transporte de correo configurado no es seguro para enviar códigos de acceso.',
        'OTP_NO_VIGENTE' => 'El código ya no estaba vigente (venció, se usó o se reemplazó), por eso no se envió.',
        'LIMITE_GLOBAL' => 'Se alcanzó el límite global de envíos y el código no se envió.',
        'CONEXION' => 'No fue posible conectar con el servidor de correo.',
        'TIMEOUT' => 'El servidor de correo tardó demasiado en responder.',
        'AUTENTICACION' => 'El servidor de correo rechazó las credenciales configuradas.',
        'DIRECCION_INVALIDA' => 'La dirección del destinatario no es válida.',
        'MENSAJE_INVALIDO' => 'El mensaje no se pudo construir.',
        'ERROR_DESCONOCIDO' => 'Ocurrió un error inesperado al enviar el correo.',
        'ERROR_INTERNO' => 'Ocurrió un error interno al registrar el envío.',
    ];

    public static function tipo(string $tipo): string
    {
        return self::TIPOS[$tipo] ?? 'Otro tipo de correo';
    }

    /** @return array{etiqueta:string,tono:string,ayuda:string} */
    public static function estado(string $estado): array
    {
        return self::ESTADOS[$estado] ?? ['etiqueta' => 'Desconocido', 'tono' => 'slate', 'ayuda' => ''];
    }

    public static function error(?string $codigo, ?string $clase = null): ?string
    {
        if ($codigo === null) {
            return null;
        }
        if (isset(self::ERRORES[$codigo])) {
            return self::ERRORES[$codigo];
        }
        if (preg_match('/^SMTP_(\d{3})$/', $codigo, $m) === 1) {
            return $clase === ClasificadorErrorEnvio::PERMANENTE
                ? "El servidor de correo rechazó el mensaje (respuesta {$m[1]})."
                : "El servidor de correo respondió con un error temporal (respuesta {$m[1]}).";
        }

        return 'Error de envío no clasificado.';
    }

    /** @return array<string,string> código → etiqueta, para filtros */
    public static function opcionesEstado(): array
    {
        return array_map(fn ($e) => $e['etiqueta'], self::ESTADOS);
    }
}
