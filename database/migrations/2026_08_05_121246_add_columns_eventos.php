<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Columnas nuevas de precios y la columna existente tras la cual se ubica cada una.
     */
    private array $columnas = [
        'precio_modulo_virtual' => 'precio_modulo',
        'precio_cng_virtual' => 'precio_cng',
        'precio_curso_intensivo_hibrido' => 'precio_curso_intensivo',
        'precio_curso_intensivo_virtual' => 'precio_curso_intensivo_hibrido',
        'precio_diplomado_hibrido' => 'precio_diplomado',
        'precio_diplomado_virtual' => 'precio_diplomado_hibrido',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('eventos')) {
            return;
        }

        // Cada columna se agrega solo si no existe, para que la migración no falle
        // si en producción ya se ejecutó un ALTER TABLE manual equivalente.
        foreach ($this->columnas as $columna => $despuesDe) {
            if (Schema::hasColumn('eventos', $columna)) {
                continue;
            }

            Schema::table('eventos', function (Blueprint $table) use ($columna, $despuesDe) {
                $definicion = $table->decimal($columna, 10, 2)->nullable()->default(0);

                if (Schema::hasColumn('eventos', $despuesDe)) {
                    $definicion->after($despuesDe);
                }
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('eventos')) {
            return;
        }

        foreach (array_keys($this->columnas) as $columna) {
            if (! Schema::hasColumn('eventos', $columna)) {
                continue;
            }

            Schema::table('eventos', function (Blueprint $table) use ($columna) {
                $table->dropColumn($columna);
            });
        }
    }
};
