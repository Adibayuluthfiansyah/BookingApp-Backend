<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Venue extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'owner_id',
        'slug',
        'description',
        'address',
        'city',
        'province',
        'latitude',
        'longitude',
        'image_url',
        'facebook_url',
        'instagram_url',
    ];

    protected $casts = [
        'latitude' => 'decimal:8',
        'longitude' => 'decimal:8',
    ];

    // Relationships
    public function fields()
    {
        return $this->hasMany(Field::class);
    }

    // Relasi ke User (pemilik venue)
    public function owner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'owner_id');
    }

    public function facilities()
    {
        return $this->belongsToMany(Facility::class, 'venue_facilities')
            ->withTimestamps();
    }

    public function images()
    {
        return $this->hasMany(VenueImage::class)->orderBy('display_order');
    }

    // Helper methods
    public function getRouteKeyName()
    {
        return 'slug';
    }
}
