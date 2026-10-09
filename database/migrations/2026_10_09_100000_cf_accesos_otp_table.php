<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Desafíos OTP del portal público de certificados históricos. NO guarda el documento, el correo, la IP ni el código en claro:
 * solo HMAC (con el secreto de la aplicación) de documento, correo, IP y user-agent, y el hash fuerte (bcrypt) del OTP. Sirve para
 * validar el código, aplicar vigencia/intentos/reenvíos y bloquear temporalmente el abuso, sin tocar las tablas históricas.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_accesos_otp', function (Blueprint $table) {
            $table->id();

            $table->char('documento_hash', 64);
            $table->char('correo_hash', 64);
            $table->string('otp_hash', 255);

            $table->dateTime('expires_at');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->dateTime('enviado_at');
            $table->timestamp('usado_at')->nullable();
            // Bloqueado por demasiados intentos fallidos.
            $table->timestamp('bloqueado_at')->nullable();
            // Reemplazado por un OTP más reciente.
            $table->timestamp('invalidado_at')->nullable();

            $table->char('ip_hash', 64)->nullable();
            $table->char('user_agent_hash', 64)->nullable();

            $table->timestamps();

            $table->index(['documento_hash', 'correo_hash', 'enviado_at'], 'cf_otp_doc_correo_idx');
            $table->index(['documento_hash', 'enviado_at'], 'cf_otp_doc_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_accesos_otp');
    }
};
