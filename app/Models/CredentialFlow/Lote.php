<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * Lote de participantes de Credential Flow: una importación (o alta manual) asociada a una plantilla,
 * con los datos comunes (evento, fecha, intensidad horaria) guardados como snapshot.
 */
class Lote extends Model
{
    use HasAuditFields, SoftDeletes;

    protected $table = 'cf_lotes';

    protected $fillable = [
        'plantilla_id',
        'nombre',
        'descripcion',
        'datos_comunes',
        'archivo_nombre',
        'archivo_hash',
    ];

    protected $casts = [
        'datos_comunes' => 'array',
    ];

    public function plantilla(): BelongsTo
    {
        return $this->belongsTo(Plantilla::class);
    }

    public function emisiones(): HasMany
    {
        return $this->hasMany(Emision::class);
    }

    public function participantes(): HasMany
    {
        return $this->hasMany(Participante::class);
    }
}
