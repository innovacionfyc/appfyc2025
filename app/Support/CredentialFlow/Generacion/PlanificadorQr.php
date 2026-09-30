<?php

namespace App\Support\CredentialFlow\Generacion;

use App\Support\CredentialFlow\DisenoSchema;

/**
 * Valida y extrae el elemento QR (schema 2) de un diseño. Es independiente de PlanificadorTexto: el QR no
 * tiene fuente, texto ni color. Las coordenadas son las del diseño (pt, origen arriba a la izquierda).
 */
final class PlanificadorQr
{
    /**
     * @param  array<string,mixed>  $diseno
     * @param  array{width:float, height:float}  $paginaReal
     * @return array{id:string, x:float, y:float, size:float}|null null si el diseño no lleva QR
     *
     * @throws GeneracionCredencialException
     */
    public static function planificar(array $diseno, array $paginaReal, int $schemaVersion): ?array
    {
        $qrs = array_values(array_filter($diseno['elements'] ?? [], fn ($e) => is_array($e) && ($e['type'] ?? null) === DisenoSchema::TIPO_QR));
        if ($qrs === []) {
            return null;
        }

        if ($schemaVersion < DisenoSchema::VERSION_QR) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::SCHEMA_NO_SOPORTADO, 'El diseño contiene un QR, pero la versión de su esquema no lo soporta.');
        }
        if (count($qrs) > DisenoSchema::QR_MAX) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::QR_NO_VALIDO, 'Solo se permite un QR de verificación por plantilla.');
        }

        $el = $qrs[0];
        $id = (string) ($el['id'] ?? '');
        [$x, $y, $w, $h] = [(float) ($el['x'] ?? 0), (float) ($el['y'] ?? 0), (float) ($el['width'] ?? 0), (float) ($el['height'] ?? 0)];

        if (round(abs($w - $h), 4) > DisenoSchema::QR_TOLERANCIA_CUADRADO_PT) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::QR_NO_VALIDO, 'El QR debe ser cuadrado.', $id);
        }
        if ($w < DisenoSchema::QR_MIN_PT - DisenoSchema::QR_TOLERANCIA_CUADRADO_PT || $w > DisenoSchema::QR_MAX_PT + DisenoSchema::QR_TOLERANCIA_CUADRADO_PT) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::QR_NO_VALIDO, 'El QR debe medir entre '.DisenoSchema::QR_MIN_PT.' y '.DisenoSchema::QR_MAX_PT.' pt.', $id);
        }

        $t = DisenoSchema::TOLERANCIA;
        if ($x < -$t || $y < -$t || $x + $w > $paginaReal['width'] + $t || $y + $h > $paginaReal['height'] + $t) {
            throw GeneracionCredencialException::con(GeneracionCredencialException::FUERA_DE_PAGINA, 'El QR queda fuera de los límites de la página.', $id);
        }

        return ['id' => $id, 'x' => $x, 'y' => $y, 'size' => $w];
    }
}
