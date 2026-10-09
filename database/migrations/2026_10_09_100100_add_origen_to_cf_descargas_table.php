<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Distingue las descargas IMPORTADAS del sistema viejo (`legado_importado`, el valor por defecto: todas las filas existentes lo son)
 * de las NUEVAS registradas por Credential Flow (`credential_flow`, p. ej. el portal público). Una nueva descarga nunca se
 * confunde con las importadas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_descargas', function (Blueprint $table) {
            $table->string('origen', 20)->default('legado_importado')->after('via');
        });
    }

    public function down(): void
    {
        Schema::table('cf_descargas', function (Blueprint $table) {
            $table->dropColumn('origen');
        });
    }
};
