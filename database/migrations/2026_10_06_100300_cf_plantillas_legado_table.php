<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Catálogo de imágenes de fondo de los certificados históricos, en DOS niveles. Son tablas SEPARADAS de `cf_plantillas`
 * a propósito: aquella exige PDF base con SHA y diseño del editor y pasa las imágenes por ImagenAPdf (que las reduce); el
 * certificado histórico necesita la imagen ORIGINAL intacta para reproducirse igual que antes.
 *
 *   cf_plantillas_legado_contenidos  = el CONTENIDO físico, único por SHA-256 (un blob, una sola copia en disco).
 *   cf_plantillas_legado             = cada NOMBRE/origen con el que el sistema viejo conocía una imagen (ruta original exacta).
 *
 * Por qué dos niveles: hay 632 archivos pero solo 624 contenidos distintos. Con una sola tabla unique por SHA se perdería
 * el nombre original de los repetidos; sin ese unique se duplicarían blobs. Además una imagen «faltante» es un NOMBRE sin
 * contenido, y la decisión de FPDF (tipo de imagen) depende de la extensión del NOMBRE, no del contenido.
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_plantillas_legado_contenidos', function (Blueprint $table) {
            $table->id();

            // Identidad física: una imagen idéntica se guarda una sola vez.
            $table->char('sha256', 64)->unique();
            $table->unsignedBigInteger('bytes');
            // Tipo REAL del contenido (no el que dice el nombre).
            $table->string('mime_real', 40)->nullable();
            $table->unsignedInteger('ancho_px')->nullable();
            $table->unsignedInteger('alto_px')->nullable();
            // Ruta relativa en el disco privado: credential-flow/legado/plantillas/{sha256}/original.{ext}. NULL hasta que se copie.
            $table->string('ruta_almacenada', 255)->nullable();

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();
        });

        Schema::create('cf_plantillas_legado', function (Blueprint $table) use ($mysql) {
            $table->id();

            // NULL si el archivo no existe (`faltante`). RESTRICT: un contenido no desaparece mientras algún nombre lo use.
            $table->foreignId('contenido_id')->nullable()->constrained('cf_plantillas_legado_contenidos')->restrictOnDelete();

            // Ruta original EXACTA en el sistema viejo (document/certImages/…). Solo informativa: nunca se usa como ruta física.
            // Collation binaria en MySQL/MariaDB: «Ética» y «ETICA» son nombres distintos del sistema viejo.
            $ruta = $table->string('ruta_original', 300)->unique();
            if ($mysql) {
                $ruta->collation('utf8mb4_bin');
            }
            $table->string('nombre_original', 255);
            // Sin mayúsculas, tildes ni separadores: solo para buscar y proponer candidatas.
            $table->string('nombre_normalizado', 255);
            // Lo que sigue al último punto del nombre, tal como lo lee FPDF (en nombres raros puede ser largo).
            $table->string('extension_original', 255)->nullable();

            // ¿El FPDF viejo habría podido dibujarla? (la extensión del nombre decide el tipo; luego se valida el contenido).
            $table->boolean('renderizable')->default(false);
            $table->string('motivo_no_renderizable', 60)->nullable();

            // ok | faltante | huerfana | candidata_revision | extension_invalida
            $table->string('estado', 20);
            $table->text('notas')->nullable();

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();

            $table->index('estado', 'cf_plantillas_legado_estado_idx');
            $table->index('nombre_normalizado', 'cf_plantillas_legado_nombre_norm_idx');
            $table->index('contenido_id', 'cf_plantillas_legado_contenido_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_plantillas_legado');
        Schema::dropIfExists('cf_plantillas_legado_contenidos');
    }
};
