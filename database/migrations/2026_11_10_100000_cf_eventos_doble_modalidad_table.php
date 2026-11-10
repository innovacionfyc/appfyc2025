<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de autorizaciones de la doble certificación por modalidad por evento (Participantes\DobleModalidad). Cada fila es UNA autorización: se crea
 * vigente y, si se revoca, se marca revocada con autor, fecha y motivo. Nunca se borra ni se reescribe una autorización: una nueva autorización del mismo
 * evento es otra fila.
 *
 *  - `vigente_evento_id` = id del evento mientras la autorización está vigente, NULL cuando se revoca. Su índice ÚNICO garantiza en la propia base de datos que
 *    un evento tenga como máximo UNA autorización vigente (los NULL no chocan entre sí, ni en MySQL/MariaDB ni en SQLite).
 *  - `origen`: «panel» (un super-admin desde la ficha del evento) o «configuracion_heredada» (registro auditado de un evento que ya estaba autorizado por la
 *    variable CREDENTIAL_FLOW_DOBLE_MODALIDAD_EVENTOS, como el #637).
 *  - Autores como en el resto del módulo (emitido_por, archivada_por…): id del usuario, sin clave foránea. Sin clave foránea al evento: los eventos se
 *    eliminan de forma lógica y esta tabla es un registro de auditoría.
 *
 * Aditiva e idempotente: crea una tabla nueva y no toca ninguna existente (eventos, bases, participantes, emisiones). Mientras la tabla esté vacía no cambia
 * nada: la autorización sigue saliendo solo de la variable heredada.
 * Reversión: `down()` elimina la tabla. Solo debe revertirse si está vacía o si se acepta perder ese historial (la auditoría queda también en movimientos).
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('cf_eventos_doble_modalidad')) {
            return;
        }

        Schema::create('cf_eventos_doble_modalidad', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('evento_id');
            $table->string('estado', 12);                       // vigente | revocada
            $table->string('origen', 30);                       // panel | configuracion_heredada
            $table->string('motivo', 500);
            $table->unsignedBigInteger('autorizada_por');
            $table->timestamp('autorizada_at')->nullable();     // TIMESTAMP NULL: sin valor por defecto implícito en MariaDB; el servicio siempre lo llena
            $table->unsignedBigInteger('revocada_por')->nullable();
            $table->timestamp('revocada_at')->nullable();
            $table->string('motivo_revocacion', 500)->nullable();
            $table->unsignedBigInteger('vigente_evento_id')->nullable()->unique('cf_ev_doble_mod_vigente_uq');
            $table->timestamps();

            $table->index(['evento_id', 'estado'], 'cf_ev_doble_mod_evento_estado_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_eventos_doble_modalidad');
    }
};
