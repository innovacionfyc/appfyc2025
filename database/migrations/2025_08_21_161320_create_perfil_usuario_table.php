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
        Schema::create('perfil_usuario', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('estado_id')->default(1);
            $table->unsignedBigInteger('usuario_id')->nullable();
            $table->unsignedBigInteger('rol_id')->default(1);

            $table->string('ruta_foto')->nullable();
            $table->string('primer_nombre')->nullable();
            $table->string('segundo_nombre')->nullable();
            $table->string('primer_apellido')->nullable();
            $table->string('segundo_apellido')->nullable();
            $table->string('telefono', 25)->nullable();
            $table->string('correo')->nullable();
            $table->string('genero')->nullable();
            $table->string('cargo')->nullable();

            $table->foreign('estado_id')->references('id')->on('estados')->onDelete('cascade');
            $table->foreign('usuario_id')->references('id')->on('usuarios')->onDelete('cascade');
            $table->foreign('rol_id')->references('id')->on('roles')->onDelete('cascade');
           

            $table->softDeletes();

            $table->timestamps();
            $table->foreignId('created_by')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('usuarios')->onDelete('set null');

        });

        DB::table('perfil_usuario')->insert([
            [
                'usuario_id' => 1,
                'rol_id' => 1,
                'primer_nombre' => 'Jhoann',
                'segundo_nombre' => 'Sebastián',
                'primer_apellido' => 'Zamudio',
                'segundo_apellido' => 'Marulanda',
                'telefono' => '3165114410',
                'correo' => 'sebastianzamudio2004@gmail.com',
                'genero' => 'Masculino',
                'cargo' => 'Líder de Comunicaciones',
                'created_at' => now(),
                'updated_at' => now(),
                'created_by' => 1,
                'updated_by' => 1,
            ],
            [
                'usuario_id' => 2,
                'rol_id' => 1,
                'primer_nombre' => 'Erik',
                'segundo_nombre' => 'Manuel',
                'primer_apellido' => 'Guevara',
                'segundo_apellido' => 'Ladino',
                'telefono' => '0000000000',
                'correo' => 'emgladino@gmail.com',
                'genero' => 'Masculino',
                'cargo' => 'Director Innovación TI',
                'created_at' => now(),
                'updated_at' => now(),
                'created_by' => 1,
                'updated_by' => 1,
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('perfil_usuario');
    }
};
