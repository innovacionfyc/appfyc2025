<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de «participante de control (QA)»: los contactos de prueba permanentes que conviven con participantes reales en una misma base. Solo sirve para
 * separarlos de las cifras y de la descarga por evento de los participantes REALES. Aditiva (booleano con valor por defecto falso): no cambia ningún
 * participante, emisión, PDF, código ni estado existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->boolean('es_control_qa')->default(false);
            $table->index(['lote_id', 'es_control_qa'], 'cf_participantes_lote_qa_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->dropIndex('cf_participantes_lote_qa_idx');
            $table->dropColumn('es_control_qa');
        });
    }
};
