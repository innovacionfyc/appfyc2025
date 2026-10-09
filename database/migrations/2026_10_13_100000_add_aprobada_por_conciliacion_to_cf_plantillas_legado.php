<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10B-1: marca ADMINISTRATIVA de una entrada del catálogo de plantillas históricas. Una entrada con `aprobada_por_conciliacion_id`
 * puede usarse para renderizar con el TIPO REAL de su contenido (MIME), por decisión de un administrador registrada en el caso de
 * conciliación. Es aditiva y nullable: NO se modifican `estado`, `extension_original`, `renderizable`, `motivo_no_renderizable` ni las
 * notas (la evidencia histórica queda tal cual). Quitar la marca revierte la decisión. FK RESTRICT: un caso con una aprobación vigente
 * no puede borrarse.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_plantillas_legado', function (Blueprint $table) {
            $table->foreignId('aprobada_por_conciliacion_id')->nullable()->after('notas')->constrained('cf_conciliaciones')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cf_plantillas_legado', function (Blueprint $table) {
            $table->dropConstrainedForeignId('aprobada_por_conciliacion_id');
        });
    }
};
