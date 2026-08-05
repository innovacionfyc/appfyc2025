<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
       Schema::table('eventos', function (Blueprint $table) {
            // Añadimos los nuevos campos de precios (con valor por defecto 0 o nullable)
            $table->decimal('precio_modulo_virtual', 10, 2)->nullable()->default(0)->after('precio_modulo');
            $table->decimal('precio_cng_virtual', 10, 2)->nullable()->default(0)->after('precio_cng');
            $table->decimal('precio_curso_intensivo_hibrido', 10, 2)->nullable()->default(0)->after('precio_curso_intensivo');
            $table->decimal('precio_curso_intensivo_virtual', 10, 2)->nullable()->default(0)->after('precio_curso_intensivo_hibrido');
            $table->decimal('precio_diplomado_hibrido', 10, 2)->nullable()->default(0)->after('precio_diplomado');
            $table->decimal('precio_diplomado_virtual', 10, 2)->nullable()->default(0)->after('precio_diplomado_hibrido');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
       Schema::table('eventos', function (Blueprint $table) {
            $table->dropColumn([
                'precio_modulo_virtual',
                'precio_cng_virtual',
                'precio_curso_intensivo_hibrido',
                'precio_curso_intensivo_virtual',
                'precio_diplomado_hibrido',
                'precio_diplomado_virtual',
            ]);
        });
    }
};
