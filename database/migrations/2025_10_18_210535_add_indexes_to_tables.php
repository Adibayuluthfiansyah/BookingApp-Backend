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
        // Add indexes to bookings table
        Schema::table('bookings', function (Blueprint $table) {
            $table->index('booking_date', 'idx_booking_date');
            $table->index('status', 'idx_status');
            $table->index('booking_number', 'idx_booking_number');

            // Composite index untuk query yang sering dipakai
            $table->index(['field_id', 'booking_date', 'time_slot_id'], 'idx_field_date_slot');
            $table->index(['booking_date', 'status'], 'idx_date_status');
        });

        // Add indexes to time_slots table
        Schema::table('time_slots', function (Blueprint $table) {
            $table->index('field_id', 'idx_field_id');
        });

        // Add indexes to fields table
        Schema::table('fields', function (Blueprint $table) {
            $table->index('venue_id', 'idx_venue_id');
        });

        // Add indexes to payments table
        Schema::table('payments', function (Blueprint $table) {
            $table->index('booking_id', 'idx_booking_id');
            $table->index('payment_status', 'idx_payment_status');
        });

        // Add indexes to venues table
        Schema::table('venues', function (Blueprint $table) {
            $table->index('slug', 'idx_slug');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('bookings', function (Blueprint $table) {
            $table->dropIndex('idx_booking_date');
            $table->dropIndex('idx_status');
            $table->dropIndex('idx_booking_number');
            $table->dropIndex('idx_field_date_slot');
            $table->dropIndex('idx_date_status');
        });

        Schema::table('time_slots', function (Blueprint $table) {
            $table->dropIndex('idx_field_id');
        });

        Schema::table('fields', function (Blueprint $table) {
            $table->dropIndex('idx_venue_id');
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->dropIndex('idx_booking_id');
            $table->dropIndex('idx_payment_status');
        });

        Schema::table('venues', function (Blueprint $table) {
            $table->dropIndex('idx_slug');
        });
    }
};
