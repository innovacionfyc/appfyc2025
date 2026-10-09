<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use InvalidArgumentException;

/**
 * Descarga de un certificado: de EXACTAMENTE una emisión moderna o un certificado histórico. La base de datos lo
 * garantiza con un CHECK en MySQL/MariaDB; esta validación es la segunda barrera (y la única en SQLite).
 */
class Descarga extends Model
{
    public const VIA_PORTAL = 'portal';

    public const VIA_CORREO = 'correo';

    public const VIA_ADMIN = 'admin';

    public const VIAS = [self::VIA_PORTAL, self::VIA_CORREO, self::VIA_ADMIN];

    /** Importada del sistema viejo (valor por defecto) o registrada ahora por Credential Flow. */
    public const ORIGEN_LEGADO_IMPORTADO = 'legado_importado';

    public const ORIGEN_CREDENTIAL_FLOW = 'credential_flow';

    protected $table = 'cf_descargas';

    protected $fillable = [
        'corrida_id',
        'emision_id',
        'certificado_legado_id',
        'participante_id',
        'via',
        'origen',
        'descargado_at',
        'ip_hash',
    ];

    protected $casts = [
        'descargado_at' => 'datetime',
    ];

    protected static function booted(): void
    {
        static::saving(function (Descarga $descarga) {
            $conEmision = $descarga->emision_id !== null;
            $conLegado = $descarga->certificado_legado_id !== null;

            if ($conEmision === $conLegado) {
                throw new InvalidArgumentException('Una descarga debe pertenecer a una emisión o a un certificado histórico, y solo a uno.');
            }
            if (! in_array($descarga->via, self::VIAS, true)) {
                throw new InvalidArgumentException('La vía de la descarga no es válida.');
            }
        });
    }

    public function emision(): BelongsTo
    {
        return $this->belongsTo(Emision::class);
    }

    public function certificadoLegado(): BelongsTo
    {
        return $this->belongsTo(CertificadoLegado::class, 'certificado_legado_id');
    }

    public function participante(): BelongsTo
    {
        return $this->belongsTo(Participante::class)->withTrashed();
    }
}
