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
        Schema::create('usuarios', function (Blueprint $table) {
            $table->id();
            $table->string('google_id')->nullable()->unique();
            $table->unsignedBigInteger('estado_id')->default(1);
           

            $table->string('numero_documento')->unique()->nullable();
            $table->string('password')->nullable();
            $table->timestamps();
            $table->softDeletes();

            $table->foreignId('created_by')->nullable()->constrained('usuarios')->onDelete('set null');
            $table->foreignId('updated_by')->nullable()->constrained('usuarios')->onDelete('set null');
        });

        DB::table('usuarios')->insert([
            [
                'estado_id' => 1,
                'numero_documento' => '1013580753',
                'password' => bcrypt('123456'),
                'created_by' =>1,
                'updated_by' =>1
            ],
            [
                'estado_id' => 1,
                'numero_documento' => '777',
                'password' => bcrypt('123456'),
                'created_by' =>1,
                'updated_by' =>1
            ],
        ]);
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('empleados');
    }
};
