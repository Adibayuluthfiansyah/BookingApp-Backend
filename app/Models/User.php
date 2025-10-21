<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Illuminate\Database\Eloquent\Relations\HasMany;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, Notifiable;

    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
        'role',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    // Helper methods untuk check role
    public function isAdmin(): bool
    {
        return $this->role === 'admin';
    }

    public function venues(): HasMany
    {
        // Hanya berlaku jika rolenya admin
        return $this->hasMany(Venue::class, 'owner_id');
    }

    // Helper method untuk cek apakah user adalah owner venue tertentu
    public function ownsVenue($venueId): bool
    {
        if ($this->role === 'super_admin') {
            return true; // Super admin bisa akses semua
        }

        return $this->venues()->where('id', $venueId)->exists();
    }

    public function isCustomer(): bool
    {
        return $this->role === 'customer';
    }

    // Get venue IDs yang dimiliki user
    public function getVenueIds(): array
    {
        if ($this->role === 'super_admin') {
            return Venue::pluck('id')->toArray();
        }

        return $this->venues()->pluck('id')->toArray();
    }
}
