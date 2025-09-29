<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Payment extends Model
{
    use HasFactory;

    protected $fillable = [
        'booking_id',
        'payment_method',
        'amount',
        'payment_proof',
        'payment_status',
        'paid_at',
        'verified_at',
        'verified_by',
        'notes',
    ];

    protected $casts = [
        'amount' => 'decimal:2',
        'paid_at' => 'datetime',
        'verified_at' => 'datetime',
    ];

    // Relationships
    public function booking()
    {
        return $this->belongsTo(Booking::class);
    }

    public function verifier()
    {
        return $this->belongsTo(User::class, 'verified_by');
    }

    // Helper methods
    public function isPending()
    {
        return $this->payment_status === 'pending';
    }

    public function isVerified()
    {
        return $this->payment_status === 'verified';
    }

    public function isRejected()
    {
        return $this->payment_status === 'rejected';
    }

    public function markAsVerified($adminId)
    {
        $this->update([
            'payment_status' => 'verified',
            'verified_at' => now(),
            'verified_by' => $adminId,
        ]);

        $this->booking->update(['status' => 'confirmed']);
    }
}
