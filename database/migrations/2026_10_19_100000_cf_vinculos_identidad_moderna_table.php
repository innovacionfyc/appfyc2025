<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Vínculos EXPLÍCITOS entre el grupo de nombre de una persona en certificados MODERNOS y su grupo de nombre HISTÓRICO (misma persona, grafía distinta:
 * p. ej. un nombre histórico mal escrito y el nombre correcto en la base nueva). Tabla aditiva y aparte: no toca ninguna tabla histórica ni el portal
 * histórico; sin ella el portal se comporta exactamente como antes.
 *
 * Sin datos personales: documento y correo van como HMAC y los grupos como su `grupo_hash` técnico. El vínculo exige EVIDENCIA verificable al crearse (el
 * correo está registrado en ambos grupos del mismo documento) y justificación humana. Nunca se borra: se revoca, y `vigente_clave` (anulable y UNIQUE)
 * garantiza un solo vínculo vigente por documento y grupo moderno.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_vinculos_identidad', function (Blueprint $table) {
            $table->id();
            $table->char('documento_hash', 64);
            $table->char('grupo_moderno_hash', 64);
            $table->char('grupo_historico_hash', 64);
            $table->char('correo_hash', 64);
            $table->string('estado', 10)->default('vigente');
            $table->text('motivo');
            $table->unsignedBigInteger('creado_por');
            $table->unsignedBigInteger('revocado_por')->nullable();
            $table->timestamp('revocado_at')->nullable();
            $table->text('motivo_revocacion')->nullable();
            $table->char('vigente_clave', 64)->nullable()->unique('cf_vinc_ident_vigente_uq');
            $table->timestamps();

            $table->index(['documento_hash', 'estado'], 'cf_vinc_ident_doc_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_vinculos_identidad');
    }
};
