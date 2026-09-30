<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\SoftDeletes;

class Participante extends Model
{
    use HasAuditFields, SoftDeletes;

    protected $table = 'cf_participantes';

    protected $fillable = [
        'lote_id',
        'nombre_completo',
        'documento',
        'documento_clave',
        'fila_origen',
    ];

    public function emisiones(): HasMany
    {
        return $this->hasMany(Emision::class);
    }

    /** La emisión vigente (como máximo una; lo garantiza el índice único participante_vigente). */
    public function emisionVigente(): HasOne
    {
        return $this->hasOne(Emision::class)->where('estado', Emision::EMITIDA);
    }

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}
