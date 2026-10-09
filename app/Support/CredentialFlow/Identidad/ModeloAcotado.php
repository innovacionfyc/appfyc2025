<?php

namespace App\Support\CredentialFlow\Identidad;

/**
 * Regla PURA del Modelo 1 acotado (10B-3B-1): qué correos puede abrir una `misma_persona` SIN una autorización de correo adicional. La usan el resolver
 * (autorización real) y la pantalla/auditoría de 10B-3A (qué se muestra y qué se registra), para que no existan dos definiciones.
 *
 * Un correo es CANDIDATO de un scope si existe históricamente en al menos un grupo del scope y en NINGÚN grupo del mismo documento fuera de él (si escapa,
 * solo una autorización explícita puede abrirlo). Un scope concede vía automática solo si tiene EXACTAMENTE UN candidato y no es masivo.
 */
final class ModeloAcotado
{
    /** Con este número de certificados (o más) el scope es masivo: la misma persona sola no concede vía. */
    public const UMBRAL_MASIVO = EvidenciaIdentidad::UMBRAL_MASIVO;

    /**
     * @param  array<string,list<string>>  $correosPorGrupo  grupo_hash → correos (HMAC o cualquier clave estable) que tiene históricamente
     * @param  list<string>  $scope  grupos de la misma persona
     * @return list<string> candidatos ordenados
     */
    public static function candidatos(array $correosPorGrupo, array $scope): array
    {
        $gruposDe = [];
        foreach ($correosPorGrupo as $g => $correos) {
            foreach ($correos as $c) {
                $gruposDe[$c][$g] = true;
            }
        }
        $candidatos = [];
        foreach ($scope as $g) {
            foreach ($correosPorGrupo[$g] ?? [] as $c) {
                if (array_diff(array_keys($gruposDe[$c]), $scope) === []) {
                    $candidatos[$c] = true;
                }
            }
        }
        $lista = array_keys($candidatos);
        sort($lista);

        return $lista;
    }

    public static function masivo(int $certificados): bool
    {
        return $certificados >= self::UMBRAL_MASIVO;
    }

    /**
     * ¿La misma persona, por sí sola, concede vía OTP al correo `$correo`? (no mira personas distintas ni integridad: eso es del resolver).
     *
     * @param  array<string,list<string>>  $correosPorGrupo
     * @param  list<string>  $scope
     * @return array{concede:bool,motivo:?string}
     */
    public static function concede(array $correosPorGrupo, array $scope, string $correo, int $certificadosScope): array
    {
        $c = self::candidatos($correosPorGrupo, $scope);
        if (! in_array($correo, $c, true)) {
            return ['concede' => false, 'motivo' => 'correo_fuera_del_scope'];
        }
        if (self::masivo($certificadosScope)) {
            return ['concede' => false, 'motivo' => 'scope_masivo_requiere_correo_autorizado'];
        }
        if (count($c) !== 1) {
            return ['concede' => false, 'motivo' => 'varios_candidatos_requieren_correo_autorizado'];
        }

        return ['concede' => true, 'motivo' => null];
    }
}
