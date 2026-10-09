<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * El contenido físico de una imagen histórica: único por SHA-256 (una sola copia en disco aunque varios nombres del sistema
 * viejo lo usen). `ruta_almacenada` es NULL hasta que el archivo se copie al almacenamiento privado.
 */
class PlantillaLegadoContenido extends Model
{
    use HasAuditFields;

    protected $table = 'cf_plantillas_legado_contenidos';

    protected $fillable = [
        'corrida_id',
        'sha256',
        'bytes',
        'mime_real',
        'ancho_px',
        'alto_px',
        'ruta_almacenada',
    ];

    protected $casts = [
        'bytes' => 'integer',
        'ancho_px' => 'integer',
        'alto_px' => 'integer',
    ];

    public function plantillas(): HasMany
    {
        return $this->hasMany(PlantillaLegado::class, 'contenido_id');
    }
}
