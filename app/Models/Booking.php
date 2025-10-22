<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class Booking extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_number',
        'field_id',
        'time_slot_id',
        'user_id',
        'booking_date',
        'start_time',
        'end_time',
        'customer_name',
        'customer_phone',
        'customer_email',
        'notes',
        'subtotal',
        'admin_fee',
        'total_amount',
        'status',
    ];

    protected $casts = [
        'booking_date' => 'date',
        'subtotal' => 'decimal:2',
        'admin_fee' => 'decimal:2',
        'total_amount' => 'decimal:2',
    ];

    // Relationships
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    public function timeSlot()
    {
        return $this->belongsTo(TimeSlot::class, 'time_slot_id');
    }

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    public function payment()
    {
        return $this->hasOne(Payment::class);
    }

    // Events
    protected static function boot()
    {
        parent::boot();

        static::creating(function ($booking) {
            if (empty($booking->booking_number)) {
                $booking->booking_number = static::generateBookingNumber();
            }
        });
    }

    // Helper methods
    public static function generateBookingNumber()
    {
        do {
            $number = 'KSHIR' . strtoupper(Str::random(8));
        } while (static::where('booking_number', $number)->exists());

        return $number;
    }

    public function isPending()
    {
        return $this->status === 'pending';
    }

    public function isConfirmed()
    {
        return $this->status === 'confirmed';
    }

    public function isCancelled()
    {
        return $this->status === 'cancelled';
    }

    public function isCompleted()
    {
        return $this->status === 'completed';
    }

    public function getFormattedTotal()
    {
        return 'Rp ' . number_format($this->total_amount, 0, ',', '.');
    }

    // ✅ TAMBAHAN: Helper untuk cek guest booking
    public function isGuestBooking(): bool
    {
        return $this->user_id === null;
    }
}
