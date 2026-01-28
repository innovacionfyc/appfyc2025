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
        Schema::create('events', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('title');
            $table->string('slug')->unique();
            $table->longText('content');
            $table->dateTime('start_date')->index();
            $table->dateTime('end_date');
            $table->string('location_type');
            $table->string('address')->nullable();
           
            $table->enum('status', ['draft', 'published', 'canceled', 'finished'])->default('draft')->index();

            $table->foreignId('manager_id')->constrained('users')->onDelete('restrict');

            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        //
    }
};
