<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Contactos de control (QA) oficiales: las personas del equipo que se incorporan SOLAS a cada base nueva para probar la descarga y el acceso por OTP.
 * Y la marca de «plantilla de pruebas internas», con la que se emiten sus certificados (nunca con la plantilla académica del evento).
 *
 * Aditiva: una tabla nueva y una columna booleana con valor por defecto. No cambia ninguna base, participante, emisión ni plantilla existente.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_contactos_qa', function (Blueprint $table) {
            $table->id();
            $table->string('nombre_completo', 100);
            $table->string('documento', 40);
            $table->string('documento_clave', 40)->unique();
            $table->string('correo', 254);
            $table->string('correo_normalizado', 254);
            $table->boolean('activo')->default(true);
            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();
            $table->timestamps();
        });

        Schema::table('cf_plantillas', function (Blueprint $table) {
            // Designación INTERNA y explícita (no depende del nombre): esta plantilla es de pruebas internas.
            $table->boolean('es_plantilla_qa')->default(false);
            // Variante de prueba de un diseño institucional: la plantilla de pruebas (`es_plantilla_qa`) que usan los contactos QA de las bases que usan ESTA plantilla.
            $table->unsignedBigInteger('variante_qa_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('cf_plantillas', function (Blueprint $table) {
            $table->dropColumn(['es_plantilla_qa', 'variante_qa_id']);
        });
        Schema::dropIfExists('cf_contactos_qa');
    }
};
