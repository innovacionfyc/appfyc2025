<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Generacion\DatosCredencial;

/**
 * Adaptador entre los modelos de Credential Flow y el motor PDF: combina los datos por participante
 * (nombre_completo, documento) con el snapshot del lote (evento, fecha, intensidad_horaria) y entrega un
 * DatosCredencial. El motor PDF nunca recibe modelos Eloquent.
 */
final class DatosDeParticipante
{
    public static function para(Participante $participante, Lote $lote): DatosCredencial
    {
        $comunes = $lote->datos_comunes ?? [];

        return DatosCredencial::fromArray([
            'nombre_completo' => $participante->nombre_completo,
            'documento' => $participante->documento,
            'evento' => $comunes['evento'] ?? '',
            'fecha' => $comunes['fecha'] ?? '',
            'intensidad_horaria' => $comunes['intensidad_horaria'] ?? '',
        ]);
    }
}
