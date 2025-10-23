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
        // Update venues table untuk support local images (Ini sudah benar)
        Schema::table('venues', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('image_url'); // Path lokal
            $table->string('image_disk')->default('public')->after('image_path'); // Storage disk
        });

        // FIX: Ganti dari Schema::table menjadi Schema::create
        // Ini akan MEMBUAT tabel venue_images yang hilang
        Schema::create('venue_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('image_url', 500)->nullable(); // Kolom asli dari model

            // Kolom-kolom baru yang ingin kamu tambahkan
            $table->string('image_path')->nullable();
            $table->string('image_disk')->default('public');
            $table->string('thumbnail_path')->nullable();

            $table->timestamps();
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

        // FIX: Ganti menjadi dropIfExists
        Schema::dropIfExists('venue_images');
    }
};
