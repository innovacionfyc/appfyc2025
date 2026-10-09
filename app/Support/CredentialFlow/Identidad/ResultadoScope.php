<?php

namespace App\Support\CredentialFlow\Identidad;

use App\Support\CredentialFlow\Portal\Hmac;

/**
 * Resultado del resolver de identidad (10B-3B-1). `motivoInterno` es SOLO técnico (logs y pruebas): nunca se muestra al público ni sale en una respuesta.
 *
 *  - `grupos`: null salvo en `alcance_aprobado`, donde es la lista ORDENADA y sin duplicados de `grupo_hash` técnicos.
 *  - `grupoHistorico`: en `alcance_historico_normal`, el `grupo` que ya devolvía `AccesoPortal::alcance` (null = documento completo).
 */
final class ResultadoScope
{
    public const HISTORICO_NORMAL = 'alcance_historico_normal';

    public const APROBADO = 'alcance_aprobado';

    public const AMBIGUO = 'ambiguo';

    public const BLOQUEADO = 'bloqueado';

    public const SOPORTE = 'soporte';

    public const CONFLICTO = 'conflicto_decisiones';

    public const SIN_VIA = 'sin_via';

    public const VERSION_HASH = 'v1';

    /**
     * @param  list<string>|null  $grupos
     * @param  list<int>  $decisionIds
     * @param  list<int>  $aprobacionIds  segundas aprobaciones (doble control, 10B-3C-3) que sustentan un scope MASIVO
     */
    private function __construct(
        public readonly string $tipo,
        public readonly ?array $grupos = null,
        public readonly ?string $grupoHistorico = null,
        public readonly array $decisionIds = [],
        public readonly ?string $scopeHash = null,
        public readonly ?string $motivoInterno = null,
        public readonly bool $masivo = false,
        public readonly array $aprobacionIds = [],
    ) {}

    public static function historico(?string $grupo): self
    {
        return new self(self::HISTORICO_NORMAL, null, $grupo);
    }

    /**
     * @param  list<string>  $grupos
     * @param  list<int>  $decisionIds
     */
    public static function aprobado(string $documentoHash, string $correoHmac, array $grupos, array $decisionIds, bool $masivo = false, array $aprobacionIds = []): self
    {
        $grupos = array_values(array_unique($grupos));
        sort($grupos);
        $decisionIds = array_values(array_unique(array_map('intval', $decisionIds)));
        sort($decisionIds);
        $aprobacionIds = array_values(array_unique(array_map('intval', $aprobacionIds)));
        sort($aprobacionIds);

        return new self(self::APROBADO, $grupos, null, $decisionIds, self::hash($documentoHash, $correoHmac, $grupos, $decisionIds, $aprobacionIds), null, $masivo, $aprobacionIds);
    }

    public static function sin(string $tipo, string $motivo): self
    {
        return new self($tipo, null, null, [], null, $motivo);
    }

    /**
     * HMAC versionado del scope. El orden de los grupos y de las decisiones NO cambia el resultado. Las segundas aprobaciones (solo scope masivo) forman parte
     * del contrato: si una se revoca o cambia, el hash cambia y el OTP o la sesión anteriores dejan de ser el scope vigente. Sin aprobaciones, el hash es el de 3B.
     *
     * @param  list<string>  $grupos
     * @param  list<int>  $decisionIds
     * @param  list<int>  $aprobacionIds
     */
    public static function hash(string $documentoHash, string $correoHmac, array $grupos, array $decisionIds, array $aprobacionIds = []): string
    {
        sort($grupos);
        sort($decisionIds);
        sort($aprobacionIds);
        $carga = [self::VERSION_HASH, $documentoHash, $correoHmac, array_values($grupos), array_values($decisionIds)];
        if ($aprobacionIds !== []) {
            $carga[] = ['aprobaciones', array_values($aprobacionIds)];
        }

        return Hmac::de('identidad_scope', json_encode($carga, JSON_THROW_ON_ERROR));
    }

    public function esHistorico(): bool
    {
        return $this->tipo === self::HISTORICO_NORMAL;
    }

    public function esAprobado(): bool
    {
        return $this->tipo === self::APROBADO;
    }

    /**
     * ¿Un scope aprobado puede abrir una sesión REAL? Un scope pequeño, sí. Uno MASIVO (≥ 100 certificados) solo con una segunda aprobación vigente (doble control,
     * 10B-3C-3) Y el interruptor masivo encendido: defensa en profundidad (el resolver ya lo exige antes de devolver un scope masivo).
     */
    public function aplicable(): bool
    {
        return $this->esAprobado() && (! $this->masivo || ($this->aprobacionIds !== [] && IdentidadFlags::masaHabilitada()));
    }

    /** ¿Puede emitirse un OTP con este resultado? */
    public function habilita(): bool
    {
        return $this->esHistorico() || $this->esAprobado();
    }
}
