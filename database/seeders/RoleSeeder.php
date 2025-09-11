<?php

// database/seeders/RoleSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Role;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        Role::create(['name' => 'Administrador', 'description' => 'Acceso total al sistema']);
        Role::create(['name' => 'Mesero', 'description' => 'Toma de pedidos y gestión de mesas']);
        Role::create(['name' => 'Cocina', 'description' => 'Visualización y gestión de comandas de comida']);
        Role::create(['name' => 'Cajero', 'description' => 'Gestión de pagos y facturación']);
    }
}
