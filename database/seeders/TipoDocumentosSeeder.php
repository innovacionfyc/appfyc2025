<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

class TipoDocumentosSeeder extends Seeder
{
    public function run(): void
    {
        $documentos = [
             [
                'nombre' => 'Cédula de Ciudadanía',
                'sigla' => 'CC',
                'codigo_dian' => '13'
            ],
            [
                'nombre' => 'Registro Civil',
                'sigla' => 'RC',
                'codigo_dian' => '11'
            ],
            [
                'nombre' => 'Tarjeta de Identidad',
                'sigla' => 'TI',
                'codigo_dian' => '12'
            ],
            [
                'nombre' => 'Tarjeta de Extranjería',
                'sigla' => 'TE',
                'codigo_dian' => '21'
            ],
            [
                'nombre' => 'Cédula de Extranjería',
                'sigla' => 'CE',
                'codigo_dian' => '22'
            ],
            [
                'nombre' => 'NIT (Número de Identificación Tributaria)',
                'sigla' => 'NIT',
                'codigo_dian' => '31'
            ],
            [
                'nombre' => 'Pasaporte',
                'sigla' => 'PP',
                'codigo_dian' => '41'
            ],
            [
                'nombre' => 'Documento de identificación extranjero',
                'sigla' => 'DIE',
                'codigo_dian' => '42'
            ],
            [
                'nombre' => 'PEP (Permiso Especial de Permanencia)',
                'sigla' => 'PEP',
                'codigo_dian' => '47'
            ],
            [
                'nombre' => 'PPT (Permiso por Protección Temporal)',
                'sigla' => 'PPT',
                'codigo_dian' => '48'
            ],
            [
                'nombre' => 'NIT de otro país',
                'sigla' => 'NIT_EXT',
                'codigo_dian' => '50'
            ],
            [
                'nombre' => 'NUIP',
                'sigla' => 'NUIP',
                'codigo_dian' => '91'
            ],
        ];

        foreach ($documentos as $doc) {
            DB::table('tipos_documento')->updateOrInsert(
                ['codigo_dian' => $doc['codigo_dian']],
                [
                    'nombre' => $doc['nombre'],
                    'sigla' => $doc['sigla'],
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]
            );
        }
    }
}