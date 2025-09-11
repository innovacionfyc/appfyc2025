<?php

// database/seeders/TableSeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Table;

class TableSeeder extends Seeder
{
    public function run(): void
    {
        for ($i = 1; $i <= 10; $i++) {
            Table::create(['name' => 'Mesa ' . $i]);
        }
        Table::create(['name' => 'Barra 1']);
        Table::create(['name' => 'Barra 2']);
    }
}