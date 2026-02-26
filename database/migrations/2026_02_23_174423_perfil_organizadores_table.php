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
        Schema::create('perfil_organizadores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('usuario_id')->constrained('usuarios')->onDelete('cascade');
            $table->string('primer_nombre');
            $table->string('segundo_nombre')->nullable();
            $table->string('primer_apellido');
            $table->string('segundo_apellido')->nullable();
            $table->string('foto')->nullable();
            $table->string('telefono_corporativo')->nullable();
            $table->string('telefono_personal');
            $table->string('correo_corporativo')->nullable();
            $table->string('cargo');
            $table->string('numero_documento')->unique();

            // Llaves foráneas
            $table->foreignId('rol_id')->constrained('roles')->onDelete('restrict');
            $table->foreignId('area_encargada_id')->constrained('areas_formacion')->onDelete('restrict');
            $table->foreignId('equipo_id')->constrained('equipos_fyc')->onDelete('restrict');
            $table->foreignId('tipo_documento_id')->constrained('tipos_documento')->onDelete('restrict');

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
        Schema::dropIfExists('perfil_organizadores');

    }
};
