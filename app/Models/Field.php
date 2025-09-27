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
        'type',
        'price_per_hour',
        'description'
    ];

    protected $casts = [
        'price_per_hour' => 'decimal:2'
    ];

    // Relationship dengan venue
    public function venue()
    {
        return $this->belongsTo(Venue::class);
    }

    // Relationship dengan bookings
    // public function bookings()
    // {
    //     return $this->hasMany(Booking::class);
    // }


    // Scope berdasarkan type
    public function scopeType($query, $type)
    {
        return $query->where('type', $type);
    }

    // Accessor untuk price (use venue price if field price is null)
    public function getPriceAttribute()
    {
        return $this->price_per_hour ?? $this->venue->price_per_hour;
    }

    // Accessor untuk formatted price
    public function getFormattedPriceAttribute()
    {
        return 'Rp ' . number_format($this->price, 0, ',', '.');
    }

    // Method untuk check availability (placeholder untuk future booking system)
    public function isAvailable($date, $startTime, $endTime)
    {
        // Logic untuk check booking availability
        // Untuk sekarang return true
        return true;
    }
}
