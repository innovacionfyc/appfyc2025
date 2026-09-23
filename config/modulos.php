<?php

/**
 * Catálogo central de módulos administrativos del sistema.
 *
 * Cada módulo tiene un 'slug' que es la clave de referencia en:
 *   - roles.permisos  (JSON en BD): array de slugs habilitados para el rol
 *   - Sidebar.vue:    item.module para filtrado visual
 *   - Fase B (RolesController): checkboxes de módulos asignables
 *
 * Convención de permisos en roles.permisos:
 *   'acceso_total'  → bypass total; reservado exclusivamente para super-admin
 *   'slug-modulo'   → habilita ese módulo para el rol (sidebar + futura validación backend)
 *
 * Para agregar un módulo nuevo:
 *   1. Añadir entrada en 'catalogo' con slug único.
 *   2. Agregar el slug al array 'permisos' de los roles que deben tenerlo (RolSeeder.php).
 *   3. Asignar module: 'slug' en el navItem correspondiente de Sidebar.vue.
 */

return [

    /*
    |--------------------------------------------------------------------------
    | Catálogo de módulos del panel administrativo
    |--------------------------------------------------------------------------
    */
    'catalogo' => [

        [
            'slug'        => 'dashboard',
            'nombre'      => 'Dashboard',
            'descripcion' => 'Panel principal con estadísticas, agenda y actividad reciente.',
            'icono'       => 'LayoutDashboard',
            'ruta_admin'  => '/admin/dashboard',
            'asignable'   => true,   // puede asignarse a roles no super-admin
        ],

        [
            'slug'        => 'eventos',
            'nombre'      => 'Eventos',
            'descripcion' => 'Gestión completa del portafolio académico: crear, editar y archivar jornadas.',
            'icono'       => 'CalendarDays',
            'ruta_admin'  => '/admin/eventos/data',
            'asignable'   => true,
        ],

        [
            'slug'        => 'academia',
            'nombre'      => 'Academia',
            'descripcion' => 'Certificados de participación, programas académicos y memorias de eventos.',
            'icono'       => 'GraduationCap',
            'ruta_admin'  => '/admin/certificadosWeb',
            'asignable'   => true,
        ],

        [
            'slug'        => 'accesos-virtuales',
            'nombre'      => 'Accesos Virtuales',
            'descripcion' => 'Páginas públicas de acceso a reuniones virtuales (Zoom, Teams, etc.).',
            'icono'       => 'Video',
            'ruta_admin'  => '/admin/accesos-virtuales/data',
            'asignable'   => true,
        ],

        [
            'slug'        => 'podcast',
            'nombre'      => 'Podcast',
            'descripcion' => 'Temporadas y episodios del podcast Íntimamente Hablando (videos alojados en YouTube).',
            'icono'       => 'Mic',
            'ruta_admin'  => '/admin/podcast/episodios',
            'asignable'   => true,
        ],

        [
            'slug'        => 'equipo',
            'nombre'      => 'Equipo',
            'descripcion' => 'Gestión del equipo administrativo y conferencistas registrados.',
            'icono'       => 'Users',
            'ruta_admin'  => '/admin/usuarios_fyc',
            'asignable'   => true,
        ],

        [
            'slug'        => 'configuracion',
            'nombre'      => 'Configuración',
            'descripcion' => 'Configuración general de la plataforma y parámetros del sistema.',
            'icono'       => 'Settings',
            'ruta_admin'  => '/admin/configuracion',
            'asignable'   => true,
        ],

        // ── Módulos exclusivos de super-admin (asignable: false) ──────────────
        // No aparecen como opciones en la UI de gestión de roles.
        // Se documentan aquí para que el catálogo refleje la totalidad del panel.

        [
            'slug'        => 'usuarios',
            'nombre'      => 'Usuarios',
            'descripcion' => 'Gestión de usuarios del sistema, asignación de perfiles y equipos.',
            'icono'       => 'UserCog',
            'ruta_admin'  => '/admin/usuarios_fyc',
            'asignable'   => false,  // exclusivo super-admin; protegido por middleware rol:super-admin
        ],

        [
            'slug'        => 'roles-permisos',
            'nombre'      => 'Roles y Permisos',
            'descripcion' => 'Creación y edición de roles, asignación de módulos por rol.',
            'icono'       => 'ShieldCheck',
            'ruta_admin'  => '/admin/roles',
            'asignable'   => false,  // exclusivo super-admin; la Fase B implementará esta pantalla
        ],

    ],

    /*
    |--------------------------------------------------------------------------
    | Permisos reservados — no asignables desde la UI de gestión de roles
    |--------------------------------------------------------------------------
    | 'acceso_total' otorga bypass completo en VerificarRol y en el sidebar.
    | Solo debe estar presente en el rol super-admin y nunca en roles creados
    | por el usuario desde el panel.
    */
    'reservados' => [
        'acceso_total',
    ],

];
