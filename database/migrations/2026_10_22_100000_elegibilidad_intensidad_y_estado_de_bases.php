<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Elegibilidad e intensidad POR PARTICIPANTE, marca de actualización del correo y estado operativo de las bases.
 *
 *  - `elegibilidad`: elegible (por defecto: todo lo existente) | pendiente (de validación) | no_elegible. Solo los elegibles entran a una emisión oficial.
 *  - `intensidad_horaria`: excepción aprobada por participante; NULL = la del evento/base. Nunca se inventan horas.
 *  - `elegibilidad_motivo`: por qué está pendiente o no es elegible (texto corto del administrador).
 *  - `correo_actualizado_at`: instante en que se corrigió el correo; una sesión del portal abierta ANTES no puede seguir viendo ese certificado.
 *  - `tipo_documento_confirmado`: true (por defecto: todo lo existente) | false = el tipo de documento (C.C., C.E., pasaporte…) aún no se verificó; esa persona se guarda
 *    pero NO entra a una emisión oficial hasta confirmarlo. La clave canónica del documento y la identidad del portal no cambian.
 *  - `cf_lotes.vista_previa_*`: la vista previa revisada y la plantilla confirmada de la base (con una huella del diseño): sin ellas no se emite oficialmente.
 *  - `cf_lotes.estado_operativo`: activa | borrador | archivada | prueba. Solo sirve para filtrar el listado; todo lo existente queda «activa» y NADA cambia solo.
 *
 * Aditiva: columnas con valor por defecto o nulas. No modifica ninguna fila, emisión, snapshot ni PDF.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->string('elegibilidad', 20)->default('elegible');
            $table->string('intensidad_horaria', 60)->nullable();
            $table->string('elegibilidad_motivo', 255)->nullable();
            $table->timestamp('correo_actualizado_at')->nullable();
            $table->boolean('tipo_documento_confirmado')->default(true);
            $table->index(['lote_id', 'elegibilidad'], 'cf_participantes_lote_eleg_idx');
        });

        Schema::table('cf_lotes', function (Blueprint $table) {
            $table->string('estado_operativo', 12)->default('activa');
            $table->timestamp('vista_previa_generada_at')->nullable();
            $table->timestamp('vista_previa_confirmada_at')->nullable();
            $table->unsignedBigInteger('vista_previa_plantilla_id')->nullable();
            $table->char('vista_previa_huella', 64)->nullable();
            $table->index('estado_operativo', 'cf_lotes_estado_operativo_idx');
        });
    }

    public function down(): void
    {
        Schema::table('cf_lotes', function (Blueprint $table) {
            $table->dropIndex('cf_lotes_estado_operativo_idx');
            $table->dropColumn(['estado_operativo', 'vista_previa_generada_at', 'vista_previa_confirmada_at', 'vista_previa_plantilla_id', 'vista_previa_huella']);
        });
        Schema::table('cf_participantes', function (Blueprint $table) {
            $table->dropIndex('cf_participantes_lote_eleg_idx');
            $table->dropColumn(['elegibilidad', 'intensidad_horaria', 'elegibilidad_motivo', 'correo_actualizado_at', 'tipo_documento_confirmado']);
        });
    }
};
