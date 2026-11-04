<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;

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
        'origen_legado_sha256',
        'origen_legado_meta',
        'diseno',
        'schema_version',
        'es_plantilla_qa',
        'variante_qa_id',
    ];

    protected $casts = [
        'diseno' => 'array',
        'origen_legado_meta' => 'array',
        'schema_version' => 'integer',
        'es_plantilla_qa' => 'boolean',
        'archivada_at' => 'datetime',
    ];

    // Las columnas del archivo (archivada_at, archivada_por, motivo_archivo, sucesora_id) NO son asignables en masa: solo las escribe ArchivoPlantillas.

    /** ¿Existe el archivo de plantillas en esta base de datos? (la migración 2026_11_04 es aditiva: sin ella todas las plantillas cuentan como vigentes) */
    public static function archivoDisponible(): bool
    {
        return Schema::hasColumn('cf_plantillas', 'archivada_at');
    }

    /** Archivada: se conserva entera, pero no se ofrece para bases nuevas ni genera emisiones nuevas. */
    public function estaArchivada(): bool
    {
        return $this->getAttribute('archivada_at') !== null;
    }

    /** Solo las plantillas vigentes (no archivadas). Sin la columna, todas lo son. */
    public function scopeVigentes(Builder $query): Builder
    {
        return self::archivoDisponible() ? $query->whereNull($query->qualifyColumn('archivada_at')) : $query;
    }

    /** Variante de pruebas internas (QA): solo se usa a través de su asociación con una plantilla oficial, nunca como plantilla de una base. */
    public function esPlantillaQa(): bool
    {
        return (bool) ($this->getAttribute('es_plantilla_qa') ?? false);
    }

    /** Solo las plantillas que pueden ser la plantilla OFICIAL de una base (excluye las variantes QA). Sin la columna, todas lo son. */
    public function scopeOficiales(Builder $query): Builder
    {
        return Schema::hasColumn('cf_plantillas', 'es_plantilla_qa') ? $query->where($query->qualifyColumn('es_plantilla_qa'), false) : $query;
    }

    /** Plantilla vigente indicada como reemplazo al archivar (informativa). */
    public function sucesora(): BelongsTo
    {
        return $this->belongsTo(self::class, 'sucesora_id')->withTrashed();
    }

    /** Lotes de participantes que usan esta plantilla (los eliminados no cuentan). */
    public function lotes(): HasMany
    {
        return $this->hasMany(Lote::class);
    }

    /** Emisiones (de cualquier estado) hechas con esta plantilla: nunca deben perder su PDF base. */
    public function emisiones(): HasMany
    {
        return $this->hasMany(Emision::class);
    }

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
