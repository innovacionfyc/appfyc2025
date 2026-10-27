<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Emisiones programadas de una base. Migración ADITIVA: solo crea una tabla nueva; no toca ninguna existente (cf_emisiones incluida).
 *
 *  - Estados: pendiente → ejecutando → completada | fallida; requiere_confirmacion (algo cambió o el permiso ya no vale: no emite sola); cancelada.
 *  - `lote_activa` vale el `lote_id` mientras la programación está pendiente, ejecutándose o esperando confirmación, y NULL en cualquier estado final: su índice ÚNICO garantiza en MySQL
 *    una sola programación activa por base, también ante dos administradores programando a la vez (mismo patrón que `participante_vigente`).
 *  - `programada_para` es la hora de Colombia (DATETIME, sin conversión de zona) y `zona_horaria` deja constancia de cómo se interpretó.
 *  - `huella` y `huella_detalle` son SOLO hashes de lo que se aprobó (base, participantes, plantilla, variante QA y vista previa): ningún dato personal.
 *  - Sin contraseñas, tokens ni sesiones: el ejecutor automático solo conoce el id de quien programó.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cf_emisiones_programadas')) {
            return;
        }

        Schema::create('cf_emisiones_programadas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('lote_id')->constrained('cf_lotes');
            $table->unsignedBigInteger('lote_activa')->nullable()->unique();
            $table->string('estado', 30)->default('pendiente');
            $table->unsignedInteger('version')->default(1);

            $table->dateTime('programada_para');
            $table->string('zona_horaria', 40)->default('America/Bogota');

            $table->char('huella', 64);
            $table->json('huella_detalle');
            $table->unsignedInteger('oficiales_esperados')->default(0);
            $table->unsignedInteger('qa_esperados')->default(0);
            $table->unsignedBigInteger('plantilla_id')->nullable();
            $table->unsignedBigInteger('variante_qa_id')->nullable();

            $table->unsignedBigInteger('programada_por');
            $table->timestamp('confirmada_at')->nullable();
            $table->unsignedBigInteger('confirmada_por')->nullable();
            $table->unsignedSmallInteger('reprogramaciones')->default(0);

            $table->timestamp('cancelada_at')->nullable();
            $table->unsignedBigInteger('cancelada_por')->nullable();
            $table->string('motivo_cancelacion', 255)->nullable();

            // Ejecutor (P2): `reclamo_hash` es el SHA-256 del boleto privado del proceso que reservó la programación (el boleto solo existe en la memoria de ese
            // proceso: ninguna petición web lo tiene). `proximo_intento_at` espacia los reintentos de errores transitorios. `con_avisos`: oficiales emitidos y
            // pruebas QA pendientes.
            $table->unsignedSmallInteger('intentos')->default(0);
            $table->char('reclamo_hash', 64)->nullable();
            $table->dateTime('proximo_intento_at')->nullable();
            $table->boolean('con_avisos')->default(false);
            $table->timestamp('iniciada_at')->nullable();
            $table->timestamp('latido_at')->nullable();
            $table->timestamp('terminada_at')->nullable();
            $table->json('resultado')->nullable();
            $table->string('error_codigo', 60)->nullable();
            $table->string('error_mensaje', 500)->nullable();

            $table->timestamps();

            $table->index(['estado', 'programada_para']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_emisiones_programadas');
    }
};
