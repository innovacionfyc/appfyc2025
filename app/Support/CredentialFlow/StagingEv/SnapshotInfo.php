<?php

namespace App\Support\CredentialFlow\StagingEv;

use InvalidArgumentException;

/**
 * Metadata de un snapshot del sistema viejo. El dump NUNCA se modifica: solo se lee para calcular su SHA-256 y
 * extraer lo que declara en su cabecera.
 */
final readonly class SnapshotInfo
{
    public function __construct(
        public string $etiqueta,
        public string $origen,
        public ?string $dumpArchivo = null,
        public ?string $dumpSha256 = null,
        public ?string $tomadoAt = null,
        public ?string $servidorOrigen = null,
        public ?string $inventarioImagenesSha256 = null,
    ) {
        if (trim($etiqueta) === '') {
            throw new InvalidArgumentException('El snapshot necesita una etiqueta.');
        }
    }

    /**
     * Lee un dump de mysqldump (.sql o .sql.gz): SHA-256 del archivo tal cual, nombre (sin ruta), fecha de la cabecera
     * `-- Dump completed on YYYY-MM-DD HH:MM:SS` y servidor (`-- Server version` / `MariaDB dump … Distrib …`).
     */
    public static function desdeDump(string $ruta, string $etiqueta, string $origen): self
    {
        if (! is_file($ruta) || ! is_readable($ruta)) {
            throw new InvalidArgumentException('No se puede leer el dump indicado.');
        }

        $sha = hash_file('sha256', $ruta);
        $abrir = str_ends_with(strtolower($ruta), '.gz') ? 'gzopen' : 'fopen';
        $leer = $abrir === 'gzopen' ? 'gzgets' : 'fgets';
        $cerrar = $abrir === 'gzopen' ? 'gzclose' : 'fclose';
        $h = $abrir($ruta, 'rb');
        if ($h === false) {
            throw new InvalidArgumentException('No se pudo abrir el dump.');
        }

        $servidor = null;
        $tomado = null;
        while (($linea = $leer($h)) !== false) {
            if ($servidor === null && preg_match('/^-- (?:MariaDB|MySQL) dump .* Distrib ([0-9.]+)(-MariaDB)?/i', $linea, $m) === 1) {
                $servidor = ($m[2] ?? '') !== '' ? 'MariaDB '.$m[1] : 'MySQL '.$m[1];
            }
            if (preg_match('/^-- Server version\s+(\S+)/', $linea, $m) === 1) {
                $servidor = $m[1];
            }
            if (preg_match('/^-- Dump completed on (\d{4}-\d{2}-\d{2}) (\d{2}:\d{2}:\d{2})/', $linea, $m) === 1) {
                $tomado = $m[1].' '.$m[2];
            }
        }
        $cerrar($h);

        return new self($etiqueta, $origen, basename($ruta), $sha, $tomado, $servidor);
    }
}
