<?php

namespace Database\Seeders;

use App\Models\EquipoFyc;
use Illuminate\Database\Seeder;

class EquipoSeeder extends Seeder
{
    public function run(): void
    {
        $equipo = [
            ['nombre' => 'Comunicaciones', 'slug' => 'comunicaciones', 'estado_id' => 1],
            ['nombre' => 'Comercial', 'slug' => 'comercial', 'estado_id' => 1],
            ['nombre' => 'Financiero', 'slug' => 'financiero', 'estado_id' => 1],
            ['nombre' => 'Desarrollo e innovacion', 'slug' => 'desarrollo-ti', 'estado_id' => 1],
            ['nombre' => 'Calidad', 'slug' => 'calidad', 'estado_id' => 1],
        ];

        foreach ($equipo as $e) {
            EquipoFyc::create($e);
        }
    }
}