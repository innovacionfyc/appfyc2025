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
        Schema::create('eventos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organizador_id')->constrained('usuarios')->onDelete('restrict');
            $table->foreignId('contenido_tematico_id')->constrained('contenidos_tematicos')->onDelete('restrict');
            $table->foreignId('estado_id')->constrained('estados')->onDelete('restrict');
            $table->foreignId('area_formacion_id')->constrained('areas_formacion')->onDelete('restrict');
            $table->foreignId('formulario_base_id')->constrained('formularios_base')->onDelete('restrict');

            $table->string('titulo');
            $table->string('subtitulo')->nullable();
            $table->string('imagen_relacionada')->nullable();
            $table->string('modalidad');
            $table->string('url_folleto')->nullable();
            $table->string('ubicacion')->nullable();
            $table->dateTime('fecha_hora');
            $table->decimal('precio_jornada', 10, 2)->nullable();
            $table->decimal('precio_modulo', 10, 2)->nullable();
            $table->text('texto_dinamico')->nullable();
            $table->string('color_hex_secundario', 7)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('eventos');

    }
};
