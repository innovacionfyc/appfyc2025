<?php

namespace Database\Seeders;

use App\Models\AreaFormacion;
use App\Models\Estado;
use Illuminate\Database\Seeder;

class AreaFormacionSeeder extends Seeder
{
    public function run(): void
    {
        $areas = [
            // --- 1. NÚCLEO SISTÉMICO (General) ---
            ['nombre' => 'Jurídica', 'imagen' => '', 'color_hex_principal' => '#d85c14', 'color_hex_secundario' => '#e8955c', 'estado_id' => '1'],
            ['nombre' => 'Talento Humano', 'imagen' => '', 'color_hex_principal' => '#b9338a', 'color_hex_secundario' => '#d991be', 'estado_id' => '1'],
            ['nombre' => 'Gestión y Políticas Públicas', 'imagen' => '', 'color_hex_principal' => '#d61116', 'color_hex_secundario' => '#f2939e', 'estado_id' => '1'],
            ['nombre' => 'Enfoques Misionales', 'imagen' => '', 'color_hex_principal' => '#236576', 'color_hex_secundario' => '#7dbfce', 'estado_id' => '1'],
            ['nombre' => 'Finanzas y Hacienda Pública', 'imagen' => '', 'color_hex_principal' => '#274395', 'color_hex_secundario' => '#8ca2ce', 'estado_id' => '1'],
            ['nombre' => 'General', 'imagen' => '', 'color_hex_principal' => '#253d80', 'color_hex_secundario' => '#206fb6', 'estado_id' => '1'],


        ];

         foreach ($areas as $a) {
            AreaFormacion::create($a);
        }
    }
}