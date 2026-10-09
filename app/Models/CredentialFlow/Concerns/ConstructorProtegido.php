<?php

namespace App\Models\CredentialFlow\Concerns;

use Illuminate\Database\Eloquent\Builder;
use LogicException;

/**
 * Constructor Eloquent que NIEGA los borrados y/o actualizaciones EN LOTE (`Modelo::query()->delete()`, `$relacion->delete()`), que se
 * saltan los eventos del modelo. Protege la bitácora de conciliación (solo añadir) y los casos (nunca se eliminan). Solo cubre Eloquent:
 * el SQL directo y el rollback técnico (por SQL, en su comando) quedan fuera a propósito.
 */
class ConstructorProtegido extends Builder
{
    private bool $sinBorrar = false;

    private bool $sinActualizar = false;

    public function protegiendo(bool $borrar, bool $actualizar): static
    {
        $this->sinBorrar = $borrar;
        $this->sinActualizar = $actualizar;

        return $this;
    }

    public function delete()
    {
        if ($this->sinBorrar) {
            throw new LogicException('Este registro no se elimina desde la aplicación.');
        }

        return parent::delete();
    }

    public function forceDelete()
    {
        return $this->delete();
    }

    public function update(array $values)
    {
        if ($this->sinActualizar) {
            throw new LogicException('Este registro es de solo añadir: no se modifica.');
        }

        return parent::update($values);
    }
}
