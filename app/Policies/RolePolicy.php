<?php

namespace App\Policies;

use App\Models\User;

class RolePolicy
{
    /**
     * Permite el acceso si el rol coincide
     */
    public function accessRole(User $user, $role)
    {
        return $user->id_rol == $role;
    }
}
