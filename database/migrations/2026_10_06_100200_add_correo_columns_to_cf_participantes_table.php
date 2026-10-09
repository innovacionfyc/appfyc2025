<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correo por participante (para el acceso del portal y los envíos). Todo nullable: los participantes actuales no
 * cambian y el correo NO forma parte de lo impreso, por eso no entra en la huella del snapshot de una emisión.
 *
 * Los índices por documento_clave sirven al portal: «documento + correo → participantes que coinciden».
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->string('correo', 254)->nullable()->after('documento_clave');
            // Minúsculas y sin espacios: solo para comparar.
            $table->string('correo_normalizado', 254)->nullable()->after('correo');
            // valido | multiple | invalido | sin_correo
            $table->string('correo_estado', 12)->nullable()->after('correo_normalizado');

            $table->index('documento_clave', 'cf_participantes_documento_clave_idx');
            $table->index(['documento_clave', 'correo_normalizado'], 'cf_participantes_doc_correo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->dropIndex('cf_participantes_doc_correo_idx');
            $table->dropIndex('cf_participantes_documento_clave_idx');
            $table->dropColumn(['correo', 'correo_normalizado', 'correo_estado']);
        });
    }
};
