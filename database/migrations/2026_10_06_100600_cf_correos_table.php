<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Correos asociados. Un participante nativo o un certificado histórico puede tener VARIOS correos (en el sistema viejo
 * hay documentos con hasta 10 correos válidos): los campos simples `correo*` de cf_participantes y cf_certificados_legado
 * no bastan. Cada fila pertenece a EXACTAMENTE un propietario (participante o certificado histórico).
 *
 * Garantías en MySQL/MariaDB (CHECK, igual que cf_descargas): un solo propietario, estado y origen válidos, y un correo
 * principal siempre válido. SQLite no admite ADD CONSTRAINT: allí rige la validación de aplicación del modelo Correo.
 *
 * Las FK son RESTRICT a propósito: borrar un participante o un certificado nunca debe borrar en silencio sus correos
 * (trazabilidad). Además MySQL 8 no permite un CHECK sobre columnas con acciones referenciales CASCADE/SET NULL.
 *
 * El mismo correo puede pertenecer a propietarios distintos (varias personas comparten dirección), pero no repetirse
 * dentro del mismo propietario: los UNIQUE compuestos lo garantizan (los NULL del otro propietario no chocan).
 */
return new class extends Migration
{
    private const CHECKS = [
        'cf_correos_un_propietario_chk' => '((participante_id IS NOT NULL AND certificado_legado_id IS NULL) OR (participante_id IS NULL AND certificado_legado_id IS NOT NULL))',
        'cf_correos_estado_chk' => "(estado IN ('valido', 'invalido'))",
        'cf_correos_origen_chk' => "(origen IN ('credential_flow', 'legado'))",
        'cf_correos_principal_valido_chk' => "(es_principal = 0 OR estado = 'valido')",
    ];

    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_correos', function (Blueprint $table) use ($mysql) {
            $table->id();

            $table->foreignId('participante_id')->nullable()->constrained('cf_participantes')->restrictOnDelete();
            $table->foreignId('certificado_legado_id')->nullable()->constrained('cf_certificados_legado')->restrictOnDelete();

            // Tal como llegó (la dirección original no se modifica).
            $table->string('correo', 254);
            // trim + minúsculas: solo para comparar y buscar. Collation binaria en MySQL/MariaDB: la normalización ya la hace
            // la aplicación, y la collation por defecto (unicode_ci) igualaría direcciones que solo difieren en tildes.
            $normalizado = $table->string('correo_normalizado', 254);
            if ($mysql) {
                $normalizado->collation('utf8mb4_bin');
            }

            // valido | invalido
            $table->string('estado', 10);
            // Posición entre los correos del mismo propietario (1 = el primero).
            $table->unsignedSmallInteger('orden')->default(1);
            // Como máximo uno por propietario y siempre válido; puede no haber ninguno. El OTP no depende de él.
            $table->boolean('es_principal')->default(false);
            // credential_flow | legado
            $table->string('origen', 20);

            // Requeridos por el trait HasAuditFields
            $table->unsignedBigInteger('created_by')->nullable();
            $table->unsignedBigInteger('update_by')->nullable();

            $table->timestamps();

            // Los dos UNIQUE sirven además de índice de cada FK (empiezan por la columna de propietario).
            $table->unique(['participante_id', 'correo_normalizado'], 'cf_correos_participante_correo_uq');
            $table->unique(['certificado_legado_id', 'correo_normalizado'], 'cf_correos_certificado_correo_uq');
            // «¿a quién pertenece este correo?» (portal / OTP).
            $table->index('correo_normalizado', 'cf_correos_correo_idx');
        });

        if ($mysql) {
            foreach (self::CHECKS as $nombre => $expresion) {
                Schema::getConnection()->statement("ALTER TABLE cf_correos ADD CONSTRAINT {$nombre} CHECK {$expresion}");
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_correos');
    }
};
