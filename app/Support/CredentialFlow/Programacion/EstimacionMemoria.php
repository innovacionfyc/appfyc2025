<?php

namespace App\Support\CredentialFlow\Programacion;

/**
 * Cuánta memoria necesita una emisión antes de empezar, para fallar con un mensaje claro en lugar de quedarse sin memoria a mitad de camino (y reintentar en
 * bucle). Medido de forma reproducible (P2, MariaDB 10.6 desechable y datos ficticios): con la liberación de memoria tras cada PDF (`gc_collect_cycles` en
 * EmisorLote) el consumo NO crece con la cantidad de certificados del lote, pero sí con el peso del PDF base de la plantilla. Pico del EJECUTOR COMPLETO en un
 * proceso nuevo (como el cron), 200 oficiales + 8 QA:
 *
 *   PDF base 0,71 MB → 48 MB · 2,09 MB → 58 · 4 MB → 66 · 9,45 MB → 82 · 20,9 MB (límite de subida) → 130 MB.
 *
 * Con 20,9 MB y el límite de 128 MB el ejecutor falla en 0,13 s con MEMORIA_INSUFICIENTE y 0 certificados, en lugar de quedarse sin memoria a mitad de camino.
 *
 * Estimación conservadora (al menos 7 MB de margen sobre cada punto medido): 56 MB + 4,5 MB por cada MB del PDF base más pesado (oficial o variante QA). Con el
 * límite de 128 MB de producción alcanza para plantillas de hasta unos 16 MB; las de hoy pesan 0,4 MB (≈ 58 MB).
 */
final class EstimacionMemoria
{
    public const FIJO_MB = 56;

    public const POR_MB_DE_PLANTILLA = 4.5;

    /** Solo pruebas: límite de memoria simulado en MB (null = el real de PHP). */
    public static ?int $limiteSimuladoMb = null;

    public static function requeridaMb(int $bytesPlantilla): int
    {
        return (int) ceil(self::FIJO_MB + self::POR_MB_DE_PLANTILLA * $bytesPlantilla / 1048576);
    }

    /** Límite de memoria del proceso en MB, o null si no tiene límite. */
    public static function limiteMb(): ?int
    {
        if (self::$limiteSimuladoMb !== null) {
            return self::$limiteSimuladoMb;
        }
        $valor = trim((string) ini_get('memory_limit'));
        if ($valor === '' || $valor === '-1') {
            return null;
        }
        $numero = (float) $valor;

        return (int) floor(match (strtoupper(substr($valor, -1))) {
            'G' => $numero * 1024,
            'M' => $numero,
            'K' => $numero / 1024,
            default => $numero / 1048576,
        });
    }

    public static function alcanza(int $bytesPlantilla): bool
    {
        $limite = self::limiteMb();

        return $limite === null || self::requeridaMb($bytesPlantilla) <= $limite;
    }
}
