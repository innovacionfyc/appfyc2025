<?php

namespace Database\Seeders;
use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    
    public function run(): void
    {
        $this->call([
            EstadosSeeder::class,
            TipoDocumentosSeeder::class,
            AreaFormacionSeeder::class,
            EquipoSeeder::class,
            RolSeeder::class,
            UsuarioSeeder::class,
        ]);
    }
}
