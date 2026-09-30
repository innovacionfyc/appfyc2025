<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cf_emisiones')) {
            return;
        }

        Schema::create('cf_emisiones', function (Blueprint $table) {
            $table->id();

            // Código público de la emisión: 20 caracteres Crockford Base32 aleatorios (~100 bits). No deriva del id.
            $table->char('codigo', 20)->unique();

            $table->foreignId('participante_id')->constrained('cf_participantes')->restrictOnDelete();
            $table->foreignId('lote_id')->constrained('cf_lotes')->restrictOnDelete();
            $table->foreignId('plantilla_id')->constrained('cf_plantillas')->restrictOnDelete();

            // Versión por participante: 1, 2, 3… (nunca se reutiliza). `reemplaza_id` apunta a la emisión sustituida.
            $table->unsignedInteger('version');
            $table->foreignId('reemplaza_id')->nullable()->constrained('cf_emisiones')->restrictOnDelete();

            // V1: 'emitida' | 'revocada'.
            $table->string('estado', 12)->default('emitida');

            // BARRERA DE UNICIDAD, no una relación: vale `participante_id` mientras la emisión está vigente y NULL
            // al revocarse. Al ser UNIQUE (y admitir varios NULL) la base de datos impide dos emisiones vigentes
            // del mismo participante, aunque dos peticiones lleguen a la vez.
            $table->unsignedBigInteger('participante_vigente')->nullable()->unique();

            // Snapshot inmutable de lo que produjo el PDF.
            $table->json('datos_snapshot');
            $table->json('diseno_snapshot');
            $table->unsignedInteger('schema_version');
            $table->char('plantilla_pdf_hash', 64);
            $table->json('generador_snapshot');

            // PDF persistido en el disco privado. El SHA-256 es evidencia de integridad, no una firma.
            $table->string('pdf_archivo');
            $table->char('pdf_hash', 64);
            $table->unsignedBigInteger('pdf_bytes');

            $table->timestamp('emitido_at');
            $table->unsignedBigInteger('emitido_por')->nullable();
            $table->timestamp('revocado_at')->nullable();
            $table->unsignedBigInteger('revocado_por')->nullable();
            $table->text('motivo_revocacion')->nullable();

            // Agrupa las emisiones creadas por una misma acción masiva.
            $table->uuid('operacion')->nullable()->index();

            $table->timestamps();

            $table->index('participante_id');
            $table->index('plantilla_id');
            $table->index(['lote_id', 'estado']);
            // Sin softDeletes: una emisión nunca se borra.
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_emisiones');
    }
};
