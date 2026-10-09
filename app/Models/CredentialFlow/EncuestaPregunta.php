<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/** Pregunta de una versión. Tipos: opcion_unica, opcion_multiple, texto, escala (no hay más). */
class EncuestaPregunta extends Model
{
    public const OPCION_UNICA = 'opcion_unica';

    public const OPCION_MULTIPLE = 'opcion_multiple';

    public const TEXTO = 'texto';

    public const ESCALA = 'escala';

    public const TIPOS = [self::OPCION_UNICA, self::OPCION_MULTIPLE, self::TEXTO, self::ESCALA];

    protected $table = 'cf_encuestas_preguntas';

    protected $guarded = [];

    protected $casts = ['obligatoria' => 'boolean', 'activa' => 'boolean', 'configuracion' => 'array'];

    public function opciones(): HasMany
    {
        return $this->hasMany(EncuestaOpcion::class, 'pregunta_id')->orderBy('orden');
    }

    public function esDeOpciones(): bool
    {
        return in_array($this->tipo, [self::OPCION_UNICA, self::OPCION_MULTIPLE], true);
    }
}
