<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Facility extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'icon',
    ];

    // Relationships
    public function venues()
    {
        return $this->belongsToMany(Venue::class, 'venue_facilities')
            ->withTimestamps();
    }
}
