<?php

namespace App\Models\CredentialFlow\Concerns;

use App\Models\CredentialFlow\Correo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Collection;

/**
 * Helpers de lectura para los propietarios de correos (Participante y CertificadoLegado). Usan la relación `correos`
 * (cárgala con ->with('correos') al recorrer muchos propietarios). No eligen nunca un correo «principal» por su cuenta.
 */
trait TieneCorreos
{
    abstract public function correos(): HasMany;

    /** @return Collection<int,Correo> */
    public function correosValidos(): Collection
    {
        return $this->correos->where('estado', Correo::ESTADO_VALIDO)->values();
    }

    /**
     * El correo cuando hay EXACTAMENTE uno válido; null si hay ninguno o varios (con varios no se elige ninguno: la
     * coincidencia por documento + correo debe mirar todos los válidos).
     */
    public function correoUnicoUtilizable(): ?Correo
    {
        $validos = $this->correosValidos();

        return $validos->count() === 1 ? $validos->first() : null;
    }
}
