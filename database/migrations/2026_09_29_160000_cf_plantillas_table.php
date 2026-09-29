<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// F&C Credential Flow — Fase 1: plantillas de certificado (PDF base privado + diseño en JSON).
return new class extends Migration {
    public function up(): void
    {
        if (Schema::hasTable('cf_plantillas')) {
            return;
        }

        Schema::create('cf_plantillas', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 200);
            $table->text('descripcion')->nullable();

            // Ruta relativa en el disco privado: credential-flow/plantillas/{id}/base.pdf
            $table->string('archivo_pdf');
            // Solo informativo (nunca se usa como nombre físico)
            $table->string('nombre_archivo_original');
            $table->char('hash_sha256', 64);

            // Diseño del editor (elementos, posiciones, estilos). Se llena en fases posteriores.
            $table->json('diseno')->nullable();
            $table->unsignedInteger('schema_version')->default(1);

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('cf_plantillas')) {
            return;
        }

        Schema::dropIfExists('cf_plantillas');
    }
};
