<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Usuario;
use App\Models\PerfilOrganizador;
use App\Models\Rol;
use App\Models\Estado;
use App\Models\TipoDocumento;
use App\Models\AreaFormacion;
use App\Models\EquipoFyc;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {
        $estadoActivo = Estado::firstOrCreate(
            ['tipo_estado' => 'General', 'categoria_estado' => 'Activo']
        );

        $tipoDocCC = TipoDocumento::firstOrCreate(
            ['documento_legal' => 'Cédula de Ciudadanía', 'sigla' => 'CC', 'codigo_dian' => '13']
        );

        $areaGerencia = AreaFormacion::firstOrCreate(
            ['nombre' => 'Gerencia General', 'estado_id' => $estadoActivo->id]
        );

        $areaOperaciones = AreaFormacion::firstOrCreate(
            ['nombre' => 'Operaciones y Comercial', 'estado_id' => $estadoActivo->id]
        );

        $equipoFyc = EquipoFyc::firstOrCreate(
            ['nombre' => 'F&C Consultores', 'slug' => 'fyc-consultores', 'estado_id' => $estadoActivo->id]
        );

        $rolSuperAdmin = Rol::where('slug', 'super-admin')->firstOrFail();
        $rolAdmin = Rol::where('slug', 'admin')->firstOrFail();
        $rolComercial = Rol::where('slug', 'comercial')->firstOrFail();

        $passwordDefault = Hash::make('Admin123*');

        $userSuperAdmin = Usuario::create([
            'estado_id'        => $estadoActivo->id,
            'perfil_completo'  => true,
            'correo_principal' => 'jhoann@fycconsultores.com',
            'numero_documento' => '1000000001',
            'contrasena'       => $passwordDefault,
        ]);

        PerfilOrganizador::create([
            'usuario_id'           => $userSuperAdmin->id,
            'rol_id'               => $rolSuperAdmin->id,
            'area_encargada_id'    => $areaGerencia->id,
            'equipo_id'            => $equipoFyc->id,
            'tipo_documento_id'    => $tipoDocCC->id,
            'primer_nombre'        => 'Jhoann',
            'segundo_nombre'       => 'Sebastián',
            'primer_apellido'      => 'Zamudio',
            'segundo_apellido'     => 'Marulanda',
            'cargo'                => 'CEO',
            'telefono_personal'    => '3000000001',
            'correo_corporativo'   => 'jhoann.gerencia@fycconsultores.com',
            'numero_documento'     => '1000000001',
        ]);

        $userAdmin = Usuario::create([
            'estado_id'        => $estadoActivo->id,
            'perfil_completo'  => true,
            'correo_principal' => 'erik@fycconsultores.com',
            'numero_documento' => '1000000002',
            'contrasena'       => $passwordDefault,
        ]);

        PerfilOrganizador::create([
            'usuario_id'           => $userAdmin->id,
            'rol_id'               => $rolAdmin->id,
            'area_encargada_id'    => $areaOperaciones->id,
            'equipo_id'            => $equipoFyc->id,
            'tipo_documento_id'    => $tipoDocCC->id,
            'primer_nombre'        => 'Erik',
            'segundo_nombre'       => null,
            'primer_apellido'      => 'Guevara',
            'segundo_apellido'     => 'Ladino',
            'cargo'                => 'Administrador de Operaciones',
            'telefono_personal'    => '3000000002',
            'correo_corporativo'   => 'erik.admin@fycconsultores.com',
            'numero_documento'     => '1000000002',
        ]);

        $userComercial = Usuario::create([
            'estado_id'        => $estadoActivo->id,
            'perfil_completo'  => true,
            'correo_principal' => 'ale@fycconsultores.com',
            'numero_documento' => '1000000003',
            'contrasena'       => $passwordDefault,
        ]);

        PerfilOrganizador::create([
            'usuario_id'           => $userComercial->id,
            'rol_id'               => $rolComercial->id,
            'area_encargada_id'    => $areaOperaciones->id,
            'equipo_id'            => $equipoFyc->id,
            'tipo_documento_id'    => $tipoDocCC->id,
            'primer_nombre'        => 'Ericka',
            'segundo_nombre'       => 'Alexandra',
            'primer_apellido'      => 'Gomez',
            'segundo_apellido'     => 'Jímenez',
            'cargo'                => 'Ejecutiva Comercial',
            'telefono_personal'    => '3000000003',
            'correo_corporativo'   => 'ale.comercial@fycconsultores.com',
            'numero_documento'     => '1000000003',
        ]);
    }
}