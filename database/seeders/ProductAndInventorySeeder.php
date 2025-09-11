<?php
// database/seeders/ProductAndInventorySeeder.php
namespace Database\Seeders;

use Illuminate\Database\Seeder;
use App\Models\Category;
use App\Models\Product;
use App\Models\InventoryItem;

class ProductAndInventorySeeder extends Seeder
{
    public function run(): void
    {
        // --- 1. CREAR INGREDIENTES (INVENTORY ITEMS) ---
        $carne = InventoryItem::create(['name' => 'Carne de Hamburguesa', 'unit' => 'gramos', 'stock' => 5000]);
        $pan = InventoryItem::create(['name' => 'Pan Brioche', 'unit' => 'unidades', 'stock' => 100]);
        $queso = InventoryItem::create(['name' => 'Queso Cheddar', 'unit' => 'gramos', 'stock' => 2000]);
        $gaseosa = InventoryItem::create(['name' => 'Gaseosa Postobón', 'unit' => 'unidades', 'stock' => 200]);
        $cafe = InventoryItem::create(['name' => 'Café en Grano', 'unit' => 'gramos', 'stock' => 1000]);

        // --- 2. OBTENER CATEGORÍAS ---
        $platosFuertes = Category::where('name', 'Platos Fuertes')->first();
        $bebidas = Category::where('name', 'Bebidas')->first();
        $cafeteria = Category::where('name', 'Cafetería')->first();

        // --- 3. CREAR PRODUCTOS DEL MENÚ ---
        $hamburguesa = Product::create([
            'name' => 'Hamburguesa Clásica',
            'price' => 25000,
            'category_id' => $platosFuertes->id,
        ]);

        $productoGaseosa = Product::create([
            'name' => 'Gaseosa',
            'price' => 4500,
            'category_id' => $bebidas->id,
        ]);

        $productoCafe = Product::create([
            'name' => 'Café Americano',
            'price' => 5000,
            'category_id' => $cafeteria->id,
        ]);

        // --- 4. ASOCIAR INGREDIENTES A PRODUCTOS (RECETAS) ---
        // La hamburguesa usa 150g de carne, 1 unidad de pan y 30g de queso.
        $hamburguesa->inventoryItems()->attach($carne->id, ['quantity_used' => 150]);
        $hamburguesa->inventoryItems()->attach($pan->id, ['quantity_used' => 1]);
        $hamburguesa->inventoryItems()->attach($queso->id, ['quantity_used' => 30]);

        // La gaseosa consume 1 unidad de inventario.
        $productoGaseosa->inventoryItems()->attach($gaseosa->id, ['quantity_used' => 1]);

        // El café consume 7 gramos de café en grano.
        $productoCafe->inventoryItems()->attach($cafe->id, ['quantity_used' => 7]);
    }
}