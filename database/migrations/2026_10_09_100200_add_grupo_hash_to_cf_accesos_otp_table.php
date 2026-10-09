<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * El desafío OTP queda ligado al ALCANCE determinado al solicitarlo: `grupo_hash` es el HMAC del grupo de nombre conservador (NULL =
 * documento completo). Así la validación crea la sesión con ESE mismo alcance, sin recalcularlo (evita un TOCTOU lógico). Solo se
 * guarda el HMAC, nunca el nombre. Aditiva y reversible; no toca las tablas históricas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->char('grupo_hash', 64)->nullable()->after('correo_hash');
        });
    }

    public function down(): void
    {
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->dropColumn('grupo_hash');
        });
    }
};
