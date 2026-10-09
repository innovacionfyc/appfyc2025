<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Casos de CONCILIACIÓN HISTÓRICA (Fase 10A). Son una capa APARTE y aditiva: no alteran ninguna tabla histórica. «El histórico no se
 * corrige sobrescribiendo el pasado»: un caso solo describe algo que requiere una decisión humana y, más adelante (10B), registrará esa
 * decisión; el dato original (snapshot, correos, descargas, plantillas) queda intacto.
 *
 *   cf_conciliaciones               el CASO (tipo, estado, referencia de origen y clave de idempotencia determinista, UNIQUE).
 *   cf_conciliaciones_certificados  certificados afectados (un caso puede afectar a muchos). Única por (caso, certificado).
 *   cf_conciliaciones_eventos       bitácora APPEND-ONLY del caso (detectado, y en 10B las decisiones). Sin UPDATE ni DELETE desde la aplicación.
 *
 * Las FK son RESTRICT: nada se borra en cascada. `referencia_clave` nunca guarda datos personales (documentos van como HMAC).
 */
return new class extends Migration
{
    public const TIPOS = ['conflicto_variantes', 'revision_documento', 'identidad_ambigua', 'plantilla_candidata', 'plantilla_faltante', 'plantilla_tipo_invalido'];

    public const ESTADOS = ['abierto', 'resuelto', 'descartado', 'requiere_soporte'];

    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_conciliaciones', function (Blueprint $table) {
            $table->id();

            $table->string('tipo', 30);
            $table->string('estado', 20)->default('abierto');
            // Evento histórico al que pertenece el caso, si es uno solo (identidad ambigua puede abarcar varios: queda NULL).
            $table->foreignId('evento_id')->nullable()->constrained('cf_eventos')->restrictOnDelete();

            // Qué se revisa: grupo_duplicado | certificado | documento (HMAC, nunca el documento) | plantilla_legado | evento.
            $table->string('referencia_tipo', 30);
            $table->string('referencia_clave', 80);
            // Código técnico que dejó la migración (DIF_VERIF, DOC_LETRAS, REFERENCIA_FALTANTE…). Sin datos personales.
            $table->string('motivo_origen', 120)->nullable();

            // Se completan solo al resolver (10B).
            $table->string('resolucion', 40)->nullable();
            $table->unsignedBigInteger('resuelto_por')->nullable();
            $table->timestamp('resuelto_at')->nullable();

            // Determinista: tipo + referencia. Impide casos duplicados aunque el detector corra dos veces o en paralelo.
            $table->string('clave_idempotencia', 120)->unique();

            $table->timestamps();

            $table->index(['tipo', 'estado'], 'cf_concil_tipo_estado_idx');
            $table->index('evento_id', 'cf_concil_evento_idx');
        });

        Schema::create('cf_conciliaciones_certificados', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conciliacion_id')->constrained('cf_conciliaciones')->restrictOnDelete();
            $table->foreignId('certificado_legado_id')->constrained('cf_certificados_legado')->restrictOnDelete();
            // variante | afectado | documento… (opcional: ayuda a leer el caso).
            $table->string('rol', 20)->nullable();

            $table->timestamps();

            $table->unique(['conciliacion_id', 'certificado_legado_id'], 'cf_concil_cert_uq');
            $table->index('certificado_legado_id', 'cf_concil_cert_certificado_idx');
        });

        Schema::create('cf_conciliaciones_eventos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conciliacion_id')->constrained('cf_conciliaciones')->restrictOnDelete();
            $table->string('accion', 30);
            $table->string('estado_anterior', 20)->nullable();
            $table->string('estado_nuevo', 20)->nullable();
            $table->text('motivo')->nullable();
            $table->json('evidencia')->nullable();
            // Sin FK a propósito: la bitácora debe sobrevivir a un usuario eliminado.
            $table->unsignedBigInteger('actor_id')->nullable();
            // Solo created_at: un evento nunca se modifica.
            $table->timestamp('created_at')->useCurrent();

            $table->index(['conciliacion_id', 'created_at'], 'cf_concil_eventos_caso_idx');
        });

        if ($mysql) {
            $tipos = "'".implode("','", self::TIPOS)."'";
            $estados = "'".implode("','", self::ESTADOS)."'";
            $c = Schema::getConnection();
            $c->statement("ALTER TABLE cf_conciliaciones ADD CONSTRAINT cf_concil_tipo_chk CHECK (tipo IN ({$tipos}))");
            $c->statement("ALTER TABLE cf_conciliaciones ADD CONSTRAINT cf_concil_estado_chk CHECK (estado IN ({$estados}))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_conciliaciones_eventos');
        Schema::dropIfExists('cf_conciliaciones_certificados');
        Schema::dropIfExists('cf_conciliaciones');
    }
};
