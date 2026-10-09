<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Certificado histórico del sistema viejo: una fila reúne al participante y a su certificado (en el sistema viejo la
 * fila del participante ES el certificado). Es una tabla SEPARADA de cf_participantes/cf_emisiones: aquellas exigen
 * lote→plantilla del editor, PDF, hash, snapshot y código moderno en todas sus filas, y esas garantías no se tocan.
 *
 * El PDF se genera al primer acceso y se congela AQUÍ (pdf_*); hasta entonces es NULL. Los valores por defecto son
 * los seguros: una fila nueva no es visible en el portal y queda pendiente de conciliación.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cf_certificados_legado', function (Blueprint $table) {
            $table->id();

            $table->foreignId('evento_id')->constrained('cf_eventos')->restrictOnDelete();
            $table->foreignId('plantilla_legado_id')->nullable()->constrained('cf_plantillas_legado')->restrictOnDelete();

            $table->string('tipo_documento', 10)->nullable();
            $table->string('documento', 40)->nullable();
            // documento en mayúsculas y solo letras/dígitos: para buscar y agrupar duplicados.
            $table->string('documento_clave', 40);
            $table->string('nombre_completo', 255);

            $table->string('correo', 254)->nullable();
            $table->string('correo_normalizado', 254)->nullable();
            // valido | multiple | invalido | sin_correo
            $table->string('correo_estado', 12)->nullable();

            // Número impreso en el certificado viejo (4 o 5 dígitos). SIN UNIQUE: filas duplicadas del mismo
            // participante comparten código y no hay garantía histórica de unicidad.
            $table->string('codigo_legado', 20)->nullable();

            // Todo lo necesario para reproducir el certificado: cadenas ya compuestas, versión del renderer, etc.
            $table->json('snapshot_legado');

            // PDF congelado (disco privado, credential-flow/legado/…). NULL hasta el primer acceso.
            $table->string('pdf_archivo', 255)->nullable();
            $table->char('pdf_hash', 64)->nullable();
            $table->unsignedBigInteger('pdf_bytes')->nullable();
            $table->timestamp('materializado_at')->nullable();

            // vigente | revocado | reemplazado
            $table->string('estado', 12)->default('vigente');
            // ok | duplicado_consolidado | pendiente_conciliacion | revision_documento | pendiente_plantilla
            $table->string('conciliacion_estado', 30)->default('pendiente_conciliacion');
            $table->string('grupo_duplicado', 64)->nullable();
            $table->boolean('visible_portal')->default(false);

            $table->unsignedSmallInteger('intentos_generacion')->default(0);
            $table->string('ultimo_error_codigo', 40)->nullable();

            // Corrección: el histórico queda «reemplazado» por una emisión moderna. RESTRICT: no se pierde el rastro.
            $table->foreignId('reemplazado_por_emision_id')->nullable()->constrained('cf_emisiones')->restrictOnDelete();

            $table->timestamp('revocado_at')->nullable();
            $table->unsignedBigInteger('revocado_por')->nullable();
            $table->text('motivo_revocacion')->nullable();

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();

            // Explícito (y no solo el implícito de la FK en MySQL) para que exista igual en cualquier motor.
            $table->index('evento_id', 'cf_cert_legado_evento_idx');
            $table->index('documento_clave', 'cf_cert_legado_documento_idx');
            $table->index(['documento_clave', 'correo_normalizado'], 'cf_cert_legado_doc_correo_idx');
            $table->index('codigo_legado', 'cf_cert_legado_codigo_idx');
            $table->index('grupo_duplicado', 'cf_cert_legado_grupo_idx');
            $table->index('conciliacion_estado', 'cf_cert_legado_concil_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_certificados_legado');
    }
};
