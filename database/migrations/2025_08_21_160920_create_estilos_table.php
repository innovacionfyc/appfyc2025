<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('estilos', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_relacion');
            $table->string('primary');
            $table->string('secondary');
            $table->string('icono')->default('function');
            $table->timestamps();
             $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estilos');
    }
};
