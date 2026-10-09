<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Doble control de la AUTORIZACIÓN MASIVA de identidad (Fase 10B-3C-3). Tabla aditiva y relacional: una fila por decisión `correo_autorizado` cuyo grupo
 * tiene >= 100 certificados. La decisión nace `vigente` pero NO es aplicable hasta que un SEGUNDO administrador, distinto del solicitante, la aprueba.
 *
 * Por qué una tabla y no JSON ni columnas en la decisión: necesitamos constraints reales —UNIQUE(decision_id) (una sola aprobación válida por decisión,
 * incluso con dos aprobadores concurrentes) y CHECK(aprobada_por <> solicitada_por) (un aprobador distinto aunque el servicio fallara)—. No toca ninguna
 * tabla histórica ni `cf_decisiones_identidad`. Nada se borra (FK RESTRICT; la aprobación se revoca hacia adelante). Sin datos personales: solo ids,
 * conteos y la fuente de la evidencia (el texto de la evidencia vive, como hasta ahora, en la decisión). Reversible: `down()` elimina solo esta tabla.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_decisiones_identidad_aprobaciones', function (Blueprint $table) {
            $table->id();
            $table->foreignId('decision_id')->constrained('cf_decisiones_identidad')->restrictOnDelete();
            $table->char('documento_hash', 64);
            $table->string('estado', 12)->default('pendiente');
            // Fuente declarada de la evidencia externa (propiedad_buzon | certificacion_organizador | registro_validado | otra).
            $table->string('fuente_evidencia', 30);
            // Blast radius CONGELADO al solicitar (conteos): el aprobador revisa lo mismo que se solicitó y se revalida al aprobar.
            $table->unsignedInteger('certificados');
            $table->unsignedInteger('logicos');
            $table->unsignedInteger('eventos');
            $table->unsignedInteger('descargables');
            $table->unsignedInteger('fuera_de_alcance');
            $table->unsignedBigInteger('solicitada_por');
            $table->timestamp('solicitada_at')->nullable();
            $table->unsignedBigInteger('aprobada_por')->nullable();
            $table->timestamp('aprobada_at')->nullable();
            $table->text('motivo_aprobacion')->nullable();
            $table->unsignedBigInteger('revocada_por')->nullable();
            $table->timestamp('revocada_at')->nullable();
            $table->text('motivo_revocacion')->nullable();
            $table->timestamps();

            // UNA sola aprobación por decisión: con B y C aprobando a la vez, el UNIQUE + el lock del caso dejan una efectiva.
            $table->unique('decision_id', 'cf_decis_aprob_decision_uq');
            $table->index(['documento_hash', 'estado'], 'cf_decis_aprob_doc_estado_idx');
        });

        // CHECK reales (MySQL 8 / MariaDB 10.2+). SQLite (pruebas) no admite ADD CONSTRAINT: ahí lo exige el servicio y el modelo.
        if (DB::connection() instanceof MySqlConnection) {
            DB::statement('ALTER TABLE cf_decisiones_identidad_aprobaciones ADD CONSTRAINT cf_decis_aprob_distinto_ck CHECK (aprobada_por IS NULL OR aprobada_por <> solicitada_por)');
            DB::statement("ALTER TABLE cf_decisiones_identidad_aprobaciones ADD CONSTRAINT cf_decis_aprob_estado_ck CHECK (estado IN ('pendiente','aprobada','revocada'))");
            DB::statement("ALTER TABLE cf_decisiones_identidad_aprobaciones ADD CONSTRAINT cf_decis_aprob_pendiente_ck CHECK (estado <> 'pendiente' OR aprobada_por IS NULL)");
            DB::statement("ALTER TABLE cf_decisiones_identidad_aprobaciones ADD CONSTRAINT cf_decis_aprob_aprobada_ck CHECK (estado <> 'aprobada' OR (aprobada_por IS NOT NULL AND aprobada_at IS NOT NULL))");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_decisiones_identidad_aprobaciones');
    }
};
