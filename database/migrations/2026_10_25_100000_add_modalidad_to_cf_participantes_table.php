<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Modalidad POR PARTICIPANTE (`cf_participantes.modalidad`), igual que la intensidad individual: NULL = la de la base (`cf_lotes.datos_comunes.modalidad`).
 * Permite que un solo Excel traiga personas presenciales y virtuales en UNA base. Se imprime literal; nunca se inventa.
 *
 * Aditiva: una columna nula. No modifica ninguna fila, emisión, snapshot ni PDF (las bases existentes siguen usando la modalidad general).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasColumn('cf_participantes', 'modalidad')) {
            return;
        }

        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->string('modalidad', 120)->nullable()->after('intensidad_horaria');
        });
    }

    public function down(): void
    {
        if (! Schema::hasColumn('cf_participantes', 'modalidad')) {
            return;
        }

        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->dropColumn('modalidad');
        });
    }
};
