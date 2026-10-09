<?php

namespace App\Support\CredentialFlow\Legado;

/**
 * `utf8_decode()` del sistema viejo (PHP 7.4), reproducido EXACTAMENTE: UTF-8 → ISO-8859-1 de un byte por carácter, con
 * «?» para lo que no cabe (código > U+00FF) y para cada secuencia UTF-8 inválida. Es port de la rutina de decodificación de
 * PHP 7.4 (ext/standard/html.c, get_next_char para UTF-8) y se verificó contra utf8_decode() REAL de PHP 7.4.33.
 *
 * NO «mejora» nada: «Ł» sale «?», «€» sale «?», «–» sale «?» igual que en el certificado de entonces. La diferencia con el
 * sistema viejo es que aquí queda DETECTADO: se devuelve la lista de lo que no era representable para reportarlo.
 *
 * Nota: mb_convert_encoding(…, 'ISO-8859-1') NO es equivalente (trata distinto las secuencias inválidas), por eso se porta.
 */
final class TextoLegado
{
    /**
     * @return array{bytes:string,no_representables:list<int>,invalidos:int}
     *                                                                       bytes: la cadena ISO-8859-1 (lo que recibía FPDF); no_representables: puntos de código distintos > U+00FF (ordenados);
     *                                                                       invalidos: secuencias UTF-8 inválidas encontradas
     */
    public static function utf8Decode(string $texto): array
    {
        $n = strlen($texto);
        $pos = 0;
        $bytes = '';
        $no = [];
        $invalidos = 0;

        while ($pos < $n) {
            [$cp, $pos] = self::siguiente($texto, $n, $pos);
            if ($cp === null) {
                $bytes .= '?';
                $invalidos++;
            } elseif ($cp > 0xFF) {
                $bytes .= '?';
                $no[$cp] = true;
            } else {
                $bytes .= chr($cp);
            }
        }
        $no = array_keys($no);
        sort($no);

        return ['bytes' => $bytes, 'no_representables' => $no, 'invalidos' => $invalidos];
    }

    /** @return array{0:?int,1:int} punto de código (null si es inválido) y nueva posición */
    private static function siguiente(string $s, int $n, int $pos): array
    {
        $c = ord($s[$pos]);
        $avail = $n - $pos;
        $trail = fn (int $i): bool => ($b = ord($s[$i])) >= 0x80 && $b <= 0xBF;
        $lead = fn (int $i): bool => ($b = ord($s[$i])) < 0x80 || ($b >= 0xC2 && $b <= 0xF4);

        if ($c < 0x80) {
            return [$c, $pos + 1];
        }
        if ($c < 0xC2) {
            return [null, $pos + 1];
        }
        if ($c < 0xE0) {
            if ($avail < 2) {
                return [null, $pos + 1];
            }
            if (! $trail($pos + 1)) {
                return [null, $pos + ($lead($pos + 1) ? 1 : 2)];
            }
            $cp = (($c & 0x1F) << 6) | (ord($s[$pos + 1]) & 0x3F);

            return $cp < 0x80 ? [null, $pos + 2] : [$cp, $pos + 2];
        }
        if ($c < 0xF0) {
            if ($avail < 3 || ! $trail($pos + 1) || ! $trail($pos + 2)) {
                return [null, $pos + self::avance($avail, $lead, $pos)];
            }
            $cp = (($c & 0x0F) << 12) | ((ord($s[$pos + 1]) & 0x3F) << 6) | (ord($s[$pos + 2]) & 0x3F);

            return ($cp < 0x800 || ($cp >= 0xD800 && $cp <= 0xDFFF)) ? [null, $pos + 3] : [$cp, $pos + 3];
        }
        if ($c < 0xF5) {
            if ($avail < 4 || ! $trail($pos + 1) || ! $trail($pos + 2) || ! $trail($pos + 3)) {
                return [null, $pos + self::avance($avail, $lead, $pos, 4)];
            }
            $cp = (($c & 0x07) << 18) | ((ord($s[$pos + 1]) & 0x3F) << 12) | ((ord($s[$pos + 2]) & 0x3F) << 6) | (ord($s[$pos + 3]) & 0x3F);

            return ($cp < 0x10000 || $cp > 0x10FFFF) ? [null, $pos + 4] : [$cp, $pos + 4];
        }

        return [null, $pos + 1];
    }

    /** Cuántos bytes descarta PHP ante una secuencia truncada o con continuaciones inválidas (3 o 4 bytes). */
    private static function avance(int $avail, \Closure $lead, int $pos, int $largo = 3): int
    {
        if ($avail < 2 || $lead($pos + 1)) {
            return 1;
        }
        if ($avail < 3 || $lead($pos + 2)) {
            return 2;
        }
        if ($largo === 3) {
            return 3;
        }

        return ($avail < 4 || $lead($pos + 3)) ? 3 : 4;
    }
}
