<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('podcast_reacciones')) {
            return;
        }

        Schema::create('podcast_reacciones', function (Blueprint $table) {
            $table->id();

            $table->foreignId('episodio_id')
                  ->constrained('podcast_episodios')
                  ->onDelete('cascade');

            $table->string('tipo', 20)->default('me_gusta');

            // Identidad anónima: HMAC (APP_KEY) de un UUID en cookie firmada. Nunca IP en claro.
            $table->string('fingerprint', 64);

            $table->timestamps();

            $table->unique(['episodio_id', 'tipo', 'fingerprint']);
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('podcast_reacciones')) {
            return;
        }

        Schema::dropIfExists('podcast_reacciones');
    }
};
