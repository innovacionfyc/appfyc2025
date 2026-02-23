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
        Schema::create('formularios_base', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_plantilla'); // Ej: "Formulario Corporativo Estándar"
            $table->enum('tipo_persona', ['Natural', 'Jurídica', 'Ambas'])->default('Ambas');

            // Configuraciones booleanas (verdadero/falso)
            $table->boolean('solicitar_cargo')->default(false);
            $table->boolean('solicitar_empresa')->default(false);
            $table->boolean('solicitar_correo_corp')->default(false);
            $table->boolean('solicitar_soporte')->default(false);
            $table->boolean('politica_datos')->default(true); // Obligatorio legalmente

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
        //
    }
};
