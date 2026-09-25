<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('podcast_episodios')) {
            return;
        }

        Schema::create('podcast_episodios', function (Blueprint $table) {
            $table->id();

            $table->foreignId('temporada_id')
                  ->constrained('podcast_temporadas')
                  ->onDelete('cascade');

            $table->unsignedSmallInteger('numero');
            $table->string('titulo', 220);
            $table->string('slug', 240)->unique();

            $table->string('invitado_nombre', 150);
            $table->string('invitado_cargo', 200)->nullable();
            $table->string('invitado_foto')->nullable();

            $table->text('descripcion');
            $table->date('fecha_publicacion')->nullable();

            // El video vive en YouTube: solo se guarda el ID (11 caracteres) y la URL original pegada.
            $table->string('youtube_video_id', 20);
            $table->string('youtube_url', 500);
            $table->string('imagen_miniatura')->nullable();
            $table->unsignedInteger('duracion_segundos')->nullable();

            $table->boolean('destacado')->default(false);

            $table->foreignId('estado_id')
                  ->constrained('estados')
                  ->onDelete('restrict');

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->unique(['temporada_id', 'numero']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('podcast_episodios')) {
            return;
        }

        Schema::dropIfExists('podcast_episodios');
    }
};
