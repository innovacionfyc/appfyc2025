<?php

namespace Database\Seeders;

use App\Models\Estado;
use Illuminate\Database\Seeder;

class EstadosSeeder extends Seeder
{
    public function run(): void
    {
        $estados = [
            // --- 1. NÚCLEO SISTÉMICO (General) ---
            ['tipo' => 'Activo', 'cat' => 'General'],
            ['tipo' => 'Inactivo', 'cat' => 'General'],
            ['tipo' => 'Suspendido', 'cat' => 'General'],
            ['tipo' => 'Eliminado', 'cat' => 'General'],
            ['tipo' => 'Archivado', 'cat' => 'General'],
            ['tipo' => 'Borrador', 'cat' => 'General'],
            ['tipo' => 'En Revisión', 'cat' => 'General'],

        ];

        foreach ($estados as $est) {
            Estado::updateOrCreate(
                [
                    'tipo_estado' => $est['tipo'],
                    'categoria_estado' => $est['cat']
                ],
                [
                    'updated_at' => now(),
                ]
            );
        }
    }
}