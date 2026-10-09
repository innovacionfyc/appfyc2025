<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Corrige la semántica de `enviado_at` en los desafíos OTP (Fase 9). Hasta ahora se escribía al CREAR el desafío, es decir, antes de
 * enviar nada. Ahora:
 *   - `solicitado_at`: cuándo se creó el desafío. Es la fecha que usan los límites de la Fase 7 (60 s, 3 por hora, 8 por día, bloqueo).
 *   - `enviado_at`: SOLO cuando el transporte de correo confirma que aceptó el mensaje (NULL mientras no ocurra o si el envío falla).
 * Los desafíos existentes conservan su fecha: `solicitado_at` se rellena con el valor previo de `enviado_at`, que ya era la de creación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->timestamp('solicitado_at')->nullable()->after('expires_at');
        });
        DB::table('cf_accesos_otp')->whereNull('solicitado_at')->update(['solicitado_at' => DB::raw('enviado_at')]);

        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->index(['documento_hash', 'correo_hash', 'solicitado_at'], 'cf_otp_doc_correo_sol_idx');
            $table->index(['documento_hash', 'solicitado_at'], 'cf_otp_doc_sol_idx');
            $table->dropIndex('cf_otp_doc_correo_idx');
            $table->dropIndex('cf_otp_doc_idx');
        });
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->dateTime('enviado_at')->nullable()->change();
        });
    }

    public function down(): void
    {
        DB::table('cf_accesos_otp')->whereNull('enviado_at')->update(['enviado_at' => DB::raw('COALESCE(solicitado_at, created_at)')]);
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->dateTime('enviado_at')->nullable(false)->change();
        });
        Schema::table('cf_accesos_otp', function (Blueprint $table) {
            $table->index(['documento_hash', 'correo_hash', 'enviado_at'], 'cf_otp_doc_correo_idx');
            $table->index(['documento_hash', 'enviado_at'], 'cf_otp_doc_idx');
            $table->dropIndex('cf_otp_doc_correo_sol_idx');
            $table->dropIndex('cf_otp_doc_sol_idx');
            $table->dropColumn('solicitado_at');
        });
    }
};
