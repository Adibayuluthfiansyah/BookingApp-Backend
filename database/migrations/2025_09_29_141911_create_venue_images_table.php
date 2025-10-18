<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Update venues table untuk support local images
        Schema::table('venues', function (Blueprint $table) {
            // Ubah image_url jadi nullable dan tambah kolom baru
            $table->string('image_path')->nullable()->after('image_url'); // Path lokal
            $table->string('image_disk')->default('public')->after('image_path'); // Storage disk
        });

        // Update venue_images table
        Schema::table('venue_images', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('image_url');
            $table->string('image_disk')->default('public')->after('image_path');
            $table->string('thumbnail_path')->nullable()->after('image_disk'); // Untuk optimize
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('venues', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_disk']);
        });

        Schema::table('venue_images', function (Blueprint $table) {
            $table->dropColumn(['image_path', 'image_disk', 'thumbnail_path']);
        });
    }
};
