<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Motor configurable de encuestas (Fase 8). Separa DEFINICIÓN (cf_encuestas), VERSIÓN/snapshot (cf_encuestas_versiones), PREGUNTAS,
 * OPCIONES, RESPUESTAS y DETALLE. No depende de columnas fijas pregunta1…pregunta9: las respuestas históricas del sistema viejo se
 * copian a este modelo SIN tocar el staging (que conserva pregunta1…9 tal cual) y quedan ligadas a una corrida de migración.
 *
 *  - Una respuesta queda ligada a la VERSIÓN exacta vigente al contestar; el detalle guarda además el snapshot de la pregunta y de la
 *    opción que vio la persona, así que cambiar la encuesta después NO altera lo ya respondido.
 *  - Una respuesta pertenece a lo sumo a UN origen de certificado (legado o moderno); puede ser huérfana (histórica sin certificado).
 *  - Todas las FK son RESTRICT: nada desaparece en cascada; el rollback técnico de una corrida borra explícitamente y en orden.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_encuestas', function (Blueprint $table) {
            $table->id();
            $table->string('nombre', 160);
            // borrador | publicada | archivada
            $table->string('estado', 12)->default('borrador');
            // Solo una encuesta publicada y activa responde a «obtener encuesta activa».
            $table->boolean('activa')->default(false);
            // legado | credential_flow
            $table->string('origen', 20)->default('credential_flow');
            $table->foreignId('corrida_id')->nullable()->constrained('cf_migraciones_corridas')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();
            $table->timestamps();

            $table->index(['estado', 'activa'], 'cf_encuestas_estado_idx');
        });

        Schema::create('cf_encuestas_versiones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('encuesta_id')->constrained('cf_encuestas')->restrictOnDelete();
            $table->unsignedInteger('numero');
            $table->string('titulo', 250);
            $table->text('introduccion')->nullable();
            // Por defecto OPCIONAL: no bloquea la descarga del certificado salvo configuración explícita futura.
            $table->boolean('obligatoria')->default(false);
            // NULL = borrador (aún no se puede contestar). Una versión publicada es inmutable en estructura.
            $table->timestamp('publicada_at')->nullable();
            $table->dateTime('activa_desde');
            $table->dateTime('activa_hasta')->nullable();
            // Estructura completa + evidencia (sin respuestas ni datos personales).
            $table->json('snapshot')->nullable();
            $table->foreignId('corrida_id')->nullable()->constrained('cf_migraciones_corridas')->restrictOnDelete();
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();
            $table->timestamps();

            $table->unique(['encuesta_id', 'numero'], 'cf_enc_ver_numero_uq');
        });

        Schema::create('cf_encuestas_preguntas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('cf_encuestas_versiones')->restrictOnDelete();
            $table->unsignedSmallInteger('orden');
            // Clave estable DENTRO de la versión (p. ej. pregunta1 en las históricas).
            $table->string('clave', 40);
            $table->text('texto');
            // opcion_unica | opcion_multiple | texto | escala
            $table->string('tipo', 20);
            $table->boolean('obligatoria')->default(false);
            $table->boolean('activa')->default(true);
            $table->text('ayuda')->nullable();
            // escala: {min,max,etiqueta_min,etiqueta_max}; texto: {max_caracteres}; históricas: trazabilidad de la redacción.
            $table->json('configuracion')->nullable();
            $table->timestamps();

            $table->unique(['version_id', 'clave'], 'cf_enc_preg_clave_uq');
            $table->unique(['version_id', 'orden'], 'cf_enc_preg_orden_uq');
        });

        Schema::create('cf_encuestas_opciones', function (Blueprint $table) use ($mysql) {
            $table->id();
            $table->foreignId('pregunta_id')->constrained('cf_encuestas_preguntas')->restrictOnDelete();
            $table->unsignedSmallInteger('orden');
            // Valor EXACTO (en las históricas, el texto que se contestó). Binario: «Si» y «SI» son valores distintos.
            $valor = $table->string('valor', 190);
            if ($mysql) {
                $valor->collation('utf8mb4_bin');
            }
            $table->string('etiqueta', 250);
            $table->boolean('activa')->default(true);
            $table->timestamps();

            $table->unique(['pregunta_id', 'valor'], 'cf_enc_op_valor_uq');
        });

        Schema::create('cf_encuestas_respuestas', function (Blueprint $table) {
            $table->id();
            $table->foreignId('version_id')->constrained('cf_encuestas_versiones')->restrictOnDelete();
            $table->foreignId('evento_id')->nullable()->constrained('cf_eventos')->restrictOnDelete();
            $table->foreignId('certificado_legado_id')->nullable()->constrained('cf_certificados_legado')->restrictOnDelete();
            $table->foreignId('emision_id')->nullable()->constrained('cf_emisiones')->restrictOnDelete();
            // HMAC del documento (nunca el documento): contexto seguro cuando no hay certificado al que ligarla.
            $table->char('documento_hash', 64)->nullable();
            // Id del evento en el sistema viejo cuando ya no existe (evento_id NULL): trazabilidad sin inventar nada.
            $table->unsignedInteger('old_evento_id')->nullable();
            // vinculada | evento_historico_eliminado | participante_no_encontrado | participante_ambiguo (históricas); NULL en las nuevas.
            $table->string('clasificacion', 40)->nullable();
            $table->dateTime('completada_at');
            // legado | credential_flow
            $table->string('origen', 20)->default('credential_flow');
            $table->foreignId('corrida_id')->nullable()->constrained('cf_migraciones_corridas')->restrictOnDelete();
            $table->timestamps();

            $table->index('version_id', 'cf_enc_resp_version_idx');
            $table->index('evento_id', 'cf_enc_resp_evento_idx');
            $table->index('certificado_legado_id', 'cf_enc_resp_cert_idx');
            $table->index('corrida_id', 'cf_enc_resp_corrida_idx');
        });

        Schema::create('cf_encuestas_respuestas_detalle', function (Blueprint $table) {
            $table->id();
            $table->foreignId('respuesta_id')->constrained('cf_encuestas_respuestas')->restrictOnDelete();
            // NULL cuando una respuesta histórica no se puede mapear a una pregunta configurable (se conserva por clave_historica).
            $table->foreignId('pregunta_id')->nullable()->constrained('cf_encuestas_preguntas')->restrictOnDelete();
            $table->string('clave_historica', 40)->nullable();
            $table->text('valor_texto')->nullable();
            $table->decimal('valor_numero', 10, 2)->nullable();
            $table->foreignId('opcion_id')->nullable()->constrained('cf_encuestas_opciones')->restrictOnDelete();
            // Lo que la persona VIO: la pregunta (clave, tipo, texto) y la opción elegida, congeladas al responder.
            $table->json('snapshot_pregunta');
            $table->string('snapshot_opcion', 250)->nullable();
            $table->timestamps();

            $table->index('respuesta_id', 'cf_enc_det_resp_idx');
            $table->index('pregunta_id', 'cf_enc_det_preg_idx');
            $table->index('opcion_id', 'cf_enc_det_op_idx');
        });

        if ($mysql) {
            // Una respuesta pertenece como máximo a UN origen de certificado (puede no pertenecer a ninguno: respuesta huérfana).
            Schema::getConnection()->statement('ALTER TABLE cf_encuestas_respuestas ADD CONSTRAINT cf_enc_resp_un_origen_chk CHECK (certificado_legado_id IS NULL OR emision_id IS NULL)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_encuestas_respuestas_detalle');
        Schema::dropIfExists('cf_encuestas_respuestas');
        Schema::dropIfExists('cf_encuestas_opciones');
        Schema::dropIfExists('cf_encuestas_preguntas');
        Schema::dropIfExists('cf_encuestas_versiones');
        Schema::dropIfExists('cf_encuestas');
    }
};
