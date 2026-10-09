<?php

namespace Tests\Feature\CredentialFlow\Historico;

use Tests\Feature\CredentialFlow\CredentialFlowTestCase;
use Tests\Feature\CredentialFlow\Historico\Concerns\MigraHistoricoSintetico;
use Tests\Feature\CredentialFlow\StagingEv\FixturesStagingEv;

/**
 * Base de las pruebas del histórico (Fase 5/6): el staging SINTÉTICO se migra de verdad con el MigradorHistorico, así las
 * pantallas se prueban sobre exactamente lo que produce la migración (eventos, duplicados, conflictivos, documentos en revisión,
 * plantillas faltantes/inválidas/candidata, correos y descargas). Ningún dato pertenece a una persona real.
 *
 * Eventos (id del origen → nombre): 1 Alfa 2024 (plantilla ok) · 2 Beta 2025 (imagen faltante) · 3 Gamma (sin año; faltante con candidata)
 * · 4 Delta 2023 (extensión inválida) · 5 Epsilon 2026 (sin imagen) · 6 Zeta (año ambiguo; faltante).
 */
abstract class HistoricoTestCase extends CredentialFlowTestCase
{
    use FixturesStagingEv, MigraHistoricoSintetico;

    protected function setUp(): void
    {
        parent::setUp();
        $this->prepararHistorico();
    }
}
