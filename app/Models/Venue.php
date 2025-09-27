<?php
// app/Models/Venue.php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Venue extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'description',
        'address',
        'phone',
        'price_per_hour',
        'image',
        'facilities',
        'status',
        'latitude',
        'longitude'
    ];

    protected $casts = [
        'facilities' => 'array',
        'price_per_hour' => 'decimal:2',
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8'
    ];

    // Relationship dengan fields
    public function fields()
    {
        return $this->hasMany(Field::class);
    }

    // Relationship dengan bookings
    // public function bookings()
    // {
    //     return $this->hasManyThrough(Booking::class, Field::class);
    // }



    // Accessor untuk format price
    public function getFormattedPriceAttribute()
    {
        return 'Rp ' . number_format($this->price_per_hour, 0, ',', '.');
    }


    // Accessor untuk image URL
    public function getImageUrlAttribute()
    {
        if ($this->image) {
            return asset('storage/' . $this->image);
        }
        return asset('images/default-venue.jpg');
    }
}
