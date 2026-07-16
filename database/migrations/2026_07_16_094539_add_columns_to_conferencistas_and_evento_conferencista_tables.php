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
        if (Schema::hasTable('perfil_conferencistas') && ! Schema::hasColumn('perfil_conferencistas', 'es_representante')) {
            Schema::table('perfil_conferencistas', function (Blueprint $table) {
                $table->boolean('es_representante')->default(false)->after('areas_encargadas');
            });
        }

        if (Schema::hasTable('evento_conferencista') && ! Schema::hasColumn('evento_conferencista', 'orden')) {
            Schema::table('evento_conferencista', function (Blueprint $table) {
                $table->integer('orden')->default(0)->after('conferencista_id');
            });
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        if (Schema::hasTable('perfil_conferencistas') && Schema::hasColumn('perfil_conferencistas', 'es_representante')) {
            Schema::table('perfil_conferencistas', function (Blueprint $table) {
                $table->dropColumn('es_representante');
            });
        }

        if (Schema::hasTable('evento_conferencista') && Schema::hasColumn('evento_conferencista', 'orden')) {
            Schema::table('evento_conferencista', function (Blueprint $table) {
                $table->dropColumn('orden');
            });
        }
    }
};
