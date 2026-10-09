<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marca de origen de la migración histórica: `corrida_id` en cada tabla que la migración puebla. Solo se rellena en filas
 * creadas por una corrida; las filas modernas (y todo lo creado después por la aplicación) quedan en NULL. Es lo que permite
 * el rollback técnico por corrida y detectar actividad posterior. NO se añade a cf_emisiones (emisiones modernas).
 *
 * RESTRICT: una corrida con filas no se puede borrar. Además cf_eventos gana `notas` (marcas de la migración: año no
 * deducible o ambiguo, plantilla pendiente…), porque el año nunca se inventa y debe quedar constancia.
 */
return new class extends Migration
{
    private const TABLAS = [
        'cf_eventos',
        'cf_plantillas_legado_contenidos',
        'cf_plantillas_legado',
        'cf_certificados_legado',
        'cf_correos',
        'cf_descargas',
    ];

    public function up(): void
    {
        foreach (self::TABLAS as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                $table->foreignId('corrida_id')->nullable()->constrained('cf_migraciones_corridas')->restrictOnDelete();
                if ($tabla === 'cf_eventos') {
                    $table->text('notas')->nullable();
                }
            });
        }
    }

    public function down(): void
    {
        foreach (array_reverse(self::TABLAS) as $tabla) {
            Schema::table($tabla, function (Blueprint $table) use ($tabla) {
                if ($tabla === 'cf_eventos') {
                    $table->dropColumn('notas');
                }
                $table->dropConstrainedForeignId('corrida_id');
            });
        }
    }
};
