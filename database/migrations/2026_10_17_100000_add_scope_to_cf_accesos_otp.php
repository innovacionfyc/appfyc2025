<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Scope APROBADO congelado en el desafío OTP (Fase 10B-3B-1). Migración ADITIVA: tres columnas nulas, sin FK, sin índice y sin tocar filas existentes.
 *
 *   scope_hash        HMAC versionado (v1) de documento + correo + grupos ordenados + decisiones ordenadas. NULL = desafío histórico normal: comportamiento
 *                     exactamente actual (`grupo_hash` sigue siendo la fuente).
 *   scope_decisiones  ids de las decisiones usadas, ordenados, como arreglo JSON de enteros. Es solo un snapshot AUDITABLE (nunca se consulta de forma
 *                     relacional): el JSON no tiene límite frágil de longitud ni parseo ambiguo, y el resolver nunca produce más de 2 ids (una autorización de
 *                     correo y la misma persona que la contiene).
 *   scope_grupos      cantidad de grupos del scope (auditoría; sin nombres).
 *
 * El correo NO se guarda en claro: al validar, el correo normalizado viene de la sesión pendiente (el usuario lo digitó) y el resolver recalcula los HMAC;
 * el desafío solo conserva `correo_hash` y `scope_hash`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->char('scope_hash', 64)->nullable()->after('grupo_hash');
            $table->json('scope_decisiones')->nullable()->after('scope_hash');
            $table->unsignedSmallInteger('scope_grupos')->nullable()->after('scope_decisiones');
        });
    }

    public function down(): void
    {
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->dropColumn(['scope_hash', 'scope_decisiones', 'scope_grupos']);
        });
    }
};
