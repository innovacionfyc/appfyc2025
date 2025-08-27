<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

class CreateEstadosTable extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('estados', function (Blueprint $table) {
            $table->id();
            $table->string('tipo_estado', 255)->nullable(false);
            $table->string('categoria_estado', 255)->nullable();
            $table->timestamps();
             $table->softDeletes();
        });

        // Insertar datos iniciales
        DB::table('estados')->insert([

            // --- Categoría: General / Ciclo de Vida ---
            [
                'id' => 1,
                'tipo_estado' => 'Activo',
                'categoria_estado' => 'General',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 2,
                'tipo_estado' => 'Inactivo',
                'categoria_estado' => 'General',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 3,
                'tipo_estado' => 'Suspendido',
                'categoria_estado' => 'General',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 4,
                'tipo_estado' => 'Archivado',
                'categoria_estado' => 'General',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 5,
                'tipo_estado' => 'Eliminado',
                'categoria_estado' => 'General',
                'created_at' => now(),
                'updated_at' => now()
            ],

            // --- Categoría: Contenido ---
            [
                'id' => 6,
                'tipo_estado' => 'Borrador',
                'categoria_estado' => 'Contenido',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 7,
                'tipo_estado' => 'Publicado',
                'categoria_estado' => 'Contenido',
                'created_at' => now(),
                'updated_at' => now()
            ],

            // --- Categoría: Proceso / Flujo de Trabajo ---
            [
                'id' => 8,
                'tipo_estado' => 'Pendiente',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 9,
                'tipo_estado' => 'En progreso',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 10,
                'tipo_estado' => 'En espera',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 11,
                'tipo_estado' => 'En revisión',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 12,
                'tipo_estado' => 'Aprobado',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 13,
                'tipo_estado' => 'Rechazado',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 14,
                'tipo_estado' => 'Cancelado',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 15,
                'tipo_estado' => 'Finalizado',
                'categoria_estado' => 'Proceso',
                'created_at' => now(),
                'updated_at' => now()
            ],

            // --- Categoría: Pagos ---
            [
                'id' => 16,
                'tipo_estado' => 'Pendiente',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 17,
                'tipo_estado' => 'Procesando',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 18,
                'tipo_estado' => 'Pagado',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 19,
                'tipo_estado' => 'Rechazado',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 20,
                'tipo_estado' => 'Cancelado',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 21,
                'tipo_estado' => 'Reembolsado',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 22,
                'tipo_estado' => 'Vencido',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'id' => 23,
                'tipo_estado' => 'En Disputa',
                'categoria_estado' => 'Pagos',
                'created_at' => now(),
                'updated_at' => now()
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('estados');
    }
}

