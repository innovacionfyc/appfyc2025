<?php

namespace App\Support\CredentialFlow\Conciliaciones;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Conciliacion;
use App\Models\CredentialFlow\ConciliacionEvento;
use App\Models\Movimiento;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Support\CredentialFlow\StagingEv\EscanerImagenes;
use Closure;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * Resolución administrativa de los casos de PLANTILLA (Fase 10B-1): aprobar una candidata, confirmar como renderizable el contenido de
 * tipo histórico no compatible y aportar manualmente una plantilla. NUNCA reescribe la evidencia histórica: cada acción crea una
 * DECISIÓN sobre el catálogo (la marca `aprobada_por_conciliacion_id` de la entrada, o una entrada nueva) y asocia los certificados del
 * caso a esa entrada (`plantilla_legado_id`), recalculando su estado con la misma prioridad de la migración (EstadoDerivado). No se
 * tocan `estado`, `extension_original`, `renderizable` ni las notas de las entradas existentes, ni los eventos, ni los archivos.
 *
 * Atomicidad: todo ocurre en UNA transacción con `lockForUpdate` sobre el caso y sus certificados; cualquier fallo revierte todo.
 * Idempotencia: un caso que ya no está `abierto` se niega («Este caso ya fue resuelto.») sin duplicar eventos ni asociaciones.
 * PDF congelado: si algún certificado afectado ya tiene su PDF (o fue reemplazado) la acción se NIEGA; la salida es una emisión moderna.
 * Reversibilidad: `revertir` deshace la decisión mientras ningún PDF se haya congelado y ningún certificado se haya reemplazado; el
 * archivo físico del catálogo NUNCA se borra.
 */
final class ResolucionPlantillas
{
    public const ACCION_CANDIDATA = 'plantilla_candidata_aprobada';

    public const ACCION_RENDERIZABLE = 'plantilla_renderizable_confirmada';

    public const ACCION_MANUAL = 'plantilla_manual_aportada';

    public const ACCION_REVERTIDA = 'plantilla_resolucion_revertida';

    public const ACCIONES = [self::ACCION_CANDIDATA, self::ACCION_RENDERIZABLE, self::ACCION_MANUAL];

    public const MOTIVO_MIN = 10;

    public const MOTIVO_MAX = 500;

    /** Tipos de imagen que se aceptan al aportar una plantilla (FPDF del sistema viejo; sin PDF). */
    public const MIMES_MANUAL = ['image/png' => 'png', 'image/jpeg' => 'jpg'];

    public const MAX_BYTES_POR_DEFECTO = 10 * 1024 * 1024;

    public const MIN_LADO_PX = 300;

    public const MAX_LADO_PX = 12000;

    public const MAX_PIXELES = 80_000_000;

    public const MSG_PDF_CONGELADO = 'Este certificado ya tiene un archivo histórico generado y no puede cambiarse de plantilla directamente.';

    public function __construct(private readonly string $disco = 'local') {}

    // ── Acciones ─────────────────────────────────────────────────────────────────────────────────────────

    /** @return array{caso_id:int,resolucion:string,certificados:int,estados:array<string,int>} */
    public function aprobarCandidata(int $casoId, int $actorId, string $motivo): array
    {
        return $this->resolver($casoId, [Conciliacion::TIPO_PLANTILLA_CANDIDATA], self::ACCION_CANDIDATA, 'candidata_aprobada', $actorId, $motivo, function (Conciliacion $caso) use ($actorId) {
            $falta = DB::table('cf_plantillas_legado')->where('id', (int) $caso->referencia_clave)->lockForUpdate()->first();
            $evidencia = $falta === null || $falta->estado !== 'faltante' ? null : (json_decode((string) $falta->notas, true)['evidencia_candidata'] ?? null);
            if ($evidencia === null || empty($evidencia['candidata_sha256'])) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CANDIDATA_NO_DISPONIBLE, 'La imagen candidata ya no está disponible para este caso.');
            }
            $contenido = DB::table('cf_plantillas_legado_contenidos')->where('sha256', $evidencia['candidata_sha256'])->lockForUpdate()->first();
            if ($contenido === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CANDIDATA_NO_DISPONIBLE, 'La imagen candidata ya no está disponible para este caso.');
            }
            $this->exigirContenidoCoherente($contenido, $evidencia);

            $candidata = DB::table('cf_plantillas_legado')->where('contenido_id', $contenido->id)->where('estado', 'candidata_revision')->whereNull('aprobada_por_conciliacion_id')->orderBy('id')->lockForUpdate()->first();
            if ($candidata === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CANDIDATA_NO_DISPONIBLE, 'La imagen candidata ya no está disponible para este caso.');
            }
            if (! $candidata->renderizable) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_REAL_NO_SOPORTADO, 'La imagen candidata no se puede usar para generar certificados.');
            }
            DB::table('cf_plantillas_legado')->where('id', $candidata->id)->update(['aprobada_por_conciliacion_id' => $caso->id, 'update_by' => $actorId, 'updated_at' => now()]);

            return [(int) $candidata->id, $this->evidenciaContenido($contenido) + ['plantilla_candidata_id' => (int) $candidata->id, 'plantilla_referencia_id' => (int) $falta->id]];
        });
    }

    /** @return array{caso_id:int,resolucion:string,certificados:int,estados:array<string,int>} */
    public function confirmarRenderizable(int $casoId, int $actorId, string $motivo): array
    {
        return $this->resolver($casoId, [Conciliacion::TIPO_PLANTILLA_TIPO_INVALIDO], self::ACCION_RENDERIZABLE, 'renderizable_confirmado', $actorId, $motivo, function (Conciliacion $caso) use ($actorId) {
            $entrada = DB::table('cf_plantillas_legado')->where('id', (int) $caso->referencia_clave)->lockForUpdate()->first();
            if ($entrada === null || $entrada->estado !== 'extension_invalida' || $entrada->contenido_id === null || $entrada->aprobada_por_conciliacion_id !== null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CONTENIDO_NO_COINCIDE, 'La plantilla de este caso cambió; actualiza la pantalla y revisa el caso.');
            }
            $contenido = DB::table('cf_plantillas_legado_contenidos')->where('id', $entrada->contenido_id)->lockForUpdate()->first();
            $extension = RutasLegado::extensionPorMime($contenido?->mime_real);
            if ($contenido === null || $extension === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_REAL_NO_SOPORTADO, 'El tipo real del contenido no es una imagen compatible.');
            }
            $this->exigirContenidoCoherente($contenido, null);

            DB::table('cf_plantillas_legado')->where('id', $entrada->id)->update(['aprobada_por_conciliacion_id' => $caso->id, 'update_by' => $actorId, 'updated_at' => now()]);

            return [(int) $entrada->id, $this->evidenciaContenido($contenido) + ['plantilla_id' => (int) $entrada->id, 'extension_historica_conservada' => true, 'motivo_original' => $entrada->motivo_no_renderizable]];
        });
    }

    /**
     * @param  string  $rutaArchivo  archivo subido (temporal); nunca se confía en su nombre ni en su extensión
     * @return array{caso_id:int,resolucion:string,certificados:int,estados:array<string,int>}
     */
    public function aportarPlantilla(int $casoId, int $actorId, string $motivo, string $rutaArchivo): array
    {
        // La validación del archivo es independiente del caso: se hace primero para no bloquear filas por un archivo inválido.
        $archivo = $this->validarArchivo($rutaArchivo);

        return $this->resolver($casoId, [Conciliacion::TIPO_PLANTILLA_FALTANTE], self::ACCION_MANUAL, 'plantilla_aportada', $actorId, $motivo, function (Conciliacion $caso) use ($actorId, $archivo, $rutaArchivo) {
            $ahora = now();
            $contenido = DB::table('cf_plantillas_legado_contenidos')->where('sha256', $archivo['sha256'])->lockForUpdate()->first();
            $reutilizado = $contenido !== null;
            if ($contenido === null) {
                $id = DB::table('cf_plantillas_legado_contenidos')->insertGetId([
                    'sha256' => $archivo['sha256'], 'bytes' => $archivo['bytes'], 'mime_real' => $archivo['mime'], 'ancho_px' => $archivo['ancho'], 'alto_px' => $archivo['alto'],
                    'ruta_almacenada' => null, 'created_by' => $actorId, 'update_by' => $actorId, 'created_at' => $ahora, 'updated_at' => $ahora,
                ]);
                $contenido = DB::table('cf_plantillas_legado_contenidos')->where('id', $id)->first();
            }

            // Una sola copia por SHA (catálogo de la Fase 3); nunca se sobrescribe un archivo existente.
            $ruta = RutasLegado::plantilla($archivo['sha256'], $archivo['extension']);
            $this->guardarBlob($ruta, $rutaArchivo, $archivo['sha256']);
            if ($contenido->ruta_almacenada === null) {
                DB::table('cf_plantillas_legado_contenidos')->where('id', $contenido->id)->update(['ruta_almacenada' => $ruta, 'update_by' => $actorId, 'updated_at' => $ahora]);
            }

            // Entrada propia del catálogo (clave determinista por caso y contenido): la evidencia de la referencia faltante no se toca.
            $clave = 'manual://conciliacion/'.$caso->id.'/'.substr($archivo['sha256'], 0, 12);
            $entrada = DB::table('cf_plantillas_legado')->where('ruta_original', $clave)->lockForUpdate()->first();
            $datos = [
                'contenido_id' => $contenido->id, 'extension_original' => $archivo['extension'], 'renderizable' => true, 'motivo_no_renderizable' => null, 'estado' => 'ok',
                'aprobada_por_conciliacion_id' => $caso->id, 'update_by' => $actorId, 'updated_at' => $ahora,
            ];
            if ($entrada === null) {
                $entradaId = DB::table('cf_plantillas_legado')->insertGetId($datos + [
                    'ruta_original' => $clave, 'nombre_original' => 'Plantilla aportada manualmente', 'nombre_normalizado' => 'plantilla aportada manualmente',
                    'notas' => json_encode(['origen' => 'aporte_manual', 'conciliacion_id' => $caso->id]), 'created_by' => $actorId, 'created_at' => $ahora,
                ]);
            } else {
                $entradaId = (int) $entrada->id;
                DB::table('cf_plantillas_legado')->where('id', $entradaId)->update($datos);
            }

            return [$entradaId, $this->evidenciaContenido($contenido) + ['plantilla_id' => $entradaId, 'contenido_id' => (int) $contenido->id, 'contenido_reutilizado' => $reutilizado]];
        });
    }

    /**
     * Deshace la resolución de un caso (solo si ningún PDF se congeló y ningún certificado se reemplazó). El caso vuelve a `abierto`; el
     * archivo físico y la entrada del catálogo se conservan.
     *
     * @return array{caso_id:int,certificados:int}
     */
    public function revertir(int $casoId, int $actorId, string $motivo): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $actorId, $motivo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            $evento = DB::table('cf_conciliaciones_eventos')->where('conciliacion_id', $caso->id)->whereIn('accion', self::ACCIONES)->orderByDesc('id')->first();
            if ($caso->estado !== Conciliacion::RESUELTO || $evento === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Este caso no tiene una resolución de plantilla que se pueda deshacer.');
            }
            $evi = json_decode((string) $evento->evidencia, true) ?: [];
            $ids = array_map('intval', $evi['certificados_ids'] ?? []);
            $entradaId = (int) ($evi['plantilla_entrada_id'] ?? 0);

            $certs = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'estado', 'conciliacion_estado', 'plantilla_legado_id', 'pdf_archivo', 'reemplazado_por_emision_id']);
            $this->exigirSinPdfNiReemplazo($certs, true);
            if ($certs->count() !== count($ids) || $certs->contains(fn ($c) => (int) $c->plantilla_legado_id !== $entradaId || ! in_array($c->conciliacion_estado, [CertificadoLegado::CONCILIACION_OK, CertificadoLegado::CONCILIACION_DUPLICADO_CONSOLIDADO], true))) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::NO_REVERSIBLE, 'Los certificados de este caso cambiaron después de la resolución: no se puede deshacer automáticamente.');
            }

            $ahora = now();
            DB::table('cf_certificados_legado')->whereIn('id', $ids)->update(['plantilla_legado_id' => null, 'conciliacion_estado' => CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA, 'update_by' => $actorId, 'updated_at' => $ahora]);
            // Se quita la marca. Una entrada aportada a mano queda en el catálogo como imagen sin evento (el archivo no se borra).
            DB::table('cf_plantillas_legado')->where('id', $entradaId)->update(['aprobada_por_conciliacion_id' => null, 'update_by' => $actorId, 'updated_at' => $ahora]
                + ($evento->accion === self::ACCION_MANUAL ? ['estado' => 'huerfana'] : []));

            $caso->update(['estado' => Conciliacion::ABIERTO, 'resolucion' => null, 'resuelto_por' => null, 'resuelto_at' => null]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => self::ACCION_REVERTIDA, 'estado_anterior' => Conciliacion::RESUELTO, 'estado_nuevo' => Conciliacion::ABIERTO, 'motivo' => $motivo,
                'evidencia' => ['revierte' => $evento->accion, 'plantilla_entrada_id' => $entradaId, 'certificados' => count($ids)], 'actor_id' => $actorId]);
            $this->movimiento($actorId, 'plantilla_resolucion_revertida', $caso->id, count($ids));

            return ['caso_id' => (int) $caso->id, 'certificados' => count($ids)];
        });
    }

    // ── Núcleo común ─────────────────────────────────────────────────────────────────────────────────────

    /**
     * @param  list<string>  $tipos
     * @param  Closure(Conciliacion):array{0:int,1:array<string,mixed>}  $cuerpo  devuelve [id de la entrada del catálogo, evidencia]
     * @return array{caso_id:int,resolucion:string,certificados:int,estados:array<string,int>}
     */
    private function resolver(int $casoId, array $tipos, string $accion, string $resolucion, int $actorId, string $motivo, Closure $cuerpo): array
    {
        $motivo = $this->motivoValido($motivo);

        return DB::transaction(function () use ($casoId, $tipos, $accion, $resolucion, $actorId, $motivo, $cuerpo) {
            $caso = Conciliacion::query()->whereKey($casoId)->lockForUpdate()->firstOrFail();
            if (! in_array($caso->tipo, $tipos, true)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'Esta acción no corresponde a este tipo de caso.');
            }
            if ($caso->estado !== Conciliacion::ABIERTO) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CASO_YA_RESUELTO, 'Este caso ya fue resuelto.');
            }

            $ids = DB::table('cf_conciliaciones_certificados')->where('conciliacion_id', $caso->id)->orderBy('certificado_legado_id')->pluck('certificado_legado_id')->map(fn ($i) => (int) $i)->all();
            $certs = DB::table('cf_certificados_legado')->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get(['id', 'estado', 'conciliacion_estado', 'plantilla_legado_id', 'pdf_archivo', 'reemplazado_por_emision_id', 'snapshot_legado']);
            $this->exigirSinPdfNiReemplazo($certs, false);
            if ($certs->isEmpty() || $certs->contains(fn ($c) => $c->conciliacion_estado !== CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA || $c->plantilla_legado_id !== null)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'Los certificados de este caso cambiaron desde que se detectó. Actualiza la pantalla y revisa el caso.');
            }

            [$entradaId, $evidencia] = $cuerpo($caso);

            // Estado recalculado por certificado (no se hardcodea «ok»): el bloqueo más restrictivo gana y los duplicados idénticos
            // recuperan su relación canónico/duplicado. Sin tocar grupos ni el mapa de la migración.
            $porEstado = [];
            foreach ($certs as $c) {
                $porEstado[EstadoDerivado::para(json_decode((string) $c->snapshot_legado, true) ?: [], true)][] = (int) $c->id;
            }
            $ahora = now();
            foreach ($porEstado as $estado => $grupo) {
                DB::table('cf_certificados_legado')->whereIn('id', $grupo)->update(['plantilla_legado_id' => $entradaId, 'conciliacion_estado' => $estado, 'update_by' => $actorId, 'updated_at' => $ahora]);
            }
            $conteos = array_map('count', $porEstado);

            $caso->update(['estado' => Conciliacion::RESUELTO, 'resolucion' => $resolucion, 'resuelto_por' => $actorId, 'resuelto_at' => $ahora]);
            ConciliacionEvento::create(['conciliacion_id' => $caso->id, 'accion' => $accion, 'estado_anterior' => Conciliacion::ABIERTO, 'estado_nuevo' => Conciliacion::RESUELTO, 'motivo' => $motivo,
                'evidencia' => $evidencia + ['plantilla_entrada_id' => $entradaId, 'certificados_ids' => $ids, 'estado_anterior_certificados' => CertificadoLegado::CONCILIACION_PENDIENTE_PLANTILLA, 'estados_nuevos' => $conteos], 'actor_id' => $actorId]);
            $this->movimiento($actorId, $accion, $caso->id, count($ids));

            return ['caso_id' => (int) $caso->id, 'resolucion' => $resolucion, 'certificados' => count($ids), 'estados' => $conteos];
        });
    }

    private function motivoValido(string $motivo): string
    {
        $motivo = trim($motivo);
        if (mb_strlen($motivo) < self::MOTIVO_MIN || mb_strlen($motivo) > self::MOTIVO_MAX) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_NO_ADMITIDO, 'El motivo debe tener entre '.self::MOTIVO_MIN.' y '.self::MOTIVO_MAX.' caracteres.');
        }

        return $motivo;
    }

    /** @param Collection<int,object> $certs */
    private function exigirSinPdfNiReemplazo($certs, bool $alRevertir): void
    {
        if ($certs->contains(fn ($c) => $c->pdf_archivo !== null)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PDF_CONGELADO, self::MSG_PDF_CONGELADO);
        }
        if ($certs->contains(fn ($c) => $c->reemplazado_por_emision_id !== null || $c->estado !== CertificadoLegado::ESTADO_VIGENTE)) {
            throw new ResolucionNoPermitida($alRevertir ? ResolucionNoPermitida::NO_REVERSIBLE : ResolucionNoPermitida::CERTIFICADOS_CAMBIARON, 'Algún certificado de este caso ya fue reemplazado o revocado: no se puede cambiar su plantilla directamente.');
        }
    }

    /** El contenido del catálogo debe ser una imagen compatible con dimensiones y huella válidas (y, si hay evidencia, coincidir con ella). */
    private function exigirContenidoCoherente(object $contenido, ?array $evidencia): void
    {
        if (RutasLegado::extensionPorMime($contenido->mime_real) === null) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::TIPO_REAL_NO_SOPORTADO, 'El tipo real de la imagen no es compatible.');
        }
        if (preg_match('/^[0-9a-f]{64}$/', (string) $contenido->sha256) !== 1 || (int) $contenido->ancho_px < 1 || (int) $contenido->alto_px < 1 || (int) $contenido->bytes < 1) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::CONTENIDO_NO_COINCIDE, 'Los datos de la imagen (huella, tipo o dimensiones) no son válidos.');
        }
        if ($evidencia !== null) {
            $coincide = hash_equals((string) $evidencia['candidata_sha256'], (string) $contenido->sha256)
                && (! isset($evidencia['mime_real']) || $evidencia['mime_real'] === $contenido->mime_real)
                && (! isset($evidencia['ancho_px']) || (int) $evidencia['ancho_px'] === (int) $contenido->ancho_px)
                && (! isset($evidencia['alto_px']) || (int) $evidencia['alto_px'] === (int) $contenido->alto_px)
                && (! isset($evidencia['bytes']) || (int) $evidencia['bytes'] === (int) $contenido->bytes);
            if (! $coincide) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CONTENIDO_NO_COINCIDE, 'La imagen candidata ya no coincide con la evidencia que se detectó.');
            }
        }
        // Si el archivo ya está en el almacenamiento, se comprueba que su contenido siga siendo el del catálogo.
        if ($contenido->ruta_almacenada !== null && Storage::disk($this->disco)->exists($contenido->ruta_almacenada)) {
            $fisico = Storage::disk($this->disco)->path($contenido->ruta_almacenada);
            if (! hash_equals((string) $contenido->sha256, (string) hash_file('sha256', $fisico))) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::CONTENIDO_NO_COINCIDE, 'El archivo almacenado no coincide con su huella del catálogo.');
            }
        }
    }

    /** @return array{sha256:string,mime_real:?string,ancho_px:int,alto_px:int,bytes:int} sin rutas físicas */
    private function evidenciaContenido(object $contenido): array
    {
        return ['sha256' => $contenido->sha256, 'mime_real' => $contenido->mime_real, 'ancho_px' => (int) $contenido->ancho_px, 'alto_px' => (int) $contenido->alto_px, 'bytes' => (int) $contenido->bytes];
    }

    private function movimiento(int $actorId, string $accion, int $casoId, int $certificados): void
    {
        // Sin IP ni agente de usuario (Movimiento::registrar los guarda): solo ids y conteos, nada personal.
        Movimiento::create(['user_id' => $actorId, 'tipo' => 'conciliacion', 'modulo' => 'credential_flow', 'descripcion' => "Conciliación #{$casoId}: {$accion} ({$certificados} certificados)", 'metadata' => ['conciliacion_id' => $casoId, 'accion' => $accion, 'certificados' => $certificados]]);
    }

    // ── Archivo aportado ─────────────────────────────────────────────────────────────────────────────────

    /** @return array{sha256:string,bytes:int,mime:string,extension:string,ancho:int,alto:int} */
    public function validarArchivo(string $ruta): array
    {
        $falla = fn (string $m) => new ResolucionNoPermitida(ResolucionNoPermitida::ARCHIVO_INVALIDO, $m);
        if (! is_file($ruta) || is_link($ruta) || ! is_readable($ruta)) {
            throw $falla('No se pudo leer el archivo.');
        }
        $bytes = (int) filesize($ruta);
        $maximo = (int) config('credential_flow.legado.plantilla_manual_max_bytes', self::MAX_BYTES_POR_DEFECTO);
        if ($bytes < 1) {
            throw $falla('El archivo está vacío.');
        }
        if ($bytes > $maximo) {
            throw $falla('El archivo pesa demasiado (máximo '.number_format($maximo / 1048576, 0).' MB).');
        }

        // El tipo se decide por el CONTENIDO, nunca por el nombre ni por la extensión.
        $mime = (new \finfo(FILEINFO_MIME_TYPE))->file($ruta) ?: '';
        $extension = self::MIMES_MANUAL[$mime] ?? null;
        if ($extension === null) {
            throw $falla('Solo se aceptan imágenes JPEG o PNG. Un PDF u otro tipo de archivo no sirve como plantilla histórica.');
        }
        $info = @getimagesize($ruta);
        if (! is_array($info) || ($info['mime'] ?? null) !== $mime) {
            throw $falla('El contenido del archivo no es una imagen válida.');
        }
        [$ancho, $alto] = [(int) $info[0], (int) $info[1]];
        if (min($ancho, $alto) < self::MIN_LADO_PX || max($ancho, $alto) > self::MAX_LADO_PX || $ancho * $alto > self::MAX_PIXELES) {
            throw $falla('Las dimensiones de la imagen no son razonables para un certificado ('.$ancho.' × '.$alto.' px).');
        }
        // Integridad barata (sin decodificar píxeles): un archivo truncado no termina con su marca de fin (PNG: IEND; JPEG: EOI).
        $cola = (string) file_get_contents($ruta, false, null, max(0, $bytes - 64));
        if (($extension === 'png' && ! str_contains(substr($cola, -16), "IEND\xAE\x42\x60\x82")) || ($extension === 'jpg' && ! str_contains($cola, "\xFF\xD9"))) {
            throw $falla('El archivo de imagen está incompleto o dañado.');
        }
        // Mismas condiciones que exigiría el FPDF del sistema viejo (PNG sin 16 bits ni entrelazado, JPEG real, etc.).
        if (! EscanerImagenes::evaluar('plantilla.'.$extension, $ruta)['ok']) {
            throw $falla('La imagen no es compatible con el generador de certificados históricos (por ejemplo, PNG entrelazado o de 16 bits).');
        }

        return ['sha256' => (string) hash_file('sha256', $ruta), 'bytes' => $bytes, 'mime' => $mime, 'extension' => $extension, 'ancho' => $ancho, 'alto' => $alto];
    }

    /** Copia el archivo al disco privado por SHA. Si ya existe NO se sobrescribe (se comprueba que sea el mismo contenido). */
    private function guardarBlob(string $ruta, string $origen, string $sha): void
    {
        $disco = Storage::disk($this->disco);
        if ($disco->exists($ruta)) {
            if (! hash_equals($sha, (string) hash_file('sha256', $disco->path($ruta)))) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::ALMACENAMIENTO, 'Ya existe un archivo distinto con esa huella en el almacenamiento: no se sobrescribe.');
            }

            return;
        }
        $temporal = $ruta.'.'.bin2hex(random_bytes(6)).'.tmp';
        try {
            $f = fopen($origen, 'rb');
            if ($f === false || ! $disco->put($temporal, $f)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::ALMACENAMIENTO, 'No se pudo guardar el archivo en el almacenamiento privado.');
            }
            if (is_resource($f)) {
                fclose($f);
            }
            if (! hash_equals($sha, (string) hash_file('sha256', $disco->path($temporal))) || ! $disco->move($temporal, $ruta)) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::ALMACENAMIENTO, 'No se pudo confirmar el archivo guardado.');
            }
        } catch (Throwable $e) {
            $disco->delete($temporal);

            throw $e;
        }
    }
}
