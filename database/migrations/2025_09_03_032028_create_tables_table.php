<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    // database/migrations/xxxx_xx_xx_xxxxxx_create_tables_table.php
public function up(): void
{
    Schema::create('tables', function (Blueprint $table) {
        $table->id();
        $table->string('name'); // Ej: "Mesa 1", "Barra 2"
        $table->string('status')->default('available'); // available, occupied, reserved
        $table->timestamps();
    });
}

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tables');
    }
};
