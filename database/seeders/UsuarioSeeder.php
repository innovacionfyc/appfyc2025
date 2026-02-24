<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use App\Models\Usuario;
use App\Models\PerfilOrganizador;

class UsuarioSeeder extends Seeder
{
    public function run(): void
    {



        $passwordDefault = Hash::make('123456');

        $userSuperAdmin = Usuario::create([
            'estado_id' => 1,
            'perfil_completo' => true,
            'correo_principal' => 'jhoann@fycconsultores.com',
            'numero_documento' => '1000000001',
            'contrasena' => $passwordDefault,
        ]);

        PerfilOrganizador::create([
            'usuario_id' => $userSuperAdmin->id,
            'rol_id' => 1,
            'area_encargada_id' => 6,
            'equipo_id' => 1,
            'tipo_documento_id' => 1,
            'primer_nombre' => 'Jhoann',
            'segundo_nombre' => 'Sebastián',
            'primer_apellido' => 'Zamudio',
            'segundo_apellido' => 'Marulanda',
            'cargo' => 'CEO',
            'telefono_personal' => '3000000001',
            'correo_corporativo' => 'jhoann.gerencia@fycconsultores.com',
            'numero_documento' => '1000000001',
        ]);

        $userAdmin = Usuario::create([
            'estado_id' => 1,
            'perfil_completo' => true,
            'correo_principal' => 'erik@fycconsultores.com',
            'numero_documento' => '1000000002',
            'contrasena' => $passwordDefault,
        ]);

        PerfilOrganizador::create([
            'usuario_id' => $userAdmin->id,
            'rol_id' => 2,
            'area_encargada_id' => 6,
            'equipo_id' => 5,
            'tipo_documento_id' => 1,
            'primer_nombre' => 'Erik',
            'segundo_nombre' => null,
            'primer_apellido' => 'Guevara',
            'segundo_apellido' => 'Ladino',
            'cargo' => 'Administrador de Operaciones',
            'telefono_personal' => '3000000002',
            'correo_corporativo' => 'erik.admin@fycconsultores.com',
            'numero_documento' => '1000000002',
        ]);

        $userComercial = Usuario::create([
            'estado_id' => 1,
            'perfil_completo' => true,
            'correo_principal' => 'ale@fycconsultores.com',
            'numero_documento' => '1000000003',
            'contrasena' => $passwordDefault,
        ]);

        PerfilOrganizador::create([
            'usuario_id' => $userComercial->id,
            'rol_id' => 3,
            'area_encargada_id' => 3,
            'equipo_id' => 1,
            'tipo_documento_id' => 1,
            'primer_nombre' => 'Ericka',
            'segundo_nombre' => 'Alexandra',
            'primer_apellido' => 'Gomez',
            'segundo_apellido' => 'Jímenez',
            'cargo' => 'Ejecutiva Comercial',
            'telefono_personal' => '3000000003',
            'correo_corporativo' => 'ale.comercial@fycconsultores.com',
            'numero_documento' => '1000000003',
        ]);
    }
}