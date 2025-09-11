<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_xx_xx_xxxxxx_create_inventory_items_table.php
public function up(): void
{
    Schema::create('inventory_items', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // Ej: "Carne de Hamburguesa", "Pan Brioche", "Tomate"
        $table->string('unit'); // Ej: "gramos", "unidades", "litros"
        $table->decimal('stock', 10, 2);
        $table->decimal('low_stock_threshold', 10, 2)->default(0); // Umbral para alertas
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('inventory_items');
    }
};
