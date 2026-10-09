<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Capa de DECISIONES DE IDENTIDAD histórica (Fase 10B-3A). Capa APARTE y aditiva: no altera ninguna tabla histórica ni el portal. Registra decisiones
 * humanas sobre casos `identidad_ambigua`; en esta fase NINGUNA cambia la autorización (lo hará 10B-3B).
 *
 *   cf_decisiones_identidad          la decisión (caso, documento por HMAC, tipo, estado vigente|revocada, actor, motivo, evidencia, revocación).
 *   cf_decisiones_identidad_grupos   los `grupo_hash` técnicos que abarca (MISMA_PERSONA / PERSONAS_DISTINTAS). Hijo relacional: permite UNIQUE reales
 *                                    («un grupo en un solo alcance vigente») que un JSON no permite.
 *   cf_decisiones_identidad_correos  correo → grupo (CORREO_AUTORIZADO): solo HMAC y máscara; UNIQUE «un correo, un grupo» mientras esté vigente.
 *
 * Integridad que NO depende de la interfaz: las UNIQUE usan columnas de VIGENCIA anulables (`vigente_*`): valen mientras la decisión está vigente y pasan
 * a NULL al revocarla (MySQL/SQLite permiten varios NULL), de modo que se puede volver a decidir sin borrar nada. Nunca se elimina una decisión (FK RESTRICT
 * y modelo sin borrado). Sin datos personales: documento y correo van como HMAC, el correo además enmascarado.
 */
return new class extends Migration
{
    public const TIPOS = ['misma_persona', 'personas_distintas', 'correo_autorizado', 'requiere_soporte', 'no_resoluble'];

    public const ESTADOS = ['vigente', 'revocada'];

    public function up(): void
    {
        Schema::create('cf_decisiones_identidad', function (Blueprint $table) {
            $table->id();

            $table->foreignId('conciliacion_id')->constrained('cf_conciliaciones')->restrictOnDelete();
            // HMAC del documento lógico (el mismo que `referencia_clave` del caso). Nunca el documento.
            $table->char('documento_hash', 64);
            $table->string('tipo', 24);
            $table->string('estado', 10)->default('vigente');

            $table->text('motivo');
            // Resumen de evidencia escrito por el administrador (10–1000 caracteres) y su SHA-256: la bitácora guarda solo el hash.
            $table->text('evidencia')->nullable();
            $table->char('evidencia_sha256', 64)->nullable();
            $table->boolean('declaro_evidencia_externa')->default(false);
            $table->boolean('confirmo_alcance_masivo')->default(false);
            $table->boolean('confirmacion_reforzada')->default(false);
            $table->unsignedInteger('certificados_afectados')->default(0);
            // Estado al que esta decisión llevó el CASO (p. ej. `requiere_soporte`); se deshace al revocar. NULL = no cambió el caso.
            $table->string('efecto_caso', 20)->nullable();

            // Sin FK a usuarios a propósito: la decisión debe sobrevivir a un usuario eliminado.
            $table->unsignedBigInteger('creada_por');
            $table->unsignedBigInteger('revocada_por')->nullable();
            $table->timestamp('revocada_at')->nullable();
            $table->text('motivo_revocacion')->nullable();

            // VIGENCIA. `vigente_clave`: SHA-256 del contenido canónico (caso + tipo + grupos/correo); UNIQUE ⇒ idempotencia y cero duplicados vigentes.
            // `vigente_caso_clave`: id del caso, solo en las decisiones terminales (soporte / no resoluble) ⇒ a lo sumo una vigente por caso.
            $table->char('vigente_clave', 64)->nullable()->unique('cf_decis_ident_clave_uq');
            $table->unsignedBigInteger('vigente_caso_clave')->nullable()->unique('cf_decis_ident_terminal_uq');

            $table->timestamps();

            $table->index(['documento_hash', 'estado'], 'cf_decis_ident_doc_estado_idx');
            $table->index(['conciliacion_id', 'estado'], 'cf_decis_ident_caso_estado_idx');
        });

        Schema::create('cf_decisiones_identidad_grupos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('decision_id')->constrained('cf_decisiones_identidad')->restrictOnDelete();
            $table->char('documento_hash', 64);
            // `grupo_hash` técnico existente (HMAC del nombre conservador). Nunca el nombre.
            $table->char('grupo_hash', 64);
            // = tipo de la decisión mientras está vigente; NULL al revocarla.
            $table->string('vigente_tipo', 24)->nullable();

            $table->timestamps();

            $table->unique(['decision_id', 'grupo_hash'], 'cf_decis_grupo_uq');
            // Un grupo pertenece a UN solo alcance vigente de cada clase (misma persona / personas distintas) dentro de su documento.
            $table->unique(['documento_hash', 'grupo_hash', 'vigente_tipo'], 'cf_decis_grupo_vigente_uq');
            $table->index('grupo_hash', 'cf_decis_grupo_hash_idx');
        });

        Schema::create('cf_decisiones_identidad_correos', function (Blueprint $table) {
            $table->id();

            $table->foreignId('decision_id')->constrained('cf_decisiones_identidad')->restrictOnDelete();
            $table->char('documento_hash', 64);
            // HMAC del correo normalizado (jamás el correo) y una máscara para mostrarlo.
            $table->char('correo_hmac', 64);
            $table->string('correo_mascara', 120);
            // Grupo al que se autoriza este correo.
            $table->char('grupo_hash', 64);
            // 1 mientras la decisión está vigente; NULL al revocarla.
            $table->unsignedTinyInteger('vigente')->nullable();

            $table->timestamps();

            $table->unique('decision_id', 'cf_decis_correo_decision_uq');
            // Un correo autoriza UN solo grupo del documento mientras esté vigente.
            $table->unique(['documento_hash', 'correo_hmac', 'vigente'], 'cf_decis_correo_vigente_uq');
            $table->index('correo_hmac', 'cf_decis_correo_hmac_idx');
            $table->index(['documento_hash', 'grupo_hash'], 'cf_decis_correo_grupo_idx');
        });

        if (Schema::getConnection() instanceof MySqlConnection) {
            $c = Schema::getConnection();
            $tipos = "'".implode("','", self::TIPOS)."'";
            $estados = "'".implode("','", self::ESTADOS)."'";
            $c->statement("ALTER TABLE cf_decisiones_identidad ADD CONSTRAINT cf_decis_ident_tipo_chk CHECK (tipo IN ({$tipos}))");
            $c->statement("ALTER TABLE cf_decisiones_identidad ADD CONSTRAINT cf_decis_ident_estado_chk CHECK (estado IN ({$estados}))");
            // Coherencia estado ↔ vigencia ↔ revocación: una decisión vigente tiene clave y no revocación; una revocada, revocación completa y sin claves.
            $c->statement('ALTER TABLE cf_decisiones_identidad ADD CONSTRAINT cf_decis_ident_vigencia_chk CHECK ('
                ."(estado = 'vigente' AND revocada_at IS NULL AND revocada_por IS NULL AND vigente_clave IS NOT NULL)"
                ." OR (estado = 'revocada' AND revocada_at IS NOT NULL AND revocada_por IS NOT NULL AND vigente_clave IS NULL AND vigente_caso_clave IS NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_decisiones_identidad_correos');
        Schema::dropIfExists('cf_decisiones_identidad_grupos');
        Schema::dropIfExists('cf_decisiones_identidad');
    }
};
