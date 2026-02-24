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
        Schema::create('formularios_inscripcion', function (Blueprint $table) {
            $table->id();
            
            $table->string('tipo_persona');
            $table->string('nombres');
            $table->string('apellidos');
            $table->string('cedula');
            $table->string('cargo')->nullable();
            $table->string('entidad_empresa')->nullable();
            $table->string('celular');
            $table->string('ciudad');
            $table->string('correo_personal');
            $table->string('correo_corporativo')->nullable();
            $table->string('modo_asistencia');
            $table->string('soporte_asistencia')->nullable();
            $table->boolean('politica_datos')->default(false);
            $table->string('medio_reconocimiento')->nullable();

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
        Schema::dropIfExists('formularios_inscripcion');

    }
};
