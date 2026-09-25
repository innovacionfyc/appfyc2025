<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('podcast_comentarios')) {
            return;
        }

        Schema::create('podcast_comentarios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('episodio_id')
                  ->constrained('podcast_episodios')
                  ->onDelete('cascade');

            // Autor anónimo: sin created_by/update_by. El correo nunca se muestra en público.
            $table->string('nombre', 80);
            $table->string('correo', 150)->nullable();
            $table->text('contenido');

            // pendiente | aprobado | rechazado (string, no enum)
            $table->string('estado_moderacion', 20)->default('pendiente');

            // Solo hash de la IP, nunca en claro; user agent para detectar ráfagas en moderación.
            $table->string('ip_hash', 64)->nullable();
            $table->string('user_agent', 255)->nullable();

            $table->foreignId('moderado_por')
                  ->nullable()
                  ->constrained('usuarios')
                  ->nullOnDelete();
            $table->dateTime('moderado_en')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index(['episodio_id', 'estado_moderacion']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('podcast_comentarios')) {
            return;
        }

        Schema::dropIfExists('podcast_comentarios');
    }
};
