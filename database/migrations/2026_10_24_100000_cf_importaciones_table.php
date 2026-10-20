<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Borradores de la importación inteligente (F3). Migración ADITIVA: solo crea una tabla nueva; no toca ninguna existente.
 *
 *  - `analisis` y `correcciones` guardan datos personales del Excel: viajan CIFRADOS (cast `encrypted:array`) y se borran al confirmar, rechazar o vencer.
 *  - El archivo original NO se conserva: solo su hash (para detectar el mismo archivo) y su nombre saneado.
 *  - `clave_activa` (usuario:hash) es única mientras el borrador está en revisión y NULL después: dos análisis simultáneos del mismo archivo no crean
 *    dos borradores. `lote_id` es único: una base solo puede salir de un borrador.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cf_importaciones')) {
            return;
        }

        Schema::create('cf_importaciones', function (Blueprint $table) {
            $table->id();

            $table->unsignedBigInteger('usuario_id');
            $table->string('estado', 20)->default('en_revision');
            $table->unsignedInteger('version')->default(1);
            $table->string('clave_activa', 100)->nullable()->unique();

            $table->string('archivo_nombre', 150)->nullable();
            $table->char('archivo_hash', 64);
            $table->unsignedInteger('archivo_bytes')->default(0);

            $table->longText('analisis')->nullable();
            $table->longText('correcciones')->nullable();
            $table->json('resumen')->nullable();
            $table->json('advertencias')->nullable();

            $table->foreignId('lote_id')->nullable()->unique()->constrained('cf_lotes')->nullOnDelete();
            $table->timestamp('confirmado_at')->nullable();
            $table->unsignedBigInteger('confirmado_por')->nullable();
            $table->timestamp('rechazado_at')->nullable();
            $table->timestamp('expira_at')->nullable();

            $table->timestamps();

            $table->index(['usuario_id', 'estado']);
            $table->index('archivo_hash');
            $table->index('expira_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_importaciones');
    }
};
