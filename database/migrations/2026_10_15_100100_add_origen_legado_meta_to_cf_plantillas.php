<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad del DERIVADO técnico de un clon moderno de una plantilla histórica (Fase 10B-2B-2A.1): el PDF base moderno no es evidencia histórica, es
 * material de render. `origen_legado_meta` guarda, sin tocar la imagen original: versión del proceso, algoritmo (incrustación directa o rasterización),
 * MIME, SHA-256 del original y del derivado, dimensiones y bytes de ambos, fecha de generación y advertencias. NULL en las plantillas normales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_plantillas', function (Blueprint $table) {
            $table->json('origen_legado_meta')->nullable()->after('origen_legado_sha256');
        });
    }

    public function down(): void
    {
        Schema::table('cf_plantillas', function (Blueprint $table) {
            $table->dropColumn('origen_legado_meta');
        });
    }
};
