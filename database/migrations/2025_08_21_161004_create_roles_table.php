<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estado_id')->default(1);
            $table->string('tipo_rol')->nullable(false);
            $table->boolean('editar')->nullable(false);
            $table->boolean('eliminar')->nullable(false);
            $table->boolean('crear')->nullable(false);
            $table->boolean('ver')->nullable(false);

            $table->timestamps();
             $table->softDeletes();


            $table->foreign('estado_id')->references('id')->on('estados')->onDelete('cascade');

        });

        DB::table('roles')->insert([
            [
                'tipo_rol' => 'Administrador',
                'editar' => true,
                'eliminar' => true,
                'crear' => true,
                'ver' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tipo_rol' => 'Apoyo',
                'editar' => true,
                'eliminar' => true,
                'crear' => true,
                'ver' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tipo_rol' => 'Conferencista',
                'editar' => true,
                'eliminar' => true,
                'crear' => true,
                'ver' => true,
                'created_at' => now(),
                'updated_at' => now()
            ],
            [
                'tipo_rol' => 'Participante',
                'editar' => true,
                'eliminar' => true,
                'crear' => true,
                'ver' => true,
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
        Schema::dropIfExists('roles');
    }
};
