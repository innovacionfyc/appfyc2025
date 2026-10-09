<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Trazabilidad de la migración histórica (Fase 4).
 *
 *   cf_migraciones_corridas  una fila por intento de migración: qué snapshot (SHA del dump + huellas global y derivada del
 *                            staging), en qué estado terminó y qué creó (totales, sin datos personales). Una misma pareja
 *                            de huellas no se migra dos veces mientras haya una corrida COMPLETADA; si se revierte
 *                            (rollback técnico) puede ejecutarse una nueva corrida (otra fila).
 *   cf_migraciones_map       mapa old_id → id nuevo: relaciona cada fila del sistema viejo con lo que se creó, y deja constancia
 *                            de duplicados idénticos (todos los old_id se conservan) y variantes conflictivas.
 *
 * Los ids del sistema viejo viven SOLO aquí (nunca en las tablas finales). `origen_id` es texto para admitir ids numéricos y
 * claves como la ruta de una imagen.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_migraciones_corridas', function (Blueprint $table) {
            $table->id();

            // p. ej. legado_evaluaciones
            $table->string('tipo', 40);
            // Identidad del snapshot migrado: SHA-256 del dump y huellas del staging (datos originales / reglas derivadas).
            $table->char('snapshot_sha256', 64);
            $table->char('huella_global', 64);
            $table->char('huella_derivada', 64);

            // pendiente | ejecutando | completada | fallida | revertida
            $table->string('estado', 12)->default('pendiente');
            $table->timestamp('iniciado_at')->nullable();
            $table->timestamp('finalizado_at')->nullable();
            $table->timestamp('rollback_at')->nullable();

            // Conteos creados, validaciones, advertencias, pendientes y tiempos. SIN datos personales.
            $table->json('totales')->nullable();
            // Código técnico del fallo (clase de la excepción); nunca el mensaje ni datos.
            $table->string('error_codigo', 120)->nullable();

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();

            $table->index(['tipo', 'huella_global', 'huella_derivada', 'estado'], 'cf_migr_corridas_huellas_idx');
        });

        Schema::create('cf_migraciones_map', function (Blueprint $table) use ($mysql) {
            $table->id();

            $table->foreignId('corrida_id')->constrained('cf_migraciones_corridas')->restrictOnDelete();

            // Tabla y clave de ORIGEN (staging): evento, participante, descarga, imagen, evento_imagen_faltante.
            $table->string('origen_tabla', 40);
            $origen = $table->string('origen_id', 300);
            if ($mysql) {
                // Exacto: dos rutas de imagen que solo difieren en mayúsculas o tildes son claves distintas.
                $origen->collation('utf8mb4_bin');
            }

            // Tabla y id de DESTINO (tablas finales cf_*).
            $table->string('destino_tabla', 40);
            $table->unsignedBigInteger('destino_id');

            // principal | canonico | duplicado_identico | variante_conflictiva
            $table->string('relacion', 24)->default('principal');
            // Datos mínimos para entender la relación (p. ej. el old_id canónico de un duplicado). Sin datos personales.
            $table->json('detalle')->nullable();

            $table->timestamps();

            $table->unique(['corrida_id', 'origen_tabla', 'origen_id', 'destino_tabla'], 'cf_migr_map_origen_uq');
            $table->index(['destino_tabla', 'destino_id'], 'cf_migr_map_destino_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_migraciones_map');
        Schema::dropIfExists('cf_migraciones_corridas');
    }
};
