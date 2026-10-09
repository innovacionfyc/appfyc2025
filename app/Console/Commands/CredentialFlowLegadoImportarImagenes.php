<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\Legado\ImportadorImagenesLegado;
use Illuminate\Console\Command;
use Throwable;

/**
 * Importa las imágenes históricas al storage privado por SHA-256 (Fase 11A), las verifica o revierte UNA corrida. No toca producción por sí mismo: opera sobre el disco
 * privado de la aplicación y la BD de la conexión por defecto. Streaming (cabe en 128 MB). Termina con código 1 ante cualquier faltante o error (NO-GO).
 */
class CredentialFlowLegadoImportarImagenes extends Command
{
    protected $signature = 'credential-flow:legado:importar-imagenes
        {--origen= : Carpeta con las imágenes del sistema viejo (certImages)}
        {--manifest= : Manifiesto JSON (sha256 + nombre_local) para no recalcular el SHA de toda la carpeta}
        {--disco=local : Disco privado de destino}
        {--simular : Calcula qué haría sin escribir nada}
        {--limite= : Procesa como máximo N contenidos (para corridas parciales o pruebas de reanudación)}
        {--verificar : Solo verifica las referencias (ruta, archivo, bytes, SHA-256, MIME); no escribe}
        {--manifiesto-salida= : Escribe el manifiesto del destino (sin PII) en ese archivo}
        {--revertir= : Revierte la corrida con ese id de diario (solo lo que esa corrida creó)}
        {--json : Salida en JSON}';

    protected $description = 'Importa o verifica las imágenes históricas en el storage privado (por SHA-256, idempotente, reanudable y reversible).';

    public function handle(): int
    {
        try {
            $imp = new ImportadorImagenesLegado((string) $this->option('disco'));
            if ($this->option('revertir')) {
                if (app()->environment('production')) {
                    $this->error('Revertir una importación no se permite en producción.');

                    return self::FAILURE;
                }

                return $this->salida(['revertir' => $imp->revertir((string) $this->option('revertir'))], true);
            }
            if ($this->option('verificar')) {
                $v = $imp->verificar();
                $m = $imp->manifiesto();
                $this->guardarManifiesto($m);

                return $this->salida(['verificacion' => $v, 'manifiesto_huella' => $m['huella'], 'storage' => $imp->huellaStorage()], $v['total'] > 0 && $v['ok'] === $v['total']);
            }
            if (! $this->option('origen')) {
                $this->error('Indica --origen=<carpeta>.');

                return self::FAILURE;
            }
            $r = $imp->importar((string) $this->option('origen'), $this->option('manifest') ?: null, (bool) $this->option('simular'), $this->option('limite') === null ? null : (int) $this->option('limite'));
            $this->guardarManifiesto($imp->manifiesto());

            return $this->salida(['importacion' => $r], $r['faltantes'] === [] && $r['errores'] === []);
        } catch (Throwable $e) {
            $this->error('IMPORTACION_FALLIDA: '.$e::class);

            return self::FAILURE;
        }
    }

    /** @param array{archivos:list<array<string,mixed>>,huella:string} $m */
    private function guardarManifiesto(array $m): void
    {
        if ($this->option('manifiesto-salida')) {
            file_put_contents((string) $this->option('manifiesto-salida'), json_encode($m, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
        }
    }

    /** @param array<string,mixed> $datos */
    private function salida(array $datos, bool $ok): int
    {
        if ($this->option('json')) {
            $this->line(json_encode($datos + ['ok' => $ok], JSON_UNESCAPED_UNICODE));
        } else {
            foreach ($datos as $k => $v) {
                $this->line($k.': '.json_encode($v, JSON_UNESCAPED_UNICODE));
            }
            $this->line($ok ? 'RESULTADO: OK' : 'RESULTADO: NO-GO (faltantes, errores o referencias inválidas)');
        }

        return $ok ? self::SUCCESS : self::FAILURE;
    }
}
