<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archivo de plantillas (C2): una plantilla ARCHIVADA deja de ofrecerse para bases nuevas y no genera emisiones nuevas, pero se conserva entera (diseño, PDF base,
 * bases asociadas, emisiones y sus PDF). No es una eliminación: la fila sigue «viva» (sin deleted_at) y se puede restaurar.
 *
 * Aditiva e idempotente: cuatro columnas NULL. Ninguna plantilla existente cambia (todas quedan vigentes: archivada_at NULL). No toca bases, emisiones ni PDF.
 * Reversión: `down()` quita las columnas (solo si ninguna plantilla está archivada se puede revertir sin perder el registro del archivo; la auditoría queda en
 * movimientos de todos modos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('cf_plantillas') || Schema::hasColumn('cf_plantillas', 'archivada_at')) {
            return;
        }

        Schema::table('cf_plantillas', function (Blueprint $table) {
            // Momento del archivo (NULL = vigente). TIMESTAMP NULL: sin valor por defecto implícito en MariaDB.
            $table->timestamp('archivada_at')->nullable();
            // Administrador (super-admin) que la archivó; como emitido_por/revocado_por, sin clave foránea a la tabla de usuarios.
            $table->unsignedBigInteger('archivada_por')->nullable();
            $table->string('motivo_archivo', 500)->nullable();
            // Plantilla vigente que la reemplaza (opcional e informativa: no cambia ninguna base por sí sola).
            $table->unsignedBigInteger('sucesora_id')->nullable();
            $table->index('archivada_at');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cf_plantillas', 'archivada_at')) {
            return;
        }

        Schema::table('cf_plantillas', function (Blueprint $table) {
            $table->dropIndex(['archivada_at']);
            $table->dropColumn(['archivada_at', 'archivada_por', 'motivo_archivo', 'sucesora_id']);
        });
    }
};
