<?php

namespace App\Support\CredentialFlow\Rehearsal;

use Illuminate\Support\Facades\DB;
use RuntimeException;

/**
 * ¿La `APP_KEY` actual es la misma con la que se generaron los datos derivados de esta BD? (Fase 11A). La huella de la clave se registra en los `totales` de la corrida de
 * migración histórica (`cf_migraciones_corridas`, sin schema nuevo). Estados: `coincide` · `no_coincide` (NO-GO) · `sin_registro` (corrida anterior a 11A) · `sin_datos`.
 */
final class GuardiaClave
{
    public const CODIGO = 'APP_KEY_NO_COINCIDE_CON_DATOS_DERIVADOS';

    /** @return array{estado:string,corrida_id:?int,huella_actual:string,huella_registrada:?string} */
    public static function estado(): array
    {
        $actual = HuellaClave::actual();
        $corrida = DB::table('cf_migraciones_corridas')->where('tipo', 'legado_evaluaciones')->where('estado', 'completada')->orderByDesc('id')->first(['id', 'totales']);
        if ($corrida === null) {
            return ['estado' => 'sin_datos', 'corrida_id' => null, 'huella_actual' => $actual, 'huella_registrada' => null];
        }
        $registrada = (json_decode((string) $corrida->totales, true) ?: [])['huella_clave'] ?? null;
        $estado = $registrada === null ? 'sin_registro' : (hash_equals((string) $registrada, $actual) ? 'coincide' : 'no_coincide');

        return ['estado' => $estado, 'corrida_id' => (int) $corrida->id, 'huella_actual' => $actual, 'huella_registrada' => $registrada === null ? null : (string) $registrada];
    }

    /** Falla solo ante una clave DISTINTA a la registrada (sin registro no bloquea: lo señala el verificador del rehearsal). @throws RuntimeException */
    public static function exigirCoincidencia(): void
    {
        if (self::estado()['estado'] === 'no_coincide') {
            throw new RuntimeException(self::CODIGO);
        }
    }
}
