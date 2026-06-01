<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('accesos_virtuales')) {
            return;
        }

        Schema::create('accesos_virtuales', function (Blueprint $table) {
            $table->id();

            // Nullable + nullOnDelete: si el organizador es eliminado, el acceso se conserva sin organizador
            $table->foreignId('organizador_id')
                  ->nullable()
                  ->constrained('usuarios')
                  ->nullOnDelete();

            // Consistente con el patrón del proyecto (eventos usa onDelete restrict con default 1)
            $table->foreignId('estado_id')
                  ->default(1)
                  ->constrained('estados')
                  ->onDelete('restrict');

            $table->string('nombre', 200);
            $table->string('slug', 220)->unique();
            $table->text('descripcion')->nullable();
            $table->date('fecha')->nullable();
            $table->time('hora')->nullable();
            $table->string('url_zoom', 500);
            $table->string('imagen_banner')->nullable();

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('accesos_virtuales')) {
            return;
        }

        Schema::dropIfExists('accesos_virtuales');
    }
};
