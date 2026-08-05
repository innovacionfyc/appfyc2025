<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Enum original de eventos.tipo_evento, tal como quedó ejecutado en producción.
     */
    private array $valoresViejos = [
        'SEMINARIO',
        'JORNADA',
        'MODULO',
        'CNG',
        'CURSO_INTENSIVO',
        'DIPLOMADO',
        'CI_CNG',
        'JOR_MOD',
    ];

    /**
     * Valores nuevos introducidos por la funcionalidad de precios virtuales/híbridos.
     */
    private array $valoresNuevos = [
        'MODULO_VIRTUAL',
        'CNG_VIRTUAL',
        'CURSO_INTENSIVO_VIRTUAL',
        'CURSO_INTENSIVO_HIBRIDO',
        'DIPLOMADO_VIRTUAL',
        'DIPLOMADO_HIBRIDO',
        'CUR_CNG_MOD_DUPLA',
        'CUR_DUPLA',
        'CNG_DUPLA',
        'MOD_DUPLA',
    ];

    /**
     * Run the migrations.
     */
    public function up(): void
    {
        if (! Schema::hasTable('eventos') || ! Schema::hasColumn('eventos', 'tipo_evento')) {
            return;
        }

        // Superconjunto: se conservan TODOS los valores viejos (incluidos
        // CURSO_INTENSIVO y DIPLOMADO) para no corromper eventos existentes,
        // y se agregan los valores nuevos. MODIFY COLUMN es re-ejecutable.
        $enum = $this->enumSql(array_merge($this->valoresViejos, $this->valoresNuevos));

        DB::statement("ALTER TABLE eventos MODIFY COLUMN tipo_evento ENUM({$enum}) NOT NULL DEFAULT 'JORNADA'");
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (! Schema::hasTable('eventos') || ! Schema::hasColumn('eventos', 'tipo_evento')) {
            return;
        }

        // Protección contra corrupción de datos: si ya existen eventos usando
        // valores nuevos del enum, volver al enum viejo los truncaría o haría
        // fallar el ALTER. En ese caso el rollback del enum NO se ejecuta y la
        // columna conserva el superconjunto de valores.
        $hayValoresNuevosEnUso = DB::table('eventos')
            ->whereIn('tipo_evento', $this->valoresNuevos)
            ->exists();

        if ($hayValoresNuevosEnUso) {
            return;
        }

        $enum = $this->enumSql($this->valoresViejos);

        DB::statement("ALTER TABLE eventos MODIFY COLUMN tipo_evento ENUM({$enum}) NOT NULL DEFAULT 'JORNADA'");
    }

    /**
     * Convierte la lista de valores en el literal SQL del ENUM.
     */
    private function enumSql(array $valores): string
    {
        return implode(',', array_map(fn (string $valor): string => "'{$valor}'", $valores));
    }
};
