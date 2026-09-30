<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cf_lotes')) {
            return;
        }

        Schema::create('cf_lotes', function (Blueprint $table) {
            $table->id();

            $table->foreignId('plantilla_id')->constrained('cf_plantillas')->restrictOnDelete();

            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();

            // Snapshot de los datos comunes: evento, fecha, intensidad_horaria (claves de CamposDinamicos).
            // No referencia la tabla `eventos`: si el evento cambia, el lote no cambia.
            $table->json('datos_comunes');

            // Solo informativos (el archivo original NO se conserva): nombre saneado y SHA-256.
            $table->string('archivo_nombre', 150)->nullable();
            $table->char('archivo_hash', 64)->nullable();

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('created_at');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_lotes');
    }
};
