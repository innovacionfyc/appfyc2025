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
        Schema::create('speakers', function (Blueprint $table) {
            $table->id();
            $table->uuid('uuid')->unique();
            $table->string('full_name')->index();
            $table->string('email')->unique();
            $table->string('specialty');
            $table->text('bio');
            $table->string('profile_photo')->nullable();
            $table->json('social_links')->nullable();
            $table->timestamps();
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
