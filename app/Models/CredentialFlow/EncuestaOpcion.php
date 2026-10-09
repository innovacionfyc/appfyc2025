<?php

namespace App\Models\CredentialFlow;

use Illuminate\Database\Eloquent\Model;

/** Opción de una pregunta de opción única/múltiple. `valor` es exacto (en las históricas, el texto contestado). */
class EncuestaOpcion extends Model
{
    protected $table = 'cf_encuestas_opciones';

    protected $guarded = [];

    protected $casts = ['activa' => 'boolean'];
}
