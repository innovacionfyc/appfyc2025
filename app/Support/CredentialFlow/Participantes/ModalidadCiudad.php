<?php

namespace App\Support\CredentialFlow\Participantes;

/**
 * Valor del campo dinámico DERIVADO «modalidad_ciudad»: la modalidad (la de la persona o, si no tiene, la general de la base) + «, » + la ciudad de la base,
 * en una sola línea. Es la ÚNICA regla de composición: la usan los datos del certificado (DatosDeParticipante) y la vista previa del asistente.
 *
 *  - La modalidad se imprime LITERAL (no se normaliza ni se traduce: «Virtual sincrónico» sigue siendo «Virtual sincrónico»). Solo se limpian los espacios
 *    sobrantes y un separador colgado al final («Presencial,» → «Presencial»).
 *  - Ciudad vacía → solo la modalidad (sin coma sobrante).
 *  - Modalidad vacía → null (el campo no tiene valor): la emisión se bloquea antes de generar, igual que con «modalidad». Nunca se imprime «, Bogotá» ni la
 *    ciudad sola en una línea que dice ser la modalidad.
 *  - Si la modalidad ya nombra la ciudad (sin distinguir mayúsculas, tildes ni puntuación, y como palabras completas) no se repite. También si nombra la
 *    ciudad sin uno de los sufijos administrativos reconocidos (D.C., D.T. y C., D.T.C.H.). No hay otras equivalencias geográficas.
 */
final class ModalidadCiudad
{
    public const SEPARADOR = ', ';

    public static function componer(?string $modalidad, ?string $ciudad): ?string
    {
        $m = self::limpiar((string) $modalidad);
        // Un separador colgado al final de la modalidad o al principio de la ciudad no se duplica («Presencial,» / «, Bogotá»).
        $m = (string) preg_replace('/[\s,;:·\-–—]+$/u', '', $m);
        $c = (string) preg_replace('/^[\s,;:·\-–—]+|[\s,;:·\-–—]+$/u', '', self::limpiar((string) $ciudad));
        if ($m === '') {
            return null;
        }
        if ($c === '' || self::incluye($m, $c)) {
            return $m;
        }

        return $m.self::SEPARADOR.$c;
    }

    /**
     * Sufijos administrativos que se reconocen al FINAL del nombre de la ciudad configurada, escritos sin espacios ni puntos (así «D.C.», «D. C.» y «DC»
     * son lo mismo). Lista cerrada y explícita: cualquier otra sigla se trata como parte del nombre. Nunca se quitan de la modalidad.
     */
    private const SUFIJOS_ADMINISTRATIVOS = [
        'dc',     // Distrito Capital (Bogotá D.C.)
        'dtyc',   // Distrito Turístico y Cultural (Cartagena de Indias D.T. y C.)
        'dtch',   // Distrito Turístico, Cultural e Histórico (Santa Marta D.T.C.H.)
    ];

    /**
     * ¿La modalidad ya nombra la ciudad? Comparación sin mayúsculas, sin tildes, sin puntuación y por palabras completas (la frase de la ciudad debe
     * aparecer entera y seguida: «San José» no es «San José del Guaviare», «Bogotano» no es «Bogotá»). Si la ciudad termina en un sufijo administrativo
     * reconocido, también basta con que la modalidad nombre la ciudad sin ese sufijo («Presencial, Bogotá» ya nombra «Bogotá D.C.»).
     */
    public static function incluye(string $modalidad, string $ciudad): bool
    {
        $m = self::clave($modalidad);
        foreach (self::formasDeLaCiudad(self::clave($ciudad)) as $c) {
            if (preg_match('/(?:^| )'.preg_quote($c, '/').'(?: |$)/u', $m) === 1) {
                return true;
            }
        }

        return false;
    }

    /**
     * La ciudad tal cual (normalizada) y, si termina en un sufijo administrativo reconocido, también sin él. El sufijo solo cuenta si son las ÚLTIMAS
     * palabras, todas de una o dos letras (iniciales), y si antes queda un nombre: «D.C.» sola no es un sufijo.
     *
     * @return list<string>
     */
    private static function formasDeLaCiudad(string $c): array
    {
        if ($c === '') {
            return [];
        }
        $palabras = explode(' ', $c);
        for ($k = 1; $k <= min(4, count($palabras) - 1); $k++) {
            $cola = array_slice($palabras, -$k);
            if (in_array(implode('', $cola), self::SUFIJOS_ADMINISTRATIVOS, true) && collect($cola)->every(fn ($p) => mb_strlen($p) <= 2)) {
                return [$c, implode(' ', array_slice($palabras, 0, -$k))];
            }
        }

        return [$c];
    }

    private static function limpiar(string $s): string
    {
        return trim((string) preg_replace('/\s+/u', ' ', $s));
    }

    private static function clave(string $s): string
    {
        $nfd = \Normalizer::normalize(mb_strtolower($s, 'UTF-8'), \Normalizer::FORM_D);
        $sinTildes = (string) preg_replace('/\p{Mn}+/u', '', $nfd === false ? mb_strtolower($s, 'UTF-8') : $nfd);

        return trim((string) preg_replace('/[^\p{L}\p{N}]+/u', ' ', $sinTildes));
    }
}
