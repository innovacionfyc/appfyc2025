<?php

namespace App\Support\CredentialFlow\Legado;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\MigracionCorrida;
use Closure;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
use RuntimeException;

/**
 * Congelado PEREZOSO del PDF de un certificado histórico (implementa CongeladorCertificadoLegado).
 *
 *   ya congelado  → verifica archivo, tamaño y SHA-256 y devuelve el existente (NUNCA regenera ni sobrescribe)
 *   sin PDF       → elegibilidad → plantilla → RendererLegado → valida el PDF → escribe a un temporal y lo mueve con rename →
 *                   guarda ruta, SHA-256 y bytes → devuelve
 *
 * Concurrencia: todo ocurre dentro de una transacción con `lockForUpdate` sobre la fila del certificado canónico; un segundo proceso
 * espera, ve el PDF ya congelado y lo reutiliza. El render es determinista: si un intento anterior dejó el archivo sin metadatos
 * (caída entre el rename y el commit), solo se ADOPTA si es byte a byte idéntico; en cualquier otro caso es una inconsistencia.
 *
 * Política canónica (A): el PDF vive solo en el canónico; los duplicados idénticos (`duplicado_consolidado`) resuelven a él.
 * Un PDF congelado es inmutable: una corrección se hace con una emisión moderna (`reemplazado_por_emision_id`), jamás tocando este.
 * Storage: disco privado; ruta credential-flow/legado/certificados/{id}/certificado.pdf (nunca public/ ni URL directa).
 */
final class CongeladorLegado implements CongeladorCertificadoLegado
{
    /** Solo para tests: se ejecuta con la fila bloqueada, antes de renderizar (permite simular un segundo proceso). */
    public static ?Closure $despuesDeBloquear = null;

    /** Solo para tests: se ejecuta tras escribir el archivo y antes de guardar los metadatos (simula una caída). */
    public static ?Closure $antesDeConfirmar = null;

    public function __construct(
        private readonly RendererLegado $renderer,
        private readonly ResolutorPlantillaLegado $plantillas,
        private readonly string $disco = 'local',
        private readonly ?CodigoHistorico $codigos = null,
    ) {}

    public function servir(CertificadoLegado $certificado): ArchivoCongelado
    {
        $c = ElegibilidadLegado::canonico($certificado) ?? throw new CongeladoNoPermitido(ElegibilidadLegado::SIN_CANONICO);
        if (($motivo = ElegibilidadLegado::motivo($c)) !== null) {
            throw new CongeladoNoPermitido($motivo);
        }
        // Camino rápido (sin transacción): ya congelado → verificar y devolver. Nunca se asigna otro código: si falta el del PDF es un
        // estado imposible y se corta con un error controlado.
        if ($c->pdf_archivo !== null) {
            $this->exigirCodigoDelPdf($c);

            return $this->verificar($c, false);
        }

        // El código se asigna y se CONFIRMA (commit propio) ANTES de renderizar; si el render falla, el número queda reservado y no se recicla.
        $codigo = $this->codigos()->resolverOAsignar($c);

        try {
            return DB::transaction(fn () => $this->generar($c->id, $codigo));
        } catch (RenderNoPermitido $e) {
            // Queda constancia del intento fallido (código técnico, sin datos personales).
            DB::table('cf_certificados_legado')->where('id', $c->id)->update(['intentos_generacion' => DB::raw('intentos_generacion + 1'), 'ultimo_error_codigo' => $e->codigo, 'updated_at' => now()]);

            throw $e;
        }
    }

    private function generar(int $id, string $codigo): ArchivoCongelado
    {
        $f = CertificadoLegado::query()->whereKey($id)->lockForUpdate()->firstOrFail();

        if (($motivo = ElegibilidadLegado::motivo($f)) !== null) {
            throw new CongeladoNoPermitido($motivo);
        }
        if ($f->pdf_archivo !== null) {
            $this->exigirCodigoDelPdf($f);

            return $this->verificar($f, false);   // otro proceso lo congeló mientras esperábamos
        }
        // El par ya tiene su código confirmado; aquí solo se comprueba que sigue siendo el mismo (nunca se asigna dentro del render).
        if (($this->codigos()->resolver($f)['codigo'] ?? null) !== $codigo) {
            throw new CodigoHistoricoException(CodigoHistoricoException::CODIGOS_EN_CONFLICTO, 'el código del par cambió durante la generación');
        }
        if (self::$despuesDeBloquear !== null) {
            (self::$despuesDeBloquear)($f);
        }

        $resultado = $this->renderer->render(new SolicitudRender(
            $this->plantillas->resolver($f), (string) $f->nombre_completo, $f->tipo_documento, $f->documento, $codigo, $this->fechaCreacion($f),
        ));
        $this->validarPdf($resultado);

        $ruta = RutasLegado::certificado($f->id);
        $this->escribirAtomico($ruta, $resultado);

        if (self::$antesDeConfirmar !== null) {
            (self::$antesDeConfirmar)($f, $ruta);
        }

        $f->forceFill([
            'pdf_archivo' => $ruta, 'pdf_hash' => $resultado->sha256, 'pdf_bytes' => $resultado->bytes, 'materializado_at' => now(), 'ultimo_error_codigo' => null,
        ])->save();

        return new ArchivoCongelado($ruta, $resultado->sha256, $resultado->bytes, true);
    }

    private function codigos(): CodigoHistorico
    {
        return $this->codigos ?? new CodigoHistorico;
    }

    /** Estado imposible: PDF congelado sin ningún código (ni legado ni de Credential Flow). Nunca se inventa uno distinto al impreso. */
    private function exigirCodigoDelPdf(CertificadoLegado $f): void
    {
        if ($this->codigos()->resolver($f) === null) {
            Log::error('Credential Flow: PDF histórico congelado sin código', ['certificado_id' => $f->id]);

            throw new CodigoHistoricoException(CodigoHistoricoException::PDF_SIN_CODIGO);
        }
    }

    /** Comprueba que el PDF congelado sigue siendo exactamente el registrado. Si no, error controlado; nunca regenera. */
    public function verificar(CertificadoLegado $f, bool $recienGenerado = false): ArchivoCongelado
    {
        $disco = Storage::disk($this->disco);
        $motivo = match (true) {
            $f->pdf_archivo !== RutasLegado::certificado($f->id) => 'ruta',
            ! $disco->exists($f->pdf_archivo) => 'ausente',
            $disco->size($f->pdf_archivo) !== (int) $f->pdf_bytes => 'bytes',
            ! hash_equals((string) $f->pdf_hash, (string) hash_file('sha256', $disco->path($f->pdf_archivo))) => 'sha256',
            default => null,
        };
        if ($motivo !== null) {
            // Alerta técnica sin datos personales (id interno y motivo).
            Log::error('Credential Flow: PDF histórico inconsistente', ['certificado_id' => $f->id, 'motivo' => $motivo]);

            throw new PdfHistoricoInconsistente($motivo);
        }

        return new ArchivoCongelado($f->pdf_archivo, (string) $f->pdf_hash, (int) $f->pdf_bytes, $recienGenerado);
    }

    /** Ruta física del PDF ya verificado (para servirlo desde una ruta autenticada). */
    public function rutaFisica(ArchivoCongelado $archivo): string
    {
        return Storage::disk($this->disco)->path($archivo->ruta);
    }

    // ── Internos ──────────────────────────────────────────────────────────────────────────────────────────

    private function validarPdf(ResultadoRender $r): void
    {
        $ok = str_starts_with($r->pdf, '%PDF-') && str_ends_with(rtrim($r->pdf), '%%EOF') && $r->bytes > 0
            && $r->bytes === strlen($r->pdf) && hash('sha256', $r->pdf) === $r->sha256 && ($r->metadata['paginas'] ?? 0) === 1;
        if (! $ok) {
            throw new RenderNoPermitido(RenderNoPermitido::ERROR_FPDF, 'El PDF generado no pasó la validación (cabecera, fin de archivo, tamaño, SHA-256 o páginas).');
        }
    }

    /** Escritura temporal + rename. Nunca sobrescribe: si ya hay un archivo en la ruta final, solo se adopta si es idéntico. */
    private function escribirAtomico(string $ruta, ResultadoRender $r): void
    {
        $disco = Storage::disk($this->disco);

        if ($disco->exists($ruta)) {
            if ($disco->size($ruta) === $r->bytes && hash_equals($r->sha256, (string) hash_file('sha256', $disco->path($ruta)))) {
                return;   // resto de un intento anterior sin metadatos: idéntico (el render es determinista)
            }

            throw new PdfHistoricoInconsistente('archivo_inesperado');
        }

        $temporal = dirname($ruta).'/.certificado-'.bin2hex(random_bytes(6)).'.tmp';
        $disco->put($temporal, $r->pdf);

        try {
            $fisico = $disco->path($temporal);
            if ($disco->size($temporal) !== $r->bytes || ! hash_equals($r->sha256, (string) hash_file('sha256', $fisico))) {
                throw new RuntimeException('El temporal no coincide con lo renderizado.');
            }
            if (function_exists('fsync') && ($h = @fopen($fisico, 'r+b')) !== false) {
                @fsync($h);
                fclose($h);
            }
            if (! $disco->move($temporal, $ruta)) {
                throw new RuntimeException('No se pudo mover el PDF a su ruta final.');
            }
        } catch (\Throwable $e) {
            $disco->delete($temporal);

            throw $e;
        }
    }

    /**
     * Fecha de creación del PDF, estable por certificado: la del snapshot si la corrida la registró; si no, el inicio de la corrida de
     * migración (o la creación de la fila). Nunca «ahora»: el mismo certificado produce siempre los mismos bytes.
     */
    private function fechaCreacion(CertificadoLegado $f): DateTimeImmutable
    {
        $corrida = $f->corrida_id === null ? null : MigracionCorrida::find($f->corrida_id);
        $texto = $corrida?->totales['huellas']['snapshot_tomado_at'] ?? $corrida?->iniciado_at?->toDateTimeString() ?? $f->created_at?->toDateTimeString() ?? '1970-01-01 00:00:00';

        return new DateTimeImmutable($texto, new DateTimeZone('UTC'));
    }
}
