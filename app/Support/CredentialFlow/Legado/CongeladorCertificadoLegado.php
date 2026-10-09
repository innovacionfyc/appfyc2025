<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;

/**
 * INTERFAZ FUTURA (sin implementación todavía; no hay portal ni persistencia en esta fase): el congelado perezoso.
 *
 * Contrato de servir(): devuelve el PDF congelado de un certificado histórico, generándolo SOLO la primera vez.
 *
 *   sin PDF congelado (pdf_archivo NULL):
 *       1. render(...)        con RendererLegado a partir del snapshot_legado del certificado
 *       2. verificar          sha256/bytes del resultado; ninguna advertencia bloqueante
 *       3. persistir          RutasLegado::certificado($id), escritura atómica (temporal + rename)
 *       4. registrar          pdf_archivo, pdf_hash, pdf_bytes, materializado_at en la MISMA transacción que el cierre
 *       5. servir
 *   con PDF congelado:
 *       1. verificar          que el archivo existe y que bytes y SHA-256 coinciden con los registrados
 *       2. servir el existente (NUNCA se regenera encima: si no coincide, se alerta y se marca el error, no se sobrescribe)
 *
 * Concurrencia: dos accesos simultáneos al mismo certificado deben producir UN solo archivo (bloqueo de fila al materializar).
 * Un fallo de render queda en `intentos_generacion` / `ultimo_error_codigo` (el código de RenderNoPermitido), sin datos personales.
 */
interface CongeladorCertificadoLegado
{
    /** @throws RenderNoPermitido si el certificado no puede generarse; nunca corrige datos */
    public function servir(CertificadoLegado $certificado): ArchivoCongelado;
}
