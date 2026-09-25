<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Rol;

class RolSeeder extends Seeder
{
    /**
     * Siembra los roles base del sistema usando updateOrCreate para ser idempotente.
     *
     * Convención de permisos (array JSON en roles.permisos):
     *   'acceso_total'     → bypass total. SOLO super-admin. Nunca asignar a otros roles.
     *   'slug-de-modulo'   → habilita ese módulo del catálogo (config/modulos.php).
     *   'clave-legacy'     → permisos semánticos anteriores, conservados por compatibilidad.
     *
     * Reglas:
     *   - Nunca eliminar roles desde aquí; solo crear o actualizar por slug.
     *   - El slug es inmutable una vez en producción.
     *   - Las claves legacy (crear_evento, ver_eventos, etc.) se conservan hasta que
     *     exista una pantalla administrativa que las gestione explícitamente.
     */
    public function run(): void
    {
        $roles = [

            // ── super-admin ────────────────────────────────────────────────────
            // Bypass total vía 'acceso_total'. No necesita slugs de módulo.
            [
                'slug'        => 'super-admin',
                'nombre'      => 'Super Administrador',
                'descripcion' => 'Acceso total y absoluto al sistema. Puede gestionar usuarios, roles, módulos y configuraciones globales.',
                'permisos'    => ['acceso_total'],
            ],

            // ── admin ──────────────────────────────────────────────────────────
            // Módulos habilitados: todos excepto 'equipo' y 'usuarios' (exclusivos de super-admin).
            // 'equipo' se excluye para no alterar el comportamiento visual actual del sidebar
            // (Equipo tiene roles:['super-admin'] en navItems; incluirlo aquí lo haría visible
            // vía el path de permisos.includes(item.module) en canSeeItem).
            // Permisos legacy conservados para no romper integraciones existentes.
            [
                'slug'        => 'admin',
                'nombre'      => 'Administrador',
                'descripcion' => 'Acceso completo al panel administrativo de eventos y módulos de gestión académica.',
                'permisos'    => [
                    // Slugs de módulos del catálogo (config/modulos.php)
                    'dashboard',
                    'eventos',
                    'academia',
                    'accesos-virtuales',
                    'podcast',
                    'configuracion',
                    // Permisos de acción legacy (conservados, no eliminar todavía)
                    'crear_evento',
                    'editar_evento',
                    'eliminar_evento',
                    'gestionar_conferencistas',
                    'ver_reportes_globales',
                ],
            ],

            // ── comercial ──────────────────────────────────────────────────────
            // Accede a /comercial/dashboard (su propio panel), no al panel admin.
            // No se asignan slugs de módulos admin por ahora.
            // Permisos legacy conservados íntegramente.
            [
                'slug'        => 'comercial',
                'nombre'      => 'Comercial',
                'descripcion' => 'Encargado de la gestión de ventas, seguimiento de inscripciones y atención al cliente. Accede a su propio dashboard comercial.',
                'permisos'    => [
                    // Permisos de acción legacy (conservados)
                    'ver_eventos',
                    'gestionar_inscripciones',
                    'exportar_asistentes',
                    'ver_reportes_ventas',
                ],
            ],

        ];

        foreach ($roles as $datos) {
            Rol::updateOrCreate(
                ['slug' => $datos['slug']],    // clave de búsqueda inmutable
                [
                    'nombre'      => $datos['nombre'],
                    'descripcion' => $datos['descripcion'],
                    'permisos'    => $datos['permisos'],
                ]
            );
        }
    }
}
