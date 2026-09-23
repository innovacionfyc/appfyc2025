<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('podcast_temporadas')) {
            return;
        }

        Schema::create('podcast_temporadas', function (Blueprint $table) {
            $table->id();

            $table->unsignedSmallInteger('numero')->unique();
            $table->string('titulo', 200);
            $table->string('slug', 220)->unique();
            $table->text('descripcion')->nullable();
            $table->string('imagen_portada')->nullable();

            // Sin default: el ID de "Borrador" no está fijado en código (solo por orden del
            // seeder), así que el estado inicial se resuelve por nombre desde la aplicación.
            $table->foreignId('estado_id')
                  ->constrained('estados')
                  ->onDelete('restrict');

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('podcast_temporadas')) {
            return;
        }

        Schema::dropIfExists('podcast_temporadas');
    }
};
