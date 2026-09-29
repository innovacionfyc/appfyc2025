<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * SEM_DUPLA es un seminario con asistencia presencial y por streaming. Esta migración añade la
 * tarifa presencial sin modificar precio_seminario_virtual, que conserva sus datos y sigue
 * disponible para el código publicado anteriormente. No copia importes entre columnas.
 */
return new class extends Migration
{
    /**
     * Tabla sobre la que actúa la migración. Las pruebas la apuntan a tablas temporales.
     */
    public string $tabla = 'eventos';

    private string $columna = 'precio_seminario_presencial';

    private string $despuesDe = 'precio_seminario';

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable($this->tabla) || Schema::hasColumn($this->tabla, $this->columna)) {
            return;
        }

        Schema::table($this->tabla, function (Blueprint $table) {
            $definicion = $table->decimal($this->columna, 10, 2)->nullable()->default(0);

            if (Schema::hasColumn($this->tabla, $this->despuesDe)) {
                $definicion->after($this->despuesDe);
            }
        });
    }

    /**
     * Reverse the migrations.
     *
     * Solo elimina la columna si ningún evento (incluidos los de la papelera) tiene importe en ella.
     */
    public function down(): void
    {
        if (! Schema::hasTable($this->tabla) || ! Schema::hasColumn($this->tabla, $this->columna)) {
            return;
        }

        $conImporte = DB::table($this->tabla)->whereNotNull($this->columna)->where($this->columna, '<>', 0)->count();

        if ($conImporte > 0) {
            throw new RuntimeException(
                "No se revierte: {$conImporte} evento(s) tienen importe en {$this->columna}."
            );
        }

        Schema::table($this->tabla, function (Blueprint $table) {
            $table->dropColumn($this->columna);
        });
    }
};
