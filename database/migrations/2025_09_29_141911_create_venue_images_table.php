<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('venue_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('image_url', 500);
            $table->string('caption')->nullable();
            $table->integer('display_order')->default(0);
            $table->timestamps();

            $table->index('venue_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('venue_images');
    }
};
