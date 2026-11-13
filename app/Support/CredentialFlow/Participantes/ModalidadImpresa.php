<?php

namespace App\Support\CredentialFlow\Participantes;

/**
 * Regla ÚNICA de cómo se imprime la modalidad de una participación. Si su modalidad efectiva (la propia de la persona o, si no tiene, la general de la base)
 * es VIRTUAL, el certificado dice exactamente «Virtual -Sincrónico y Remoto»: sin ciudad, sin prefijo ni sufijo de la plantilla, tanto en «modalidad» como en
 * «modalidad_ciudad». Cualquier otra modalidad (presencial, híbrida…) se imprime como hasta ahora (ModalidadCiudad para la línea con ciudad).
 *
 * Solo actúa al GENERAR: los certificados ya emitidos conservan sus datos congelados y su PDF. El motor omite los afijos únicamente cuando el valor congelado
 * es EXACTAMENTE el texto oficial (lo que guarda una emisión hecha con esta regla), así que un snapshot anterior («Virtual», «Virtual, Bogotá»…) se dibuja igual
 * que cuando se emitió.
 */
final class ModalidadImpresa
{
    /** Redacción oficial aprobada: espacio antes del guion y ninguno después. */
    public const VIRTUAL = 'Virtual -Sincrónico y Remoto';

    /** Campos que imprimen la modalidad. */
    public const CAMPOS = ['modalidad', 'modalidad_ciudad'];

    /**
     * ¿Es virtual esta modalidad escrita libremente? La primera palabra debe ser «virtual» (sin distinguir mayúsculas, tildes ni puntuación) y no puede
     * nombrar también otra modalidad presencial o híbrida («Virtual y presencial» no es virtual). «Virtualidad», «Remoto», «Híbrido» o «Presencial» no lo son.
     */
    public static function esVirtual(?string $modalidad): bool
    {
        $palabras = self::palabras($modalidad);
        if (($palabras[0] ?? null) !== 'virtual') {
            return false;
        }

        return array_intersect($palabras, ['presencial', 'semipresencial', 'hibrido', 'hibrida']) === [];
    }

    /**
     * Valores impresos de la modalidad ya resuelta (la propia manda sobre la general) y de la línea con ciudad.
     *
     * @return array{modalidad:?string, modalidad_ciudad:?string}
     */
    public static function resolver(?string $modalidad, ?string $ciudad): array
    {
        if (self::esVirtual($modalidad)) {
            return ['modalidad' => self::VIRTUAL, 'modalidad_ciudad' => self::VIRTUAL];
        }

        return ['modalidad' => $modalidad, 'modalidad_ciudad' => ModalidadCiudad::componer($modalidad, $ciudad)];
    }

    /** ¿El motor debe imprimir este valor tal cual, sin el prefijo ni el sufijo del elemento? Solo el texto oficial exacto en un campo de modalidad. */
    public static function sinAfijos(?string $campo, string $valor): bool
    {
        return in_array($campo, self::CAMPOS, true) && $valor === self::VIRTUAL;
    }

    /**
     * ¿Un certificado ya emitido imprime la redacción oficial? Todos los elementos de modalidad de su diseño congelado tienen como valor congelado el texto
     * oficial y no llevan afijos (con afijos solo es exacto si se emitió con esta regla, que los omite al dibujar). Sin elementos de modalidad: no aplica (false).
     *
     * @param  array<string,mixed>  $diseno
     * @param  array<string,mixed>  $datos
     */
    public static function imprimeOficial(array $diseno, array $datos): bool
    {
        $hay = false;
        foreach (($diseno['elements'] ?? []) as $el) {
            $campo = $el['field'] ?? null;
            if (! in_array($campo, self::CAMPOS, true)) {
                continue;
            }
            $hay = true;
            if (($datos[$campo] ?? null) !== self::VIRTUAL) {
                return false;
            }
        }

        return $hay;
    }

    /** @return list<string> palabras en minúscula, sin tildes ni puntuación */
    private static function palabras(?string $s): array
    {
        $s = mb_strtolower(trim((string) $s), 'UTF-8');
        $nfd = \Normalizer::normalize($s, \Normalizer::FORM_D);
        $s = (string) preg_replace('/\p{Mn}+/u', '', $nfd === false ? $s : $nfd);
        $s = trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $s));

        return $s === '' ? [] : explode(' ', $s);
    }
}
