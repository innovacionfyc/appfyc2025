<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('eventos')) {
            return;
        }

        Schema::table('eventos', function (Blueprint $table) {
            if (!Schema::hasColumn('eventos', 'estilo_card')) {
                $table->string('estilo_card', 50)->nullable()->default('lista');
            }

            if (!Schema::hasColumn('eventos', 'estilo_plantilla')) {
                $table->string('estilo_plantilla', 50)->nullable()->default('lista');
            }
        });
    }

    public function down(): void
    {
        if (!Schema::hasTable('eventos')) {
            return;
        }

        Schema::table('eventos', function (Blueprint $table) {
            if (Schema::hasColumn('eventos', 'estilo_card')) {
                $table->dropColumn('estilo_card');
            }

            if (Schema::hasColumn('eventos', 'estilo_plantilla')) {
                $table->dropColumn('estilo_plantilla');
            }
        });
    }
};
