<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Una base (lote) puede pertenecer a un evento de certificación. Nullable: las bases actuales no tienen evento y no
 * cambian. `datos_comunes` sigue siendo el snapshot que manda al emitir; este vínculo no influye en ninguna emisión.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_lotes', function (Blueprint $table) {
            $table->foreignId('evento_id')->nullable()->after('plantilla_id')
                ->constrained('cf_eventos')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('cf_lotes', function (Blueprint $table) {
            $table->dropConstrainedForeignId('evento_id');
        });
    }
};
