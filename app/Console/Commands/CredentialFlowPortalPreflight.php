<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Portal\PortalFlag;
use App\Support\CredentialFlow\Portal\VerificadorSmtp;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Throwable;

/**
 * Diagnóstico de SOLO LECTURA para decidir si el portal público puede activarse (Fase 11C.1). No escribe nada (ni BD, ni archivos, ni caché), no conecta al
 * SMTP, no envía correo y NO activa el portal: `CREDENTIAL_FLOW_PORTAL_ENABLED` se cambia a mano, solo en una fase autorizada.
 * Código de salida 1 si algún chequeo es NO_APTO (no listo para activar).
 */
class CredentialFlowPortalPreflight extends Command
{
    protected $signature = 'credential-flow:portal:preflight {--json : Salida estructurada}';

    protected $description = 'Comprueba (solo lectura) si el portal público está listo para activarse: SMTP, APP_KEY, sesión, HTTPS, flags y dependencias.';

    private const TABLAS = ['cf_certificados_legado', 'cf_correos', 'cf_accesos_otp', 'cf_envios', 'cf_descargas', 'cf_plantillas_legado_contenidos'];

    private const EXTENSIONES = ['mbstring', 'gd', 'zip', 'fileinfo', 'intl', 'xmlreader', 'xmlwriter', 'dom', 'zlib', 'openssl', 'pdo_mysql'];

    private const CLASES = ['TCPDF', 'setasign\\Fpdi\\Tcpdf\\Fpdi', 'OpenSpout\\Reader\\XLSX\\Reader'];

    /** @var list<array{id:string,estado:string,detalle:string}> */
    private array $checks = [];

    public function handle(): int
    {
        $this->checks = [];
        $this->add('portal_enabled', 'INFO', PortalFlag::habilitado() ? 'ENCENDIDO' : 'APAGADO (el portal no responde; se enciende a mano en la fase autorizada)');

        $smtp = VerificadorSmtp::evaluar();
        $this->add(
            'smtp',
            $smtp['apto'] ? 'OK' : 'NO_APTO',
            $smtp['estado'].($smtp['problemas'] ? ' · '.implode(', ', $smtp['problemas']) : '').($smtp['advertencias'] ? ' · avisos: '.implode(', ', $smtp['advertencias']) : '').' · mailer='.$smtp['mailer'],
        );

        $k = (string) config('app.key', '');
        $bytes = str_starts_with($k, 'base64:') ? strlen((string) base64_decode(substr($k, 7), true)) : strlen($k);
        $this->add('app_key', ($k !== '' && in_array($bytes, [16, 32], true)) ? 'OK' : 'NO_APTO', $k === '' ? 'AUSENTE' : "PRESENTE ({$bytes} bytes)");

        $driver = (string) config('session.driver');
        $this->add('sesion', in_array($driver, ['database', 'redis', 'memcached', 'file', 'cookie'], true) ? 'OK' : 'NO_APTO', "driver={$driver}");

        $url = (string) config('app.url');
        $base = (string) (config('credential_flow.verificacion.base_url') ?: $url);
        $https = str_starts_with($url, 'https://');
        $this->add('https', $https ? 'OK' : 'ADVERTENCIA', 'APP_URL '.($https ? 'https' : 'no https').(config('session.secure') === false ? ' · cookies sin Secure forzado' : ''));
        $this->add('url_verificacion', (str_starts_with($base, 'https://') && ! str_contains($base, 'localhost')) ? 'OK' : 'NO_APTO', str_starts_with($base, 'https://') ? 'https definida' : 'no es https o no está definida');

        foreach (['decisiones_enabled', 'multi_scope_enabled', 'mass_scope_enabled'] as $f) {
            $this->add('flag_'.$f, 'INFO', config('credential_flow.identidad.'.$f) === true ? 'ENCENDIDO' : 'apagado');
        }

        $faltan = array_values(array_filter(self::EXTENSIONES, fn ($e) => ! extension_loaded($e)));
        $clases = array_values(array_filter(self::CLASES, fn ($cl) => ! class_exists($cl)));
        $this->add('dependencias', ($faltan === [] && $clases === []) ? 'OK' : 'NO_APTO', ($faltan === [] && $clases === []) ? 'extensiones y librerías presentes' : 'faltan: '.implode(', ', array_merge($faltan, $clases)));

        try {
            DB::connection()->getPdo();
            $sin = array_values(array_filter(self::TABLAS, fn ($t) => ! Schema::hasTable($t)));
            $this->add('esquema', $sin === [] ? 'OK' : 'NO_APTO', $sin === [] ? 'tablas del portal presentes' : 'faltan tablas: '.implode(', ', $sin));
        } catch (Throwable) {
            $this->add('esquema', 'NO_APTO', 'sin conexión a la BD');
        }

        $noApto = array_values(array_filter($this->checks, fn ($x) => $x['estado'] === 'NO_APTO'));
        $r = ['listo_para_activar' => $noApto === [], 'portal_enabled' => PortalFlag::habilitado(), 'bloqueos' => array_column($noApto, 'id'), 'checks' => $this->checks];

        if ($this->option('json')) {
            $this->line((string) json_encode($r, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES));
        } else {
            foreach ($this->checks as $x) {
                $this->line(sprintf('  [%-10s] %-18s %s', $x['estado'], $x['id'], $x['detalle']));
            }
            $this->line($r['listo_para_activar'] ? 'PORTAL: LISTO PARA ACTIVAR (la activación es manual y está fuera de esta herramienta)' : 'PORTAL: NO LISTO · bloqueos: '.implode(', ', $r['bloqueos']));
        }

        return $r['listo_para_activar'] ? self::SUCCESS : self::FAILURE;
    }

    private function add(string $id, string $estado, string $detalle): void
    {
        $this->checks[] = ['id' => $id, 'estado' => $estado, 'detalle' => $detalle];
    }
}
