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
            $table->foreignId('estado_id')->default(1)->constrained('estados')->onDelete('restrict');
            $table->foreignId('area_formacion_id')->constrained('areas_formacion')->onDelete('restrict');
            $table->foreignId('formulario_base_id')->nullable()->constrained('formularios_base')->onDelete('restrict');

            $table->string('modo_evento')->default('Jornada de actualización');
            $table->string('titulo');
            $table->string('slug')->unique();
            $table->string('subtitulo')->nullable();
            $table->string('imagen_relacionada')->nullable();
            $table->string('modalidad');
            $table->string('url_folleto')->nullable();
            $table->string('url_folleto_secundario')->nullable();
            $table->string('url_formulario_inscripcion');
            $table->string('ubicacion')->nullable();
            $table->dateTime('fecha_hora_inicio');
            $table->dateTime('fecha_hora_fin');
            $table->decimal('precio_jornada', 10, 2)->nullable();
            $table->decimal('precio_seminario', 10, 2)->nullable();
            $table->decimal('precio_modulo', 10, 2)->nullable();
            $table->decimal('precio_cng', 10, 2)->nullable();
            $table->decimal('precio_curso_intensivo', 10, 2)->nullable();
            $table->decimal('precio_diplomado', 10, 2)->nullable();
            $table->text('texto_dinamico')->nullable();
            $table->enum('tipo_evento', [
                'SEMINARIO',
                'JORNADA',
                'MODULO',
                'MODULO_VIRTUAL',
                'CNG',
                'CNG_VIRTUAL',
                'CURSO_INTENSIVO_VIRTUAL',
                'CURSO_INTENSIVO_HIBRIDO',
                'DIPLOMADO_VIRTUAL',
                'DIPLOMADO_HIBRIDO',
                'CI_CNG',
                'JOR_MOD',
                'CUR_CNG_MOD_DUPLA',
                'CUR_DUPLA',
                'CNG_DUPLA',
                'MOD_DUPLA'
            ])->default('JORNADA');
            $table->boolean('tiene_oferta_valor')->default(FALSE);
            $table->string('oferta_valor')->nullable()->default('N/A');
            $table->string('color_hex_secundario', 7)->nullable();
            $table->string('estilo_temario')->default('lista');
            $table->string('estilo_expertos')->default('lista');
            $table->string('estilo_card')->default('minimalista');

            $table->string('estilo_plantilla')->default('clasico');

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
