<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Fase 10B-1: `accion` (30) era corta para «plantilla_renderizable_confirmada» (33). SQLite no valida el largo, así que solo MySQL lo
 * detectó (QA real). Se amplía a 60 sin tocar datos. Es una ampliación: no pierde información.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_conciliaciones_eventos', function (Blueprint $table) {
            $table->string('accion', 60)->change();
        });
    }

    public function down(): void
    {
        Schema::table('cf_conciliaciones_eventos', function (Blueprint $table) {
            $table->string('accion', 30)->change();
        });
    }
};
