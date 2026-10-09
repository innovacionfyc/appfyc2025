<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Database\Schema\ColumnDefinition;
use Illuminate\Support\Facades\Schema;

/**
 * STAGING del histórico de evaluaciones (sistema viejo) → Credential Flow. Fase 2.
 *
 * Estas tablas NO viven en database/migrations a propósito: un `migrate` normal (por ejemplo en un despliegue) no debe
 * crear tablas con datos personales del sistema viejo. Solo se instalan de forma explícita en una base LOCAL de staging:
 *
 *   php artisan migrate --path=database/staging/ev --force
 *
 * Cada fila conserva el VALOR ORIGINAL del sistema viejo y, aparte, el valor normalizado. Ninguna tabla final de
 * Credential Flow (cf_*) se toca ni se rellena desde aquí.
 *
 * Columnas de control comunes (todas las entidades importadas):
 *  - primera_snapshot_id / ultima_snapshot_id: primer y último snapshot en que apareció la fila.
 *  - hash_fila: SHA-256 de las columnas ORIGINALES de origen (detecta cambios).
 *  - estado_fila: nueva | igual | cambiada | ausente_en_origen (respecto del último snapshot cargado).
 *  - validacion: ok | advertencia | error; motivo: códigos separados por coma.
 *  - norm_version: versión de las reglas de normalización con que se calcularon las columnas derivadas.
 */
return new class extends Migration
{
    /** Columnas de control comunes a toda entidad importada. */
    private function control(Blueprint $table): void
    {
        $table->foreignId('primera_snapshot_id')->constrained('stg_ev_snapshots')->restrictOnDelete();
        $table->foreignId('ultima_snapshot_id')->constrained('stg_ev_snapshots')->restrictOnDelete();
        $table->char('hash_fila', 64);
        // nueva | igual | cambiada | ausente_en_origen
        $table->string('estado_fila', 20);
        // ok | advertencia | error
        $table->string('validacion', 12)->default('ok');
        $table->string('motivo', 500)->nullable();
        $table->unsignedSmallInteger('norm_version')->default(0);
        $table->timestamps();
    }

    /**
     * Columnas que se comparan o son únicas por su valor EXACTO (mayúsculas y tildes incluidas): en MySQL/MariaDB se declaran
     * con una collation binaria. La collation por defecto (utf8mb4_unicode_ci) trata «Ética» y «ETICA» como iguales y haría
     * chocar dos archivos del sistema viejo cuyos nombres solo difieren en mayúsculas o tildes.
     */
    private function exacta(ColumnDefinition $columna): ColumnDefinition
    {
        return in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true) ? $columna->collation('utf8mb4_bin') : $columna;
    }

    public function up(): void
    {
        Schema::create('stg_ev_snapshots', function (Blueprint $table) {
            $table->id();
            $table->string('etiqueta', 80)->unique();
            $table->string('origen', 255);
            // Solo el nombre del archivo: nunca rutas del servidor.
            $table->string('dump_archivo', 255)->nullable();
            $table->char('dump_sha256', 64)->nullable()->index();
            // Momento en que se tomó el snapshot (cabecera del dump).
            $table->dateTime('tomado_at')->nullable();
            $table->string('servidor_origen', 120)->nullable();
            // Hash de la estructura (tablas, columnas y tipos) de la base de origen.
            $table->char('esquema_sha256', 64)->nullable();
            $table->char('inventario_imagenes_sha256', 64)->nullable();
            $table->unsignedSmallInteger('norm_version');
            // Conteos por tabla de origen y por tabla de staging al terminar la carga.
            $table->json('conteos');
            $table->timestamps();
        });

        Schema::create('stg_ev_evento', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            $table->string('nombre_original', 250)->nullable();
            $table->string('nombre_normalizado', 250);
            $table->string('imagen_original', 250)->nullable();
            // Lo que sigue al último punto del nombre, tal como lo lee FPDF: en nombres raros puede ser largo (hasta el nombre entero).
            $table->string('imagen_extension', 255)->nullable();
            // ok | sin_imagen | archivo_faltante | extension_invalida
            $table->string('imagen_estado', 24)->nullable();
            $table->unsignedBigInteger('imagen_ref_id')->nullable();
            $table->unsignedSmallInteger('anio_deducido')->nullable();
            // nombre | imagen | null
            $table->string('anio_origen', 12)->nullable();
            $table->unsignedInteger('participantes_count')->default(0);
            $this->control($table);
            $table->index('imagen_estado', 'stg_ev_evento_imagen_estado_idx');
            $table->index(['estado_fila', 'validacion'], 'stg_ev_evento_estado_idx');
        });

        Schema::create('stg_ev_duplicados', function (Blueprint $table) {
            $table->unsignedBigInteger('id')->primary();
            $table->unsignedInteger('old_evento_id');
            // Seudónimo del documento: el reporte nunca expone el documento.
            $table->char('documento_clave_hash', 40);
            // identico | conflictivo
            $table->string('clasificacion', 12);
            // DIF_NOMBRE, DIF_TIPO, DIF_DOCUMENTO, DIF_CORREO, DIF_VERIF
            $table->string('etiquetas', 80)->nullable();
            $table->string('subtipo', 40)->nullable();
            $table->unsignedSmallInteger('filas');
            $table->json('old_ids');
            $table->unique(['old_evento_id', 'documento_clave_hash'], 'stg_ev_dup_evento_doc_uq');
            $table->index('clasificacion', 'stg_ev_dup_clasif_idx');
        });

        Schema::create('stg_ev_participante', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            $table->unsignedInteger('old_evento_id');
            $table->string('tipo_documento_original', 20)->nullable();
            $table->string('tipo_documento', 20)->nullable();
            $table->string('documento_original', 45)->nullable();
            // Mayúsculas y solo letras/dígitos (misma regla que cf_participantes.documento_clave).
            $this->exacta($table->string('documento_clave', 60));
            // Cómo lo imprimía el sistema viejo: tipo ": " number_format(documento) en PHP 7.4.
            $table->string('documento_impreso', 80)->nullable();
            // valido | vacio | anomalo
            $table->string('documento_estado', 12);
            $table->string('documento_detalle', 28)->nullable();
            // Espacios en blanco del original y dónde están (`lf@borde,tab@borde`); el original nunca se modifica.
            $table->string('documento_whitespace', 120)->nullable();
            // true si hubo que quitar espacios en blanco y aun así el sistema viejo imprimía lo mismo (DOCUMENTO_NORMALIZADO_WHITESPACE).
            $table->boolean('documento_normalizado_ws')->default(false);
            $table->string('nombre_original', 250)->nullable();
            $table->string('nombre_normalizado', 250);
            $table->string('correo_original', 200)->nullable();
            $table->string('correo_normalizado', 254)->nullable();
            // valido (exactamente 1 candidato válido) | multiple (más de 1: no se elige ninguno) | invalido | sin_correo.
            // correo_normalizado solo se llena con `valido`; con `multiple` queda NULL a propósito (no usable para OTP) y la
            // colección stg_ev_participante_correos es la fuente de verdad.
            $table->string('correo_estado', 12);
            $table->unsignedSmallInteger('correos_candidatos')->default(0);
            $table->unsignedSmallInteger('correos_validos')->default(0);
            // SHA-256 de los candidatos ordenados: sirve para comparar filas (duplicados) sin mirar el texto.
            $table->char('correos_firma', 64)->nullable();
            $table->unsignedInteger('num_verificacion')->nullable();
            $table->unsignedBigInteger('grupo_duplicado_id')->nullable();
            $table->boolean('tiene_evento')->default(false);
            $table->unsignedInteger('descargas_count')->default(0);
            $table->dateTime('primera_descarga_at')->nullable();
            $this->control($table);
            $table->index('old_evento_id', 'stg_ev_part_evento_idx');
            $table->index(['old_evento_id', 'documento_clave'], 'stg_ev_part_evento_doc_idx');
            $table->index('num_verificacion', 'stg_ev_part_verif_idx');
            $table->index('grupo_duplicado_id', 'stg_ev_part_grupo_idx');
            $table->index(['estado_fila', 'validacion'], 'stg_ev_part_estado_idx');
        });

        // Colección DERIVADA de correos candidatos por participante (se reconstruye completa en cada conciliación). El valor
        // real vive solo en esta base local de staging (material privado de migración): nunca se imprime ni sale de aquí.
        Schema::create('stg_ev_participante_correos', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('participante_old_id');
            $table->foreignId('snapshot_id')->constrained('stg_ev_snapshots')->restrictOnDelete();
            // Posición del candidato dentro del campo original (sin elegir ninguno como principal).
            $table->unsignedSmallInteger('orden');
            $this->exacta($table->string('correo_normalizado', 254));
            // Para contar y comparar en reportes sin leer direcciones.
            $table->char('correo_sha256', 64);
            // valido | invalido
            $table->string('estado', 12);
            $table->timestamps();
            $table->unique(['participante_old_id', 'orden'], 'stg_ev_pcorreos_part_orden_uq');
            $table->index('correo_sha256', 'stg_ev_pcorreos_sha_idx');
            $table->index('estado', 'stg_ev_pcorreos_estado_idx');
        });

        Schema::create('stg_ev_token', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            // El token NO se guarda en claro (da acceso a certificados): solo su SHA-256 y su largo.
            $table->char('codigo_sha256', 64)->nullable()->index();
            $table->unsignedSmallInteger('codigo_longitud')->nullable();
            $table->dateTime('fecha_creacion')->nullable();
            $table->unsignedInteger('old_participante_id')->nullable();
            $table->boolean('participante_existe')->default(false);
            $table->boolean('codigo_repetido')->default(false);
            // Destino futuro: los tokens no se migran (los reemplaza el OTP). Se conservan solo para auditoría.
            $table->string('destino_futuro', 12)->default('NO_MIGRAR');
            $this->control($table);
            $table->index('old_participante_id', 'stg_ev_token_part_idx');
        });

        Schema::create('stg_ev_descargas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            $table->dateTime('fecha')->nullable();
            $table->unsignedInteger('old_evento_id')->nullable();
            $table->unsignedInteger('old_participante_id')->nullable();
            $table->boolean('participante_existe')->default(false);
            // false si el evento de la descarga no es el del participante.
            $table->boolean('evento_coincide')->nullable();
            $table->boolean('es_primera')->default(false);
            $this->control($table);
            $table->index('old_participante_id', 'stg_ev_desc_part_idx');
        });

        Schema::create('stg_ev_encuesta', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            $table->unsignedInteger('old_evento_id')->nullable();
            $table->string('documento_original', 45)->nullable();
            $this->exacta($table->string('documento_clave', 60));
            $table->dateTime('fecha')->nullable();
            foreach (range(1, 9) as $i) {
                $table->text("pregunta{$i}")->nullable();
            }
            $table->text('justificacion_pregunta1')->nullable();
            $table->boolean('evento_existe')->default(false);
            $table->boolean('participante_existe')->default(false);
            $this->control($table);
            $table->index(['old_evento_id', 'documento_clave'], 'stg_ev_enc_evento_doc_idx');
        });

        Schema::create('stg_ev_preguntas', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            $table->text('texto')->nullable();
            $table->string('tipo_respuesta', 45)->nullable();
            $table->integer('num_opciones')->nullable();
            $this->control($table);
        });

        Schema::create('stg_ev_opciones', function (Blueprint $table) {
            $table->id();
            $table->unsignedInteger('old_id')->unique();
            $table->string('opcion', 250)->nullable();
            $table->unsignedInteger('old_pregunta_id')->nullable();
            $this->control($table);
        });

        Schema::create('stg_ev_imagenes', function (Blueprint $table) {
            $table->id();
            // Ruta relativa original (document/certImages/…): es la clave de la imagen (no tiene old_id).
            $this->exacta($table->string('ruta_relativa', 300))->unique();
            $table->string('nombre_original', 255);
            // Igual que imagen_extension: puede ser un texto largo cuando el nombre no tiene una extensión real.
            $table->string('extension', 255)->nullable();
            $table->char('sha256', 64)->index();
            $table->unsignedBigInteger('bytes');
            $table->unsignedInteger('ancho_px')->nullable();
            $table->unsignedInteger('alto_px')->nullable();
            $table->string('mime_real', 40)->nullable();
            // Regla replicada del FPDF 1.81 del sistema viejo (_parsejpg/_parsepng): ¿habría podido dibujarla?
            $table->boolean('renderizable_fpdf');
            $table->string('motivo_no_renderizable', 60)->nullable();
            $table->boolean('huerfana')->default(false);
            $table->unsignedInteger('old_evento_id')->nullable();
            $table->unsignedSmallInteger('eventos_enlazados')->default(0);
            $table->boolean('candidata_revision')->default(false);
            $table->unsignedInteger('candidata_old_evento_id')->nullable();
            $this->control($table);
            $table->index('old_evento_id', 'stg_ev_img_evento_idx');
        });

        Schema::create('stg_ev_historial', function (Blueprint $table) {
            $table->id();
            $table->string('entidad', 30);
            $table->unsignedBigInteger('old_id')->nullable();
            $table->string('clave', 300)->nullable();
            // Snapshot en que se detectó el cambio y último snapshot en que la fila aún tenía el valor anterior.
            $table->foreignId('snapshot_id')->constrained('stg_ev_snapshots')->restrictOnDelete();
            $table->unsignedBigInteger('snapshot_anterior_id');
            $table->char('hash_anterior', 64);
            $table->char('hash_nuevo', 64);
            // Columnas ORIGINALES de la versión anterior.
            $table->json('datos_anteriores');
            $table->timestamps();
            $table->index(['entidad', 'old_id'], 'stg_ev_hist_entidad_idx');
        });
    }

    public function down(): void
    {
        foreach (['stg_ev_historial', 'stg_ev_imagenes', 'stg_ev_participante_correos', 'stg_ev_opciones', 'stg_ev_preguntas', 'stg_ev_encuesta', 'stg_ev_descargas',
            'stg_ev_token', 'stg_ev_participante', 'stg_ev_duplicados', 'stg_ev_evento', 'stg_ev_snapshots'] as $tabla) {
            Schema::dropIfExists($tabla);
        }
    }
};
