<?php

namespace App\Models\CredentialFlow;

use App\Traits\HasAuditFields;
use Illuminate\Database\Eloquent\Model;

/** Contacto de control (QA) oficial: se incorpora automáticamente, marcado `es_control_qa`, a cada base nueva. Salen de las pruebas internas ya hechas, no se inventan. */
class ContactoQa extends Model
{
    use HasAuditFields;

    protected $table = 'cf_contactos_qa';

    protected $fillable = ['nombre_completo', 'documento', 'documento_clave', 'correo', 'correo_normalizado', 'activo'];

    protected $casts = ['activo' => 'boolean'];
}
