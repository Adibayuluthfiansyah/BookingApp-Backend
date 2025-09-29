<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Field extends Model
{
    use HasFactory;

    protected $fillable = [
        'venue_id',
        'name',
        'field_type',
        'description',
    ];

    // Relationships
    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    public function timeSlots()
    {
        return $this->hasMany(TimeSlot::class);
    }

    public function bookings()
    {
        return $this->hasMany(Booking::class);
    }

    // Helper methods
    public function getAvailableSlots($date)
    {
        $bookedSlots = $this->bookings()
            ->where('booking_date', $date)
            ->whereIn('status', ['pending', 'confirmed'])
            ->pluck('start_time')
            ->toArray();

        return $this->timeSlots()
            ->whereNotIn('start_time', $bookedSlots)
            ->get();
    }
}
