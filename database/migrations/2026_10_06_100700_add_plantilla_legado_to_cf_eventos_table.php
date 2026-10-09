<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Relaciona un evento con la imagen de fondo con la que el sistema viejo lo conocía (entrada del catálogo por NOMBRE).
 * Nullable: un evento sin imagen no tiene entrada, y un evento con imagen faltante o de extensión inválida apunta a una
 * entrada `faltante` / `extension_invalida`. Los eventos con candidata NO se enlazan solos: la candidata es solo evidencia.
 * RESTRICT: la entrada del catálogo no desaparece mientras algún evento la use.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_eventos', function (Blueprint $table) {
            $table->foreignId('plantilla_legado_id')->nullable()->after('origen')
                ->constrained('cf_plantillas_legado')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cf_eventos', function (Blueprint $table) {
            $table->dropConstrainedForeignId('plantilla_legado_id');
        });
    }
};
