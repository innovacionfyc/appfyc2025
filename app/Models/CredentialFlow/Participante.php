<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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

    public function lote(): BelongsTo
    {
        return $this->belongsTo(Lote::class);
    }
}
