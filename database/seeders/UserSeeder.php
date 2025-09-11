<?php
// database/seeders/UserSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\User;
use App\Models\Role;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        // Obtener los IDs de los roles
        $adminRole = Role::where('name', 'Administrador')->first();
        $meseroRole = Role::where('name', 'Mesero')->first();
        $cocinaRole = Role::where('name', 'Cocina')->first();

        // Crear usuario Administrador
        User::create([
            'name' => 'Admin',
            'email' => 'admin@lamagistral.com',
            'password' => Hash::make('password'), // ¡Cambia esto en producción!
            'role_id' => $adminRole->id,
            'is_active' => true,
        ]);

        // Crear usuario Mesero de ejemplo
        User::create([
            'name' => 'Juan Mesero',
            'email' => 'juan.mesero@lamagistral.com',
            'password' => Hash::make('password'),
            'role_id' => $meseroRole->id,
            'is_active' => true,
        ]);

        // Crear usuario Cocina de ejemplo
        User::create([
            'name' => 'Pedro Cocinero',
            'email' => 'pedro.cocina@lamagistral.com',
            'password' => Hash::make('password'),
            'role_id' => $cocinaRole->id,
            'is_active' => true,
        ]);
    }
}
