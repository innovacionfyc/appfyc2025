<?php

namespace App\Support\CredentialFlow\Participantes;

use App\Models\CredentialFlow\Correo;

/**
 * Valida la columna OPCIONAL «correo» de una fila de participantes. Usa la misma normalización y la misma regla de formato que el modelo
 * `Correo` (la que ya usa el portal), de modo que lo que se acepta aquí es exactamente lo que el portal reconocerá después.
 *
 * Nunca completa ni infiere correos: una celda vacía queda sin correo (con aviso). Una celda puede traer varios correos separados por
 * «;», «,» o espacios; el primero es el principal. Un correo con formato inválido es un ERROR de esa fila (no se guarda a medias).
 */
final class ValidadorCorreo
{
    /** Correos por participante: más de unos pocos indica una celda mal llenada. */
    public const MAX_POR_PARTICIPANTE = 5;

    /**
     * @return array{correos: list<string>, errores: list<ErrorFila>, avisos: list<ErrorFila>, vista: string}
     *                                                                                                        correos: direcciones ORIGINALES (sin espacios) válidas y sin repetir; vista: enmascarada, solo para mostrar
     */
    public static function validar(CeldaCruda $celda, int $fila): array
    {
        $errores = [];
        $avisos = [];

        if ($celda->formula) {
            return ['correos' => [], 'errores' => [ErrorFila::error(ErrorFila::FORMULA_NO_PERMITIDA, 'El correo no puede ser una fórmula.', $fila, 'correo')], 'avisos' => [], 'vista' => ''];
        }
        if ($celda->vacia()) {
            return ['correos' => [], 'errores' => [], 'avisos' => [ErrorFila::aviso(ErrorFila::CORREO_VACIO, 'Sin correo: esta persona no podrá entrar al portal de certificados con su documento y correo.', $fila, 'correo')], 'vista' => ''];
        }
        if (! is_string($celda->valor) && ! is_numeric($celda->valor)) {
            return ['correos' => [], 'errores' => [ErrorFila::error(ErrorFila::VALOR_NO_TEXTO, 'El correo debe ser texto.', $fila, 'correo')], 'avisos' => [], 'vista' => ''];
        }

        $texto = trim((string) $celda->valor);
        if ($texto !== '' && in_array($texto[0], ['=', '+', '-', '@'], true)) {
            return ['correos' => [], 'errores' => [ErrorFila::error(ErrorFila::FORMULA_NO_PERMITIDA, 'El correo no puede empezar por = + - o @.', $fila, 'correo', $texto)], 'avisos' => [], 'vista' => ''];
        }

        $correos = [];
        $vistos = [];
        foreach (preg_split('/[;,\s]+/u', $texto, -1, PREG_SPLIT_NO_EMPTY) ?: [] as $token) {
            $normalizado = Correo::normalizar($token);
            if (Correo::estadoDe($normalizado) !== Correo::ESTADO_VALIDO) {
                $errores[] = ErrorFila::error(ErrorFila::CORREO_INVALIDO, 'El correo no tiene un formato válido.', $fila, 'correo', $token);

                continue;
            }
            if (isset($vistos[$normalizado])) {
                continue;
            }
            $vistos[$normalizado] = true;
            $correos[] = $token;
        }
        if (count($correos) > self::MAX_POR_PARTICIPANTE) {
            $errores[] = ErrorFila::error(ErrorFila::CORREO_INVALIDO, 'Hay demasiados correos en la celda (máximo '.self::MAX_POR_PARTICIPANTE.').', $fila, 'correo');
        }
        if ($errores !== []) {
            $correos = [];
        }

        return ['correos' => $correos, 'errores' => $errores, 'avisos' => $avisos, 'vista' => $correos === [] ? '' : self::mascara($correos[0]).(count($correos) > 1 ? ' +'.(count($correos) - 1) : '')];
    }

    /** Máscara para mostrar en la vista previa sin exponer la dirección completa. */
    public static function mascara(string $correo): string
    {
        [$local, $dominio] = array_pad(explode('@', $correo, 2), 2, '');

        return mb_substr($local, 0, 2).'***@'.$dominio;
    }
}
