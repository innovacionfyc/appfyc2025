<?php

namespace App\Support\CredentialFlow\Portal;

/**
 * ¿La configuración de correo es APTA para enviar los OTP del portal en producción? (Fase 11C.1). Solo lee configuración: NO conecta al servidor SMTP,
 * NO autentica y NO envía nada. Detecta configuraciones claramente no aptas: servidores de desarrollo (mailpit, mailhog…), host vacío o de pruebas,
 * remitente de ejemplo, transportes que no entregan (`log`, `array`), puertos inválidos y envío del módulo deshabilitado.
 *
 * Resultado: `apto` (SMTP_APTO / SMTP_NO_APTO), `problemas` (códigos que bloquean) y `advertencias` (no bloquean). Sin credenciales ni valores sensibles.
 */
final class VerificadorSmtp
{
    private const HOSTS_DESARROLLO = ['mailpit', 'mailhog', 'mailtrap', 'localhost', '127.0.0.1', '::1', 'maildev', 'smtp4dev', 'ethereal.email'];

    private const DOMINIOS_EJEMPLO = ['example.com', 'example.org', 'example.net', 'example.test', 'localhost', 'localdomain', 'test', 'invalid'];

    /** @return array{estado:string,apto:bool,mailer:string,problemas:list<string>,advertencias:list<string>,smtp:array<string,mixed>} */
    public static function evaluar(): array
    {
        $problemas = [];
        $advertencias = [];
        $default = (string) config('mail.default', '');
        $mailers = (array) config('mail.mailers', []);
        $efectivos = self::mailersEfectivos($default, $mailers);

        if ($default === '' || $efectivos === []) {
            $problemas[] = 'MAILER_NO_CONFIGURADO';
        }
        $resumenSmtp = ['host_definido' => false, 'puerto' => null, 'cifrado' => null, 'usuario_presente' => false, 'clave_presente' => false];
        foreach ($efectivos as $nombre) {
            $m = (array) ($mailers[$nombre] ?? []);
            $transporte = (string) ($m['transport'] ?? '');
            if (in_array($transporte, ['log', 'array', ''], true)) {
                $problemas[] = 'MAILER_NO_ENTREGA_CORREO';

                continue;
            }
            if ($transporte !== 'smtp') {
                continue;   // ses/postmark/resend/sendmail: su validez depende de credenciales propias de ese proveedor; no se infiere por configuración SMTP
            }
            $host = strtolower(trim((string) ($m['host'] ?? '')));
            $puerto = (int) ($m['port'] ?? 0);
            $resumenSmtp = [
                'host_definido' => $host !== '', 'puerto' => $puerto ?: null, 'cifrado' => $m['scheme'] ?? ($m['encryption'] ?? null),
                'usuario_presente' => ! empty($m['username']), 'clave_presente' => ! empty($m['password']),
            ];
            if ($host === '') {
                $problemas[] = 'SMTP_HOST_VACIO';
            } elseif (in_array($host, self::HOSTS_DESARROLLO, true) || self::dominioDeEjemplo($host)) {
                $problemas[] = 'SMTP_HOST_DESARROLLO';
            }
            if ($puerto < 1 || $puerto > 65535) {
                $problemas[] = 'SMTP_PUERTO_INVALIDO';
            }
            if (empty($m['username']) || empty($m['password'])) {
                $advertencias[] = 'SMTP_SIN_CREDENCIALES';   // algunos relays internos no autentican: se avisa, no se bloquea
            }
            if (empty($m['scheme'] ?? $m['encryption'] ?? null) && $puerto !== 25) {
                $advertencias[] = 'SMTP_SIN_CIFRADO';
            }
        }

        $remitente = strtolower(trim((string) (config('credential_flow.correo.remitente.direccion') ?: config('mail.from.address', ''))));
        if ($remitente === '' || filter_var($remitente, FILTER_VALIDATE_EMAIL) === false) {
            $problemas[] = 'REMITENTE_INVALIDO';
        } elseif (self::dominioDeEjemplo(substr(strrchr($remitente, '@') ?: '', 1))) {
            $problemas[] = 'REMITENTE_DE_EJEMPLO';
        }

        if (config('credential_flow.correo.habilitado') !== true) {
            $problemas[] = 'ENVIO_DEL_MODULO_DESHABILITADO';
        }
        if (config('credential_flow.correo.permitir_transporte_log') === true) {
            $advertencias[] = 'TRANSPORTE_LOG_PERMITIDO';   // solo debe existir en QA local: escribiría el OTP en un archivo
        }

        $problemas = array_values(array_unique($problemas));

        return ['estado' => $problemas === [] ? 'SMTP_APTO' : 'SMTP_NO_APTO', 'apto' => $problemas === [], 'mailer' => $default, 'problemas' => $problemas,
            'advertencias' => array_values(array_unique($advertencias)), 'smtp' => $resumenSmtp];
    }

    /** @param array<string,mixed> $mailers @return list<string> */
    private static function mailersEfectivos(string $default, array $mailers, int $nivel = 0): array
    {
        $m = (array) ($mailers[$default] ?? []);
        if ($m === []) {
            return [];
        }
        if (in_array($m['transport'] ?? '', ['failover', 'roundrobin'], true) && $nivel < 3) {
            $r = [];
            foreach ((array) ($m['mailers'] ?? []) as $hijo) {
                $r = array_merge($r, self::mailersEfectivos((string) $hijo, $mailers, $nivel + 1));
            }

            return $r;
        }

        return [$default];
    }

    private static function dominioDeEjemplo(string $dominio): bool
    {
        $dominio = strtolower(trim($dominio));
        foreach (self::DOMINIOS_EJEMPLO as $d) {
            if ($dominio === $d || str_ends_with($dominio, '.'.$d)) {
                return true;
            }
        }

        return false;
    }
}
