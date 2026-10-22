<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\CamposDinamicos;
use App\Support\CredentialFlow\Generacion\DatosCredencial;

/**
 * Adaptador entre los modelos de Credential Flow y el motor PDF: combina los datos por participante
 * (nombre_completo, documento) con el snapshot del lote (evento, fecha, intensidad_horaria y, si existen, modalidad, ciudad y textos configurables) y entrega un
 * DatosCredencial. El motor PDF nunca recibe modelos Eloquent.
 */
final class DatosDeParticipante
{
    public static function para(Participante $participante, Lote $lote): DatosCredencial
    {
        $comunes = $lote->datos_comunes ?? [];

        $datos = [
            'nombre_completo' => $participante->nombre_completo,
            'documento' => self::documentoParaImprimir((string) $participante->documento),
            'evento' => $comunes['evento'] ?? '',
            'fecha' => $comunes['fecha'] ?? '',
            // La del evento/base es la predeterminada; una excepción aprobada por participante la reemplaza. Nunca se inventan horas.
            'intensidad_horaria' => ($participante->intensidad_horaria ?? '') !== '' ? (string) $participante->intensidad_horaria : ($comunes['intensidad_horaria'] ?? ''),
        ];

        // Datos opcionales de la base (modalidad, ciudad, textos configurables): solo existen si el administrador los escribió. Nunca se completan ni se vacían.
        foreach (CamposDinamicos::COMUNES_OPCIONALES as $campo) {
            if (is_string($comunes[$campo] ?? null) && $comunes[$campo] !== '') {
                $datos[$campo] = $comunes[$campo];
            }
        }
        // La modalidad de la persona (un mismo Excel puede traer presenciales y virtuales) reemplaza a la de la base, igual que la intensidad individual.
        if (($modalidad = self::modalidadPropia($participante)) !== null) {
            $datos['modalidad'] = $modalidad;
        }

        return DatosCredencial::fromArray($datos);
    }

    /** Modalidad propia de la persona, o null si usa la de la base. */
    public static function modalidadPropia(Participante $participante): ?string
    {
        $m = trim((string) ($participante->modalidad ?? ''));

        return $m === '' ? null : $m;
    }

    /**
     * Cómo se IMPRIME el documento: una cédula de ciudadanía escrita solo con dígitos («C.C. 40343234») sale con puntos de miles («C.C. 40.343.234»).
     * Conserva todos los dígitos (también ceros a la izquierda). No cambia el dato guardado ni su clave (portal/OTP), ni otros tipos de documento
     * (pasaporte, PPT, C.E.…), ni un documento que ya traiga su propio formato.
     */
    public static function documentoParaImprimir(string $documento): string
    {
        if (preg_match('/^C\.C\.\s*(\d{4,})$/u', trim($documento), $m) !== 1) {
            return $documento;
        }

        return 'C.C. '.strrev(implode('.', str_split(strrev($m[1]), 3)));
    }
}
