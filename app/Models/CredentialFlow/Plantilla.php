<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;

class Plantilla extends Model
{
    use HasAuditFields, SoftDeletes;

    /** Disco privado (storage/app/private). Los PDFs de Credential Flow nunca van a un disco público. */
    public const DISCO = 'local';

    public const NOMBRE_PDF = 'base.pdf';

    protected $table = 'cf_plantillas';

    protected $fillable = [
        'nombre',
        'descripcion',
        'archivo_pdf',
        'nombre_archivo_original',
        'hash_sha256',
        'diseno',
        'schema_version',
    ];

    protected $casts = [
        'diseno' => 'array',
        'schema_version' => 'integer',
    ];

    /** Carpeta de la plantilla, calculada solo a partir de su id (nunca de datos del navegador). */
    public function carpeta(): string
    {
        return 'credential-flow/plantillas/'.(int) $this->getKey();
    }

    /** Ruta relativa esperada del PDF base. */
    public function rutaPdfEsperada(): string
    {
        return $this->carpeta().'/'.self::NOMBRE_PDF;
    }
}
