<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('contenido_conferencista', function (Blueprint $table) {
            $table->id();
            $table->foreignId('contenido_tematico_id')->constrained('contenidos_tematicos')->onDelete('cascade');
            $table->foreignId('conferencista_id')->constrained('perfil_conferencistas')->onDelete('cascade');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('contenido_conferencista');
    }
};
