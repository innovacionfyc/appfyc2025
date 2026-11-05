<?php

namespace App\Support\CredentialFlow\Verificacion;

use App\Support\CredentialFlow\Qa\ServicioQa;

/**
 * Naturaleza de una emisión según el DOCUMENTO QUE SE EMITIÓ: su diseño congelado (cf_emisiones.diseno_snapshot), que no cambia después.
 *  - PRUEBA_INTERNA: el diseño lleva el marcador institucional (ServicioQa::esMarcadorQa: un texto fijo que es exactamente la advertencia).
 *  - OFICIAL: el diseño es legible y no lo lleva.
 *  - INDETERMINADA: el diseño falta o está dañado; quien lo use no debe afirmar validez.
 * No mira las marcas vivas del participante ni de la plantilla (cambiarlas no altera lo ya emitido), ni el estado: la revocación se decide antes y prevalece.
 */
final class ClasificacionEmision
{
    public const OFICIAL = 'oficial';

    public const PRUEBA_INTERNA = 'prueba_interna';

    public const INDETERMINADA = 'indeterminada';

    /** @param  mixed  $diseno  el snapshot tal como viene de la base (JSON) o ya decodificado */
    public static function deDiseno(mixed $diseno): string
    {
        if (is_string($diseno)) {
            $diseno = json_decode($diseno, true);
        }
        $elementos = is_array($diseno) ? ($diseno['elements'] ?? null) : null;
        if (! is_array($elementos) || $elementos === [] || ! array_is_list($elementos)) {
            return self::INDETERMINADA;
        }
        foreach ($elementos as $el) {
            if (! is_array($el)) {
                return self::INDETERMINADA;
            }
        }
        foreach ($elementos as $el) {
            if (ServicioQa::esMarcadorQa($el)) {
                return self::PRUEBA_INTERNA;
            }
        }

        return self::OFICIAL;
    }
}
