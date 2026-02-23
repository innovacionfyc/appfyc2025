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
                'documento_legal' => 'Cédula de Ciudadanía',
                'sigla' => 'CC',
                'codigo_dian' => '13'
            ],
            [
                'documento_legal' => 'Registro Civil',
                'sigla' => 'RC',
                'codigo_dian' => '11'
            ],
            [
                'documento_legal' => 'Tarjeta de Identidad',
                'sigla' => 'TI',
                'codigo_dian' => '12'
            ],
            [
                'documento_legal' => 'Tarjeta de Extranjería',
                'sigla' => 'TE',
                'codigo_dian' => '21'
            ],
            [
                'documento_legal' => 'Cédula de Extranjería',
                'sigla' => 'CE',
                'codigo_dian' => '22'
            ],
            [
                'documento_legal' => 'NIT (Número de Identificación Tributaria)',
                'sigla' => 'NIT',
                'codigo_dian' => '31'
            ],
            [
                'documento_legal' => 'Pasaporte',
                'sigla' => 'PP',
                'codigo_dian' => '41'
            ],
            [
                'documento_legal' => 'Documento de identificación extranjero',
                'sigla' => 'DIE',
                'codigo_dian' => '42'
            ],
            [
                'documento_legal' => 'PEP (Permiso Especial de Permanencia)',
                'sigla' => 'PEP',
                'codigo_dian' => '47'
            ],
            [
                'documento_legal' => 'PPT (Permiso por Protección Temporal)',
                'sigla' => 'PPT',
                'codigo_dian' => '48'
            ],
            [
                'documento_legal' => 'NIT de otro país',
                'sigla' => 'NIT_EXT',
                'codigo_dian' => '50'
            ],
            [
                'documento_legal' => 'NUIP',
                'sigla' => 'NUIP',
                'codigo_dian' => '91'
            ],
        ];

        foreach ($documentos as $doc) {
            DB::table('tipos_documento')->updateOrInsert(
                ['codigo_dian' => $doc['codigo_dian']],
                [
                    'documento_legal' => $doc['documento_legal'],
                    'sigla' => $doc['sigla'],
                    'created_at' => now(),
                    'updated_at' => now(),
                    'deleted_at' => null,
                ]
            );
        }
    }
}