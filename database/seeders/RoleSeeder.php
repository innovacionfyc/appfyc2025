<?php

namespace Database\Seeders;

use App\Models\Role;
use Illuminate\Database\Seeder;

class RoleSeeder extends Seeder
{
    public function run(): void
    {
        $roles = [
            [
                'name' => 'super-admin',
                'display_name' => 'Director de Tecnología (CTO)',
                'description' => 'Acceso total al sistema, gestión de backups y configuración de API/DIAN.',
            ],
            [
                'name' => 'comercial',
                'display_name' => 'Gestor Comercial',
                'description' => 'Responsable de crear eventos, gestionar organizadores y ver reportes de ventas/inscritos.',
            ],
            [
                'name' => 'logistica',
                'display_name' => 'Coordinador de Logística',
                'description' => 'Gestión de formularios de inscripción, control de asistencia y activos del evento.',
            ],
            [
                'name' => 'conferencista',
                'display_name' => 'Conferencista / Speaker',
                'description' => 'Acceso limitado para gestionar su propio perfil y ver lista de asistentes a su charla.',
            ],
            [
                'name' => 'cliente',
                'display_name' => 'Usuario Final / Asistente',
                'description' => 'Usuario que se inscribe a eventos y descarga sus certificados de asistencia.',
            ],
        ];

        foreach ($roles as $role) {
            Role::updateOrCreate(['name' => $role['name']], $role);
        }
    }
}