<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Historial de descargas. Cada descarga es de EXACTAMENTE una de dos cosas: una emisión moderna o un certificado
 * histórico. Se garantiza con un CHECK en MySQL/MariaDB (la validación de aplicación del modelo Descarga es la segunda
 * barrera). SQLite no admite ADD CONSTRAINT: allí solo rige la validación del modelo (las pruebas corren sobre SQLite).
 *
 * Las FK son RESTRICT a propósito: MySQL 8 no permite un CHECK sobre columnas con acciones referenciales CASCADE/SET
 * NULL, y una descarga nunca debe desaparecer por borrar su origen. La eliminación definitiva de bases deberá borrar
 * antes las descargas de sus emisiones (cuando esta tabla tenga datos y esa fase se aborde).
 */
return new class extends Migration
{
    private const RESTRICCION = 'cf_descargas_un_origen_chk';

    public function up(): void
    {
        Schema::create('cf_descargas', function (Blueprint $table) {
            $table->id();

            $table->foreignId('emision_id')->nullable()->constrained('cf_emisiones')->restrictOnDelete();
            $table->foreignId('certificado_legado_id')->nullable()->constrained('cf_certificados_legado')->restrictOnDelete();
            // Un participante eliminado no borra su historial de descargas.
            $table->foreignId('participante_id')->nullable()->constrained('cf_participantes')->nullOnDelete();

            // portal | correo | admin
            $table->string('via', 12);
            // useCurrent() fija un DEFAULT explícito (ver cf_emisiones.emitido_at): evita el ON UPDATE implícito.
            $table->timestamp('descargado_at')->useCurrent();
            // Hash de la IP (nunca la IP en claro).
            $table->char('ip_hash', 64)->nullable();

            $table->timestamps();

            $table->index('descargado_at', 'cf_descargas_fecha_idx');
        });

        // MySqlConnection cubre MySQL y MariaDB (MariaDbConnection la extiende). Se usa la conexión del Schema, no la
        // predeterminada, para que también funcione con otra conexión y con pretend() en las pruebas.
        $conexion = Schema::getConnection();
        if ($conexion instanceof MySqlConnection) {
            $conexion->statement('ALTER TABLE cf_descargas ADD CONSTRAINT '.self::RESTRICCION.' CHECK '
                .'((emision_id IS NOT NULL AND certificado_legado_id IS NULL) OR (emision_id IS NULL AND certificado_legado_id IS NOT NULL))');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_descargas');
    }
};
