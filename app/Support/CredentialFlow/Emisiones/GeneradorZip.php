<?php

namespace App\Support\CredentialFlow\Emisiones;

use App\Models\CredentialFlow\Emision;
use App\Models\CredentialFlow\EventoCertificacion;
use App\Models\CredentialFlow\Lote;
use App\Models\CredentialFlow\Participante;
use App\Support\CredentialFlow\Eventos\DescargaHistorica;
use App\Support\CredentialFlow\Eventos\ServicioEventos;
use App\Support\CredentialFlow\Legado\CongeladorLegado;
use App\Support\CredentialFlow\Legado\PdfHistoricoInconsistente;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Throwable;
use ZipArchive;

/**
 * ZIP temporal, bajo demanda, con las emisiones VIGENTES de un lote. No emite nada y no persiste el ZIP.
 *
 * Aquí somos escritores del ZIP: nombres saneados y planos (sin rutas), solo archivos cuya ruta real está dentro
 * del almacén de emisiones y no son enlaces simbólicos, cada PDF verificado (existencia, tamaño y SHA-256),
 * límite de tamaño y borrado del temporal siempre. Los PDFs ya están comprimidos: se guardan con CM_STORE.
 */
final class GeneradorZip
{
    public const LIMITE_BYTES = 200 * 1024 * 1024;

    public const NOMBRE_MAX = 60;

    /** @throws EmisionException */
    public function paraLote(Lote $lote): BinaryFileResponse
    {
        // El ZIP de una base reúne los certificados de los participantes REALES: las pruebas internas (QA) no van en él.
        $emisiones = Emision::where('lote_id', $lote->id)->where('estado', Emision::EMITIDA)
            ->whereNotIn('participante_id', Participante::query()->where('lote_id', $lote->id)->where('es_control_qa', true)->select('id'))
            ->orderBy('id')->get();

        return $this->armar(self::entradasDe($emisiones), 'credenciales-lote-'.$lote->id.'.zip', 'Esta base no tiene certificados vigentes que descargar.');
    }

    /**
     * ZIP de un EVENTO: las emisiones VIGENTES de todas sus bases. Por defecto SOLO las de participantes reales (se excluyen los participantes de control QA,
     * estén en una base mixta o en una base solo de QA); las revocadas nunca entran. Solo contiene certificados NUEVOS (Credential Flow), no los históricos.
     * Mismas garantías que `paraLote` (nombres planos y saneados, PDF verificado, límite de tamaño, temporal borrado).
     *
     * @throws EmisionException
     */
    public function paraEvento(EventoCertificacion $evento, bool $incluirQa = false): BinaryFileResponse
    {
        $emisiones = ServicioEventos::emisiones($evento->id, $incluirQa ? null : false)
            ->where('cf_emisiones.estado', Emision::EMITIDA)->orderBy('cf_emisiones.lote_id')->orderBy('cf_emisiones.id')->get();

        return $this->armar(self::entradasDe($emisiones), 'credenciales-evento-'.$evento->id.($incluirQa ? '-con-qa' : '').'.zip', 'Este evento no tiene certificados vigentes de participantes reales que descargar.');
    }

    /**
     * ZIP de un evento HISTÓRICO: SOLO los PDF ya congelados y elegibles (vigentes, conciliados, sin reemplazo) de los certificados del evento. NO genera ni
     * congela nada: no asigna códigos ni toca registros o PDF históricos. Cada PDF se verifica (existencia, tamaño y SHA-256) ANTES de armar el ZIP; si
     * alguno falta o no coincide, no se entrega ningún ZIP (nunca uno incompleto presentado como completo). Los elegibles que aún no tienen PDF se
     * omiten y así se declara en `LEEME.txt` dentro del ZIP (sin datos personales).
     *
     * @throws EmisionException
     */
    public function paraEventoHistorico(EventoCertificacion $evento, DescargaHistorica $descarga, CongeladorLegado $congelador): BinaryFileResponse
    {
        $resumen = $descarga->resumen($evento);
        $entradas = collect();
        $fallos = [];
        foreach ($descarga->disponibles($evento) as $c) {
            try {
                $archivo = $congelador->verificar($c);
            } catch (PdfHistoricoInconsistente) {
                $fallos[] = $c->id; // la verificación ya registró el motivo técnico (sin datos personales)

                continue;
            }
            $entradas->push(['fisica' => $congelador->rutaFisica($archivo), 'nombre' => (string) $c->nombre_completo, 'bytes' => $archivo->bytes]);
        }
        if ($fallos !== []) {
            throw new EmisionException(EmisionException::INTEGRIDAD_EMISION_INVALIDA, count($fallos).' certificado(s) histórico(s) de este evento tienen su PDF ausente o alterado (ids: '.implode(', ', array_slice($fallos, 0, 10)).(count($fallos) > 10 ? '…' : '').'). No se generó el ZIP para no entregarlo incompleto; avisa al equipo técnico.', 409);
        }

        $omitidos = $resumen['sin_pdf'];
        $leeme = $omitidos > 0
            ? "ZIP PARCIAL: contiene {$entradas->count()} PDF ya generados.
{$omitidos} certificado(s) elegible(s) de este evento aun no tienen PDF generado y NO se incluyeron.
Se generan al abrir cada certificado desde el modulo Historico.
"
            : null;

        return $this->armar($entradas, 'certificados-historicos-evento-'.$evento->id.'.zip', 'Este evento histórico no tiene PDF ya generados y elegibles para descargar. Los certificados se generan al abrirlos uno a uno.', $leeme, ['X-Certificados-Omitidos' => (string) $omitidos]);
    }

    /**
     * @param  Collection<int,Emision>  $emisiones
     * @return Collection<int,array{fisica:Closure|string,nombre:string,bytes:int}>
     */
    private static function entradasDe(Collection $emisiones): Collection
    {
        return $emisiones->map(fn (Emision $e) => [
            'fisica' => fn () => AlmacenEmisiones::rutaFisicaVerificada($e), // existencia, tamaño, SHA-256, ruta segura
            'nombre' => (string) ($e->datos_snapshot['nombre_completo'] ?? ''),
            'bytes' => (int) $e->pdf_bytes,
        ])->values();
    }

    /**
     * @param  Collection<int,array{fisica:Closure|string,nombre:string,bytes:int}>  $entradas
     * @param  array<string,string>  $cabeceras
     *
     * @throws EmisionException
     */
    private function armar(Collection $entradas, string $nombreArchivo, string $mensajeVacio, ?string $leeme = null, array $cabeceras = []): BinaryFileResponse
    {
        if ($entradas->isEmpty()) {
            throw new EmisionException(EmisionException::SIN_EMISIONES_VIGENTES, $mensajeVacio, 409);
        }

        $total = (int) $entradas->sum('bytes');
        if ($total > self::LIMITE_BYTES) {
            throw new EmisionException(EmisionException::ZIP_MUY_GRANDE, 'El ZIP superaría los '.(self::LIMITE_BYTES / 1024 / 1024).' MB permitidos. Descarga los certificados de uno en uno.', 409);
        }

        $temporal = sys_get_temp_dir();
        EspacioDisco::exigir($total, $temporal);

        $ruta = tempnam($temporal, 'cfz_');
        if ($ruta === false) {
            throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo preparar el archivo ZIP.', 500);
        }
        register_shutdown_function(static fn () => @unlink($ruta)); // respaldo si la conexión se corta antes del borrado

        try {
            $zip = new ZipArchive;
            if ($zip->open($ruta, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo preparar el archivo ZIP.', 500);
            }

            $usados = [];
            foreach ($entradas->values() as $i => $entrada) {
                $fisica = $entrada['fisica'] instanceof Closure ? ($entrada['fisica'])() : $entrada['fisica'];
                $nombre = self::nombreEntrada($i + 1, $entrada['nombre']);
                while (isset($usados[$nombre])) {
                    $nombre = self::nombreEntrada($i + 1, 'X'.Str::random(4)); // no ocurre: el consecutivo es único
                }
                $usados[$nombre] = true;

                $zip->addFile($fisica, $nombre);
                $zip->setCompressionName($nombre, ZipArchive::CM_STORE);
            }

            if ($leeme !== null) {
                $zip->addFromString('LEEME.txt', $leeme);
            }
            if (! $zip->close()) {
                throw new EmisionException(EmisionException::ERROR_ESCRITURA, 'No se pudo preparar el archivo ZIP.', 500);
            }
        } catch (Throwable $e) {
            @unlink($ruta);

            throw $e;
        }

        return response()->download($ruta, $nombreArchivo, [
            'Content-Type' => 'application/zip',
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
        ] + $cabeceras)->deleteFileAfterSend(true);
    }

    /** `NNN-NOMBRE-SANEADO.pdf`: consecutivo + nombre ASCII en mayúsculas con guiones (máx. 60), sin rutas. */
    public static function nombreEntrada(int $consecutivo, string $nombre): string
    {
        $ascii = strtoupper(Str::ascii($nombre));
        $limpio = trim((string) preg_replace('/[^A-Z0-9]+/', '-', $ascii), '-');
        $limpio = trim(substr($limpio, 0, self::NOMBRE_MAX), '-');

        return sprintf('%03d-%s.pdf', $consecutivo, $limpio === '' ? 'PARTICIPANTE' : $limpio);
    }
}
