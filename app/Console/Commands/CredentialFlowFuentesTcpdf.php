<?php

namespace App\Console\Commands;

use App\Support\CredentialFlow\FuentesCredential;
use App\Support\CredentialFlow\Generacion\FuentesTcpdf;
use Illuminate\Console\Command;
use Throwable;

/**
 * Genera (o verifica con --verify) las definiciones TCPDF de Outfit en
 * resources/fonts/credential-flow/tcpdf/ a partir de los TTF versionados. La verificación es
 * semántica: no exige igualdad binaria de los .z (zlib puede producir bytes distintos).
 */
class CredentialFlowFuentesTcpdf extends Command
{
    protected $signature = 'credential-flow:fuentes-tcpdf {--verify : Solo comprueba las definiciones versionadas; no escribe nada}';

    protected $description = 'Genera o verifica las definiciones TCPDF de las fuentes de Credential Flow';

    public function handle(): int
    {
        try {
            FuentesTcpdf::configurar();

            return $this->option('verify') ? $this->verificar() : $this->generar();
        } catch (Throwable $e) {
            $this->error('Error: '.$e->getMessage());

            return self::FAILURE;
        }
    }

    private function generar(): int
    {
        $dir = FuentesTcpdf::directorio();
        if (! is_dir($dir)) {
            mkdir($dir, 0775, true);
        }

        foreach (FuentesTcpdf::DEFINICIONES as $peso => [$nombreEsperado]) {
            $ttf = FuentesCredential::rutaArchivo(FuentesCredential::OUTFIT, $peso);
            $nombre = \TCPDF_FONTS::addTTFfont($ttf, 'TrueTypeUnicode', '', 32, $dir.'/');
            if ($nombre !== $nombreEsperado) {
                $this->error("TCPDF nombró la fuente {$peso} como «{$nombre}» y se esperaba «{$nombreEsperado}».");

                return self::FAILURE;
            }
            $this->line("  {$peso}: {$nombre}");
        }

        return $this->verificar();
    }

    private function verificar(): int
    {
        $errores = [];

        foreach (FuentesTcpdf::DEFINICIONES as $peso => [$nombre, $postscript]) {
            $ruta = FuentesTcpdf::directorio()."/{$nombre}.php";
            if (! is_file($ruta)) {
                $errores[] = "{$peso}: falta {$nombre}.php";

                continue;
            }

            $d = (static function (string $__ruta): array {
                include $__ruta;

                return get_defined_vars();
            })($ruta);

            $ttf = FuentesCredential::rutaArchivo(FuentesCredential::OUTFIT, $peso);
            $m = FuentesCredential::metricasDe(FuentesCredential::OUTFIT, $peso);

            foreach (['file', 'ctg'] as $clave) {
                if (empty($d[$clave]) || ! is_file(FuentesTcpdf::directorio().'/'.$d[$clave])) {
                    $errores[] = "{$peso}: falta el archivo de la definición ({$clave})";
                }
            }
            if (($d['type'] ?? null) !== 'TrueTypeUnicode') {
                $errores[] = "{$peso}: tipo distinto de TrueTypeUnicode";
            }
            if (($d['name'] ?? null) !== $postscript) {
                $errores[] = "{$peso}: nombre «".($d['name'] ?? '')."» distinto de «{$postscript}»";
            }
            if (($d['originalsize'] ?? null) !== filesize($ttf)) {
                $errores[] = "{$peso}: originalsize no coincide con el TTF";
            }
            if (($d['desc']['Ascent'] ?? null) !== $m['ascent'] || ($d['desc']['Descent'] ?? null) !== $m['descent']) {
                $errores[] = "{$peso}: Ascent/Descent no coinciden con metricas.json";
            }

            $cw = $d['cw'] ?? [];
            foreach ($m['anchos'] as $cp => $ancho) {
                if (($cw[$cp] ?? null) !== $ancho) {
                    $errores[] = "{$peso}: ancho de U+".dechex($cp).' distinto de metricas.json';
                    break;
                }
            }
            $sobrantes = array_diff(array_keys($cw), array_keys($m['anchos']), [0]);
            if ($sobrantes !== []) {
                $errores[] = "{$peso}: la definición tiene caracteres que metricas.json no tiene";
            }
        }

        if ($errores !== []) {
            foreach ($errores as $e) {
                $this->error($e);
            }

            return self::FAILURE;
        }

        $this->info('Las definiciones TCPDF coinciden con los TTF y con metricas.json.');

        return self::SUCCESS;
    }
}
