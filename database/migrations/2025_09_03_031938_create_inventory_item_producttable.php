<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_xx_xx_xxxxxx_create_product_inventory_item_table.php
public function up(): void
{
    Schema::create('inventory_item_product', function (Blueprint $table) {
        $table->id();
        $table->foreignId('product_id')->constrained('products')->onDelete('cascade');
        $table->foreignId('inventory_item_id')->constrained('inventory_items')->onDelete('cascade');
        $table->decimal('quantity_used', 8, 2); // Cantidad de ingrediente por producto
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('product_inventory_item');
    }
};
