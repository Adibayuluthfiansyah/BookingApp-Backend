<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TimeSlot extends Model
{
    use HasFactory;

    protected $fillable = [
        'field_id',
        'start_time',
        'end_time',
        'price',
        'day_type',
    ];

    protected $casts = [
        'price' => 'decimal:2',
    ];

    // Relationships
    public function field()
    {
        return $this->belongsTo(Field::class);
    }

    // Helper methods
    public function isBooked($date)
    {
        return Booking::where('field_id', $this->field_id)
            ->where('booking_date', $date)
            ->where('start_time', $this->start_time)
            ->whereIn('status', ['pending', 'confirmed'])
            ->exists();
    }

    public function getFormattedPrice()
    {
        return 'Rp ' . number_format($this->price, 0, ',', '.');
    }
}
