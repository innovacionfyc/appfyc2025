<?php

// database/seeders/CategorySeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;

class CategorySeeder extends Seeder
{
    public function run(): void
    {
        Category::create(['name' => 'Entradas']);
        Category::create(['name' => 'Platos Fuertes']);
        Category::create(['name' => 'Bebidas']);
        Category::create(['name' => 'Postres']);
        Category::create(['name' => 'Cafetería']);
    }
}
