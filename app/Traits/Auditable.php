<?php

// app/Traits/Auditable.php

namespace App\Traits;

use Illuminate\Support\Facades\Auth;
use Core\Models\Auditoria;

trait Auditable
{
    public static function bootAuditable()
    {
        static::created(function ($model) {
            Auditoria::crearRegistro('create', $model);
        });

        static::updated(function ($model) {
            Auditoria::crearRegistro('update', $model, $model->getOriginal(), $model->getChanges());
        });

        static::deleted(function ($model) {
            Auditoria::crearRegistro('delete', $model);
        });
    }
}
