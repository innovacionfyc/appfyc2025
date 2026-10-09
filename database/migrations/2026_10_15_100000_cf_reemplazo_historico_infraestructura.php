<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Infraestructura del reemplazo de certificados históricos por una emisión moderna (Fase 10B-2B-2A). Dos refuerzos aditivos, sin tocar datos:
 *
 *  - `cf_certificados_legado.reemplazado_por_emision_id` UNIQUE: una emisión moderna reemplaza como máximo UN certificado histórico (cada
 *    reemplazo crea su propio participante y su propia emisión), así que el índice solo cierra la puerta a un doble vínculo. Admite varios NULL.
 *  - `cf_plantillas.origen_legado_sha256` (UNIQUE, nullable): SHA-256 del contenido histórico del que se clonó una plantilla moderna. Garantiza
 *    «un contenido histórico → un clon reutilizable» aunque dos procesos lo intenten a la vez. NULL en las plantillas normales.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_certificados_legado', function (Blueprint $table) {
            $table->unique('reemplazado_por_emision_id', 'cf_cert_legado_reemplazo_uq');
        });

        Schema::table('cf_plantillas', function (Blueprint $table) {
            $table->char('origen_legado_sha256', 64)->nullable()->after('hash_sha256');
            $table->unique('origen_legado_sha256', 'cf_plantillas_origen_legado_uq');
        });
    }

    public function down(): void
    {
        // Primero la tabla que puede fallar: sin transacciones DDL, un fallo posterior dejaría la reversión a medias.
        // La FK `reemplazado_por_emision_id` se apoya en el UNIQUE (el índice automático de la FK se descartó al crearlo): antes de quitarlo se repone un índice
        // simple con el nombre original de la FK; si no, MySQL y MariaDB rechazan el DROP (error 1553).
        Schema::table('cf_certificados_legado', function (Blueprint $table) {
            $table->index('reemplazado_por_emision_id', 'cf_certificados_legado_reemplazado_por_emision_id_foreign');
            $table->dropUnique('cf_cert_legado_reemplazo_uq');
        });

        Schema::table('cf_plantillas', function (Blueprint $table) {
            $table->dropUnique('cf_plantillas_origen_legado_uq');
            $table->dropColumn('origen_legado_sha256');
        });
    }
};
