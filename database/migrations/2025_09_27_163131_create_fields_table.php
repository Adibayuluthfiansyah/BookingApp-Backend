<?php


use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up()
    {
        Schema::create('fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('venue_id')->constrained()->onDelete('cascade');
            $table->string('name'); // Lapangan A, B, C
            $table->enum('type', ['futsal', 'mini_soccer'])->default('mini_soccer');
            $table->decimal('price_per_hour', 10, 2)->nullable(); // Override venue price if needed
            $table->enum('status', ['active', 'maintenance', 'inactive'])->default('active');
            $table->text('description')->nullable();
            $table->timestamps();
        });
    }

    public function down()
    {
        Schema::dropIfExists('fields');
    }
};
