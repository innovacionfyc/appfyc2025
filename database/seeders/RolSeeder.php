<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RolSeeder extends Seeder
{
    /**
     * Ejecuta los seeds de la base de datos.
     */
    public function run(): void
    {
        $roles = [
            [
                'tipo_rol' => 'Super Administrador',
                'slug' => 'super-admin',
                'descripcion' => 'Acceso total y absoluto al sistema. Puede gestionar usuarios, catálogos y configuraciones globales.',
                'permisos' => ['acceso_total'],
            ],
            [
                'tipo_rol' => 'Administrador',
                'slug' => 'admin',
                'descripcion' => 'Gestión completa de la plataforma de eventos, contenidos temáticos y asignación de conferencistas.',
                'permisos' => [
                    'crear_evento',
                    'editar_evento',
                    'eliminar_evento',
                    'gestionar_conferencistas',
                    'ver_reportes_globales'
                ],
            ],
            [
                'tipo_rol' => 'Comercial',
                'slug' => 'comercial',
                'descripcion' => 'Encargado de la gestión de ventas, seguimiento de formularios de inscripción y atención al cliente.',
                'permisos' => [
                    'ver_eventos',
                    'gestionar_inscripciones',
                    'exportar_asistentes',
                    'ver_reportes_ventas'
                ],
            ],
        ];

        foreach ($roles as $rol) {
            Rol::create($rol);
        }
    }
}