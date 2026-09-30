<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cf_participantes')) {
            return;
        }

        Schema::create('cf_participantes', function (Blueprint $table) {
            $table->id();

            // cascadeOnDelete solo protege ante un borrado físico; la interfaz usa soft delete.
            $table->foreignId('lote_id')->constrained('cf_lotes')->cascadeOnDelete();

            // Valores ya normalizados: lo guardado es lo que se imprime.
            $table->string('nombre_completo', 100);
            $table->string('documento', 40);
            // documento en mayúsculas y solo letras/dígitos: únicamente para detectar duplicados.
            $table->string('documento_clave', 40);

            // Fila del archivo de origen (trazabilidad); null en altas manuales.
            $table->unsignedInteger('fila_origen')->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            // Sin UNIQUE físico: con soft delete no serviría. Los duplicados se controlan en la aplicación.
            $table->index(['lote_id', 'documento_clave']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_participantes');
    }
};
