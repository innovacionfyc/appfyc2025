<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Evento de certificación de Credential Flow (histórico o nuevo).
 *
 * NO es la tabla `eventos` (marketing: organizador, precios, slug…): aquí solo vive lo necesario para agrupar bases y
 * certificados. El id del sistema viejo NO se guarda aquí: irá en el mapa de migración.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_eventos', function (Blueprint $table) {
            $table->id();

            $table->string('nombre', 255);
            // Nombre sin mayúsculas, tildes ni espacios sobrantes: solo para buscar y detectar repetidos.
            $table->string('nombre_normalizado', 255);

            // Si solo se conoce el año se guarda el año: nunca se inventa día ni mes.
            $table->unsignedSmallInteger('anio')->nullable();
            $table->date('fecha_inicio')->nullable();
            $table->date('fecha_fin')->nullable();
            // Fecha tal como se imprime o se muestra («LOS DÍAS 17, 18 Y 19 DE SEPTIEMBRE DE 2026»).
            $table->string('fecha_texto', 120)->nullable();

            // borrador | activo | cerrado | archivado
            $table->string('estado', 12)->default('activo');
            // credential_flow | legado
            $table->string('origen', 20)->default('credential_flow');

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('nombre_normalizado', 'cf_eventos_nombre_norm_idx');
            $table->index(['anio', 'estado'], 'cf_eventos_anio_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_eventos');
    }
};
