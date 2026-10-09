<?php

namespace App\Support\CredentialFlow\Reemplazo;

use App\Models\CredentialFlow\CertificadoLegado;
use App\Models\CredentialFlow\Plantilla;
use App\Services\CredentialFlow\PlantillaService;
use App\Support\CredentialFlow\Conciliaciones\ResolucionNoPermitida;
use App\Support\CredentialFlow\DisenoSchema;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaLegado;
use App\Support\CredentialFlow\Legado\ResolutorPlantillaStorage;
use App\Support\CredentialFlow\Plantillas\ImagenAPdf;
use App\Support\CredentialFlow\Plantillas\ImagenExcedeCapacidadException;
use App\Support\CredentialFlow\Plantillas\ImagenInvalidaException;
use Illuminate\Database\UniqueConstraintViolationException;

/**
 * Clona la imagen de una plantilla HISTÓRICA en una plantilla MODERNA (PDF base por `ImagenAPdf` + diseño inicial). Una imagen histórica (por su
 * SHA-256) → UN clon reutilizable (índice único `cf_plantillas.origen_legado_sha256`). Nunca altera la imagen histórica y nunca se ejecuta sola:
 * un administrador (o la futura pantalla 10B-2B-2B) lo pide para un certificado concreto. Es independiente de la transacción del reemplazo: así
 * un fallo del reemplazo no deja archivos de plantilla huérfanos.
 */
final class CreadorPlantillaReemplazo
{
    /** Versión del proceso que genera el clon (entra en `origen_legado_meta`). */
    public const VERSION_PROCESO = 'clon-2';

    private readonly ResolutorPlantillaLegado $resolutor;

    public function __construct(private readonly PlantillaService $plantillas, ?ResolutorPlantillaLegado $resolutor = null)
    {
        $this->resolutor = $resolutor ?? new ResolutorPlantillaStorage;
    }

    /**
     * La plantilla moderna que clona la imagen histórica del certificado (la crea si no existe).
     *
     * @return array{plantilla:Plantilla,creada:bool}
     *
     * @throws ResolucionNoPermitida PLANTILLA_INVALIDA
     */
    public function paraCertificado(CertificadoLegado $certificado): array
    {
        $imagen = $this->resolutor->resolver($certificado);
        if ($imagen->estado !== 'ok' || ! $imagen->renderizable || $imagen->sha256 === null || $imagen->rutaFisica === '' || ! is_file($imagen->rutaFisica)
            || ! hash_equals($imagen->sha256, (string) hash_file('sha256', $imagen->rutaFisica))) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La imagen histórica no está disponible, no es renderizable o no coincide con el catálogo.');
        }

        $existente = $this->existente($imagen->sha256);
        if ($existente !== null) {
            return ['plantilla' => $existente, 'creada' => false];
        }

        $bytes = (string) file_get_contents($imagen->rutaFisica);
        try {
            // Memoria acotada: las imágenes incrustables (todas las >16 MP del catálogo) se incrustan SIN decodificar; el resto, solo si la estimación previa cabe.
            $pdf = ImagenAPdf::convertirHistorica($bytes);
        } catch (ImagenExcedeCapacidadException $e) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_HISTORICA_EXCEDE_CAPACIDAD, $e->getMessage());
        } catch (ImagenInvalidaException) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La imagen histórica no se pudo convertir en una plantilla moderna.');
        }
        $dimensiones = (array) @getimagesizefromstring($bytes);
        // El PDF es un DERIVADO técnico de render, no evidencia histórica: queda trazado respecto de la imagen original, que no se toca.
        $meta = [
            'version_proceso' => self::VERSION_PROCESO, 'algoritmo' => $pdf['modo'], 'mime' => $dimensiones['mime'] ?? null, 'sha256_origen' => $imagen->sha256,
            'sha256_derivado' => hash('sha256', $pdf['pdf']), 'ancho_px_origen' => (int) ($dimensiones[0] ?? 0), 'alto_px_origen' => (int) ($dimensiones[1] ?? 0),
            'ancho_px_derivado' => $pdf['ancho_px'], 'alto_px_derivado' => $pdf['alto_px'], 'bytes_origen' => strlen($bytes), 'bytes_derivado' => strlen($pdf['pdf']),
            'generado_at' => now()->toIso8601String(), 'advertencias' => $pdf['advertencias'],
        ];
        unset($bytes);

        $diseno = DisenoReemplazo::inicial($pdf['ancho_pt'], $pdf['alto_pt']);

        try {
            $plantilla = $this->plantillas->crearDesdePdf(
                'Reemplazo histórico '.substr($imagen->sha256, 0, 12),
                'Clon moderno de una imagen histórica (diseño inicial: pendiente de revisión en el editor).',
                'plantilla-historica.pdf',
                $pdf['pdf'],
                ['origen_legado_sha256' => $imagen->sha256, 'origen_legado_meta' => $meta, 'diseno' => $diseno, 'schema_version' => DisenoSchema::versionPara($diseno)],
            );
        } catch (UniqueConstraintViolationException) {
            // Otro proceso creó el clon a la vez: se reutiliza (PlantillaService ya limpió el intento propio).
            $existente = $this->existente($imagen->sha256);
            if ($existente === null) {
                throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'No se pudo crear la plantilla moderna del reemplazo.');
            }

            return ['plantilla' => $existente, 'creada' => false];
        }

        return ['plantilla' => $plantilla, 'creada' => true];
    }

    /** Hash canónico del diseño (claves ordenadas): identifica EXACTAMENTE el diseño que el administrador revisó. @param array<string,mixed> $diseno */
    public static function hashDiseno(array $diseno): string
    {
        $ordenar = function (mixed $v) use (&$ordenar) {
            if (! is_array($v)) {
                return $v;
            }
            $o = array_map($ordenar, $v);
            if (! array_is_list($o)) {
                ksort($o);
            }

            return $o;
        };

        return hash('sha256', json_encode($ordenar($diseno), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
    }

    /**
     * ¿El administrador ya revisó y confirmó el diseño ACTUAL del clon? La confirmación guarda el hash del diseño revisado en `origen_legado_meta`
     * (sin columnas nuevas): si el diseño se vuelve a guardar en el editor, el hash deja de coincidir y la confirmación se pierde sola.
     */
    public static function disenoConfirmado(Plantilla $p): bool
    {
        $hash = $p->origen_legado_meta['diseno_confirmado']['hash'] ?? null;

        return is_string($hash) && is_array($p->diseno) && hash_equals($hash, self::hashDiseno($p->diseno));
    }

    /**
     * Confirma el diseño actual de un clon para usarlo en reemplazos (acción administrativa, tras revisarlo en el editor).
     *
     * @throws ResolucionNoPermitida
     */
    public static function confirmarDiseno(Plantilla $p, int $actorId): Plantilla
    {
        $d = $p->diseno;
        if ($p->origen_legado_sha256 === null || ! is_array($d) || ! isset($d['page'], $d['elements'])) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'Esta plantilla no es un clon histórico con un diseño guardado.');
        }
        $campos = collect($d['elements'])->where('type', 'text')->pluck('field')->filter()->all();
        if (! in_array('nombre_completo', $campos, true) || ! in_array('documento', $campos, true)) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'El diseño debe imprimir el nombre y el documento.');
        }
        $meta = (array) $p->origen_legado_meta;
        $meta['diseno_confirmado'] = ['hash' => self::hashDiseno($d), 'at' => now()->toIso8601String(), 'por' => $actorId];
        $p->update(['origen_legado_meta' => $meta]);

        return $p->fresh();
    }

    /** El clon moderno de la imagen histórica de este certificado, si ya existe (por el SHA del catálogo: no necesita el archivo). */
    public static function existentePara(CertificadoLegado $certificado): ?Plantilla
    {
        $sha = $certificado->plantillaLegado?->contenido?->sha256;

        return $sha === null ? null : Plantilla::query()->where('origen_legado_sha256', $sha)->first();
    }

    /** @throws ResolucionNoPermitida si el clon existe pero fue eliminado */
    private function existente(string $sha): ?Plantilla
    {
        $p = Plantilla::withTrashed()->where('origen_legado_sha256', $sha)->first();
        if ($p !== null && $p->trashed()) {
            throw new ResolucionNoPermitida(ResolucionNoPermitida::PLANTILLA_INVALIDA, 'La plantilla moderna de esta imagen histórica fue eliminada.');
        }

        return $p;
    }
}
