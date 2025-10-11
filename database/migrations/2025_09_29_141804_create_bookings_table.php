<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('booking_number', 50)->unique();
            $table->foreignId('field_id')->constrained()->onDelete('restrict');
            $table->foreignId('time_slot_id');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->date('booking_date');
            $table->time('start_time');
            $table->time('end_time');

            // Data pemesan
            $table->string('customer_name');
            $table->string('customer_phone', 20);
            $table->string('customer_email')->nullable();
            $table->text('notes')->nullable();

            // Pricing
            $table->decimal('subtotal', 10, 2);
            $table->decimal('admin_fee', 10, 2)->default(5000);
            $table->decimal('total_amount', 10, 2);

            // Status
            $table->enum('status', ['pending', 'confirmed', 'cancelled', 'completed'])->default('pending');

            $table->timestamps();

            $table->index('booking_number');
            $table->index('booking_date');
            $table->index('status');
            $table->index(['field_id', 'booking_date']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bookings');
    }
};
