<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\MySqlConnection;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Códigos HISTÓRICOS asignados por Credential Flow (Fase 10B-1.5). `cf_certificados_legado.codigo_legado` conserva EXCLUSIVAMENTE lo que
 * existía en el sistema viejo; lo que asigna Credential Flow vive aquí, separado e inmutable.
 *
 *   cf_codigos_historicos            un código por PAR lógico (evento + documento), UNIQUE por código y por `par_hash` (HMAC: el
 *                                    documento nunca se guarda en claro). FK RESTRICT al evento y al certificado canónico. Nunca se
 *                                    actualiza ni se borra desde la aplicación.
 *   cf_codigo_historico_contador     UNA fila (id = 1): siguiente número a asignar y los límites del rango reservado. Se bloquea con
 *                                    `lockForUpdate` al asignar; nunca se usa MAX(codigo)+1 como fuente de verdad.
 *
 * Una asignación puede dejar HUECOS y es correcto: el código se confirma ANTES de renderizar; si el render falla, el número queda
 * reservado y nunca se recicla (el sistema viejo también tenía huecos).
 */
return new class extends Migration
{
    public function up(): void
    {
        $mysql = Schema::getConnection() instanceof MySqlConnection;

        Schema::create('cf_codigos_historicos', function (Blueprint $table) {
            $table->id();

            // 5 dígitos como texto (se compara con codigo_legado, que también es texto).
            $table->string('codigo', 5)->unique();
            $table->foreignId('evento_id')->constrained('cf_eventos')->restrictOnDelete();
            $table->foreignId('certificado_canonico_id')->constrained('cf_certificados_legado')->restrictOnDelete();
            // HMAC(evento + documento_clave): identifica el par sin guardar el documento.
            $table->char('par_hash', 64)->unique();
            $table->timestamp('asignado_at')->useCurrent();
            $table->string('origen', 30)->default('credential_flow');

            $table->timestamps();

            $table->index('evento_id', 'cf_codigos_hist_evento_idx');
            $table->index('certificado_canonico_id', 'cf_codigos_hist_cert_idx');
        });

        Schema::create('cf_codigo_historico_contador', function (Blueprint $table) {
            $table->unsignedTinyInteger('id')->primary();
            $table->unsignedInteger('inicio');
            $table->unsignedInteger('fin');
            // Siguiente número a asignar. Puede quedar en fin + 1 (rango agotado).
            $table->unsignedInteger('siguiente');
            $table->timestamps();
        });

        $inicio = (int) config('credential_flow.codigos_historicos.inicio', 50000);
        $fin = (int) config('credential_flow.codigos_historicos.fin', 99999);
        DB::table('cf_codigo_historico_contador')->insert(['id' => 1, 'inicio' => $inicio, 'fin' => $fin, 'siguiente' => $inicio, 'created_at' => now(), 'updated_at' => now()]);

        if ($mysql) {
            $c = Schema::getConnection();
            $c->statement("ALTER TABLE cf_codigos_historicos ADD CONSTRAINT cf_codigos_hist_formato_chk CHECK (codigo REGEXP '^[0-9]{4,5}$')");
            $c->statement('ALTER TABLE cf_codigo_historico_contador ADD CONSTRAINT cf_codigo_contador_unica_chk CHECK (id = 1 AND inicio >= 1000 AND fin <= 99999 AND inicio <= fin AND siguiente >= inicio)');
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('cf_codigo_historico_contador');
        Schema::dropIfExists('cf_codigos_historicos');
    }
};
