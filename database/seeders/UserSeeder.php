<?php

namespace Database\Seeders;

use App\Models\User;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class UserSeeder extends Seeder
{
    public function run(): void
    {
        $adminRole = Role::where('name', 'super-admin')->first();

        User::create([
            'name' => 'Admin Maestro F&C',
            'email' => 'admin@fycconsultores.com',
            'password' => Hash::make('Nikole123'),
            'role_id' => $adminRole->id,
            'is_active' => true,
            'email_verified_at' => now(),
        ]);
    }
}