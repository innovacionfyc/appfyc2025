<?php

namespace App\Models\CredentialFlow;

use App\Support\CredentialFlow\Legado\PlantillaImagen;
use App\Support\CredentialFlow\Legado\RutasLegado;
use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Un NOMBRE con el que el sistema viejo conocía una imagen de fondo (ruta original exacta). Apunta a su contenido físico
 * (PlantillaLegadoContenido, único por SHA-256); varios nombres pueden compartir contenido. Sin contenido = `faltante`.
 * Catálogo separado de Plantilla: la imagen original se conserva intacta.
 */
class PlantillaLegado extends Model
{
    use HasAuditFields;

    public const ESTADO_OK = 'ok';

    public const ESTADO_FALTANTE = 'faltante';

    public const ESTADO_HUERFANA = 'huerfana';

    public const ESTADO_CANDIDATA_REVISION = 'candidata_revision';

    public const ESTADO_EXTENSION_INVALIDA = 'extension_invalida';

    public const ESTADOS = [
        self::ESTADO_OK,
        self::ESTADO_FALTANTE,
        self::ESTADO_HUERFANA,
        self::ESTADO_CANDIDATA_REVISION,
        self::ESTADO_EXTENSION_INVALIDA,
    ];

    protected $table = 'cf_plantillas_legado';

    protected $fillable = [
        'corrida_id',
        'contenido_id',
        'ruta_original',
        'nombre_original',
        'nombre_normalizado',
        'extension_original',
        'renderizable',
        'motivo_no_renderizable',
        'estado',
        'notas',
        'aprobada_por_conciliacion_id',
    ];

    protected $attributes = [
        'renderizable' => false,
    ];

    protected $casts = [
        'renderizable' => 'boolean',
    ];

    public function contenido(): BelongsTo
    {
        return $this->belongsTo(PlantillaLegadoContenido::class, 'contenido_id');
    }

    public function certificados(): HasMany
    {
        return $this->hasMany(CertificadoLegado::class, 'plantilla_legado_id');
    }

    public function eventos(): HasMany
    {
        return $this->hasMany(EventoCertificacion::class, 'plantilla_legado_id');
    }

    /**
     * Lo que el renderer necesita (sin Eloquent). `$rutaFisica` la resuelve quien llama (disco privado): aquí solo se arma
     * la descripción; una entrada sin contenido produce una ruta vacía y el renderer se niega por su estado.
     */
    public function comoImagen(?string $rutaFisica = null): PlantillaImagen
    {
        $contenido = $this->contenido;
        $ruta = $rutaFisica ?? ($contenido?->ruta_almacenada !== null ? Storage::disk('local')->path($contenido->ruta_almacenada) : '');

        // Aprobación administrativa (Fase 10B-1): se renderiza con el tipo REAL del contenido; el catálogo (estado, extensión original,
        // renderizable) no se toca. Sin contenido o con un tipo no soportado, la marca no habilita nada.
        if ($this->aprobada_por_conciliacion_id !== null && $contenido !== null) {
            $tipo = ['png' => 'png', 'jpg' => 'jpg', 'gif' => 'gif'][RutasLegado::extensionPorMime($contenido->mime_real) ?? ''] ?? null;
            if ($tipo !== null) {
                return new PlantillaImagen($ruta, $contenido->sha256, $tipo, self::ESTADO_OK, true);
            }
        }

        return new PlantillaImagen($ruta, $contenido?->sha256, $this->extension_original, $this->estado, $this->renderizable);
    }

    /** Ruta futura del blob de este contenido (null si es una entrada sin contenido o de tipo no soportado). */
    public function rutaBlobEsperada(): ?string
    {
        $contenido = $this->contenido;
        $extension = RutasLegado::extensionPorMime($contenido?->mime_real);

        return $contenido !== null && $extension !== null ? RutasLegado::plantilla($contenido->sha256, $extension) : null;
    }
}
