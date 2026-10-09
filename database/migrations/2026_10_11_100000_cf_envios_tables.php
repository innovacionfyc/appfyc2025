<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Registro auditable de CORREOS enviados por Credential Flow (Fase 9). Un envío LÓGICO (`cf_envios`) puede tener varios INTENTOS
 * (`cf_envios_intentos`); cada intento conserva su resultado.
 *
 * Qué NO se guarda jamás: el código OTP, el cuerpo del correo, el documento, tokens, credenciales ni mensajes de excepción (pueden
 *
 * contener la dirección). Del destinatario solo quedan un HMAC (para correlacionar sin conocerlo) y una máscara (`a***@dominio.com`) para
 * soporte. «Aceptado por el transporte» significa que el servidor de correo aceptó el mensaje; NO que llegó a la bandeja de entrada.
 *
 * No hay histórico migrado: el sistema legado no dejó ningún registro fiable de envíos y no se inventó ninguno.
 */
return new class extends Migration
{
    public const ESTADOS = ['pendiente', 'procesando', 'aceptado_por_transporte', 'fallido_temporal', 'fallido_permanente'];

    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_envios', function (Blueprint $table) {
            $table->id();

            // otp_acceso … (catálogo en App\Support\CredentialFlow\Envios\CatalogoEnvios)
            $table->string('tipo', 30);
            // seguridad | informativo
            $table->string('categoria', 15)->default('seguridad');
            $table->string('plantilla', 60);
            $table->unsignedSmallInteger('plantilla_version')->default(1);

            // Entidad de origen y su id (OTP: cf_accesos_otp.id). Sin FK a propósito: el registro del envío debe sobrevivir a la depuración del origen.
            $table->string('origen_tipo', 30);
            $table->unsignedBigInteger('origen_id')->nullable();

            $table->char('destinatario_hash', 64);
            $table->string('destinatario_mascara', 120);

            // UNA clave por envío lógico: impide duplicados incluso con concurrencia (UNIQUE).
            $table->string('clave_idempotencia', 120)->unique();

            // pendiente | procesando | aceptado_por_transporte | fallido_temporal | fallido_permanente
            $table->string('estado', 30)->default('pendiente');
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->unsignedSmallInteger('max_intentos')->default(1);

            $table->timestamp('solicitado_at')->useCurrent();
            $table->timestamp('primer_intento_at')->nullable();
            $table->timestamp('ultimo_intento_at')->nullable();
            // Solo cuando el transporte aceptó el mensaje.
            $table->timestamp('aceptado_at')->nullable();

            // Identificador que devolvió el servidor de correo, únicamente si existe.
            $table->string('proveedor_referencia', 190)->nullable();
            // temporal | permanente y un código técnico corto (SMTP_451, CONEXION, LIMITE_GLOBAL…): nunca el mensaje de la excepción.
            $table->string('error_clase', 12)->nullable();
            $table->string('error_codigo', 40)->nullable();

            $table->unsignedBigInteger('created_by')->nullable();
            $table->timestamps();

            $table->index(['estado', 'solicitado_at'], 'cf_envios_estado_fecha_idx');
            $table->index(['tipo', 'solicitado_at'], 'cf_envios_tipo_fecha_idx');
            $table->index('destinatario_hash', 'cf_envios_destinatario_idx');
            $table->index(['origen_tipo', 'origen_id'], 'cf_envios_origen_idx');
        });

        Schema::create('cf_envios_intentos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('envio_id')->constrained('cf_envios')->restrictOnDelete();
            $table->unsignedSmallInteger('numero');
            // aceptado | fallido_temporal | fallido_permanente
            $table->string('resultado', 30);
            $table->dateTime('iniciado_at');
            $table->timestamp('finalizado_at')->nullable();
            $table->unsignedInteger('duracion_ms')->nullable();
            // Nombre del transporte configurado (smtp, array…): nunca host, usuario ni clave.
            $table->string('transporte', 30)->nullable();
            $table->string('proveedor_referencia', 190)->nullable();
            $table->string('error_clase', 12)->nullable();
            $table->string('error_codigo', 40)->nullable();
            $table->timestamps();

            $table->unique(['envio_id', 'numero'], 'cf_envios_intentos_numero_uq');
        });

        if ($mysql) {
            $estados = "'".implode("','", self::ESTADOS)."'";
            Schema::getConnection()->statement("ALTER TABLE cf_envios ADD CONSTRAINT cf_envios_estado_chk CHECK (estado IN ({$estados}))");
            Schema::getConnection()->statement("ALTER TABLE cf_envios_intentos ADD CONSTRAINT cf_envios_intentos_resultado_chk CHECK (resultado IN ('aceptado','fallido_temporal','fallido_permanente'))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_envios_intentos');
        Schema::dropIfExists('cf_envios');
    }
};
