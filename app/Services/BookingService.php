<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\Field;
use App\Models\Payment;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

class BookingService
{
    /**
     * Create new booking
     */
    public function createBooking(array $data)
    {
        // Get time slot dengan field dan venue sekali query
        $timeSlot = TimeSlot::with('field.venue')
            ->findOrFail($data['time_slot_id']);

        // Check availability dengan index
        if ($this->isSlotBooked($data['field_id'], $data['time_slot_id'], $data['booking_date'])) {
            throw new \Exception('Slot sudah dibooking oleh orang lain');
        }

        // Calculate price
        $pricing = $this->calculatePricing($timeSlot->price);

        return DB::transaction(function () use ($data, $timeSlot, $pricing) {
            // Create booking
            $booking = Booking::create([
                'field_id' => $data['field_id'],
                'time_slot_id' => $data['time_slot_id'],
                'user_id' => Auth::check() ? Auth::id() : null,
                'booking_date' => $data['booking_date'],
                'start_time' => $timeSlot->start_time,
                'end_time' => $timeSlot->end_time,
                'customer_name' => $data['customer_name'],
                'customer_phone' => $data['customer_phone'],
                'customer_email' => $data['customer_email'],
                'notes' => $data['notes'] ?? null,
                'subtotal' => $pricing['subtotal'],
                'admin_fee' => $pricing['admin_fee'],
                'total_amount' => $pricing['total'],
                'status' => 'pending',
            ]);

            return $booking;
        });
    }

    /**
     * Check if slot is already booked (optimized dengan index)
     */
    public function isSlotBooked($fieldId, $timeSlotId, $date): bool
    {
        return Booking::where([
            ['field_id', '=', $fieldId],
            ['time_slot_id', '=', $timeSlotId],
            ['booking_date', '=', $date],
        ])
            ->whereIn('status', ['pending', 'confirmed', 'completed'])
            ->exists();
    }

    /**
     * Calculate pricing
     */
    private function calculatePricing(float $basePrice): array
    {
        $subtotal = $basePrice;
        $adminFee = 5000;
        $total = $subtotal + $adminFee;

        return [
            'subtotal' => $subtotal,
            'admin_fee' => $adminFee,
            'total' => $total,
        ];
    }

    /**
     * Cancel booking
     */
    public function cancelBooking(string $bookingNumber)
    {
        $booking = Booking::where('booking_number', $bookingNumber)
            ->with('payment')
            ->firstOrFail();

        if (!in_array($booking->status, ['pending'])) {
            throw new \Exception('Booking tidak dapat dibatalkan. Status: ' . $booking->status);
        }

        if ($booking->payment && $booking->payment->payment_status === 'verified') {
            throw new \Exception('Booking tidak dapat dibatalkan karena sudah dibayar');
        }

        return DB::transaction(function () use ($booking) {
            $booking->update(['status' => 'cancelled']);

            if ($booking->payment) {
                $booking->payment->update(['payment_status' => 'rejected']);
            }

            return $booking;
        });
    }

    /**
     * Get dashboard statistics 
     */
    public function getDashboardStats()
    {
        $today = now()->format('Y-m-d');
        $thisMonth = now()->format('Y-m');

        // 1. Single query untuk semua aggregate stats
        $stats = Booking::selectRaw("
        COUNT(*) as total_bookings,
        COUNT(CASE WHEN DATE(booking_date) = ? THEN 1 END) as today_bookings,
        COUNT(CASE WHEN DATE_FORMAT(booking_date, '%Y-%m') = ? THEN 1 END) as monthly_bookings,
        COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_bookings,
        SUM(CASE WHEN status IN ('confirmed', 'completed') THEN total_amount ELSE 0 END) as total_revenue,
        SUM(CASE WHEN DATE(booking_date) = ? AND status IN ('confirmed', 'completed') THEN total_amount ELSE 0 END) as today_revenue,
        SUM(CASE WHEN DATE_FORMAT(booking_date, '%Y-%m') = ? AND status IN ('confirmed', 'completed') THEN total_amount ELSE 0 END) as monthly_revenue
    ", [$today, $thisMonth, $today, $thisMonth])
            ->first();

        // 2. Recent bookings
        $recentBookings = DB::table('bookings')
            ->join('fields', 'bookings.field_id', '=', 'fields.id')
            ->join('venues', 'fields.venue_id', '=', 'venues.id')
            ->leftJoin('payments', 'bookings.id', '=', 'payments.booking_id')
            ->select(
                'bookings.id',
                'bookings.booking_number',
                'bookings.customer_name',
                'bookings.status',
                'bookings.total_amount',
                'bookings.created_at',
                'venues.name as venue_name',
                'fields.name as field_name',
                'payments.payment_status'
            )
            ->orderBy('bookings.created_at', 'desc')
            ->limit(10)
            ->get();

        // 3. Booking by status
        $bookingsByStatus = Booking::selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // 4. Revenue by venue 
        $revenueByVenue = DB::table('bookings')
            ->join('fields', 'bookings.field_id', '=', 'fields.id')
            ->join('venues', 'fields.venue_id', '=', 'venues.id')
            ->where('bookings.status', 'confirmed')
            ->orWhere('bookings.status', 'completed')
            ->select(
                'venues.id as venue_id',
                'venues.name as venue_name',
                DB::raw('SUM(bookings.total_amount) as revenue'),
                DB::raw('COUNT(bookings.id) as booking_count')
            )
            ->groupBy('venues.id', 'venues.name')
            ->orderByDesc('revenue')
            ->limit(5)
            ->get();

        return [
            'today' => [
                'bookings' => $stats->today_bookings ?? 0,
                'revenue' => $stats->today_revenue ?? 0,
            ],
            'monthly' => [
                'bookings' => $stats->monthly_bookings ?? 0,
                'revenue' => $stats->monthly_revenue ?? 0,
            ],
            'overall' => [
                'total_bookings' => $stats->total_bookings ?? 0,
                'total_revenue' => $stats->total_revenue ?? 0,
                'pending_bookings' => $stats->pending_bookings ?? 0,
            ],
            'recent_bookings' => $recentBookings,
            'bookings_by_status' => $bookingsByStatus,
            'revenue_by_venue' => $revenueByVenue,
        ];
    }

    /**
     * Get all bookings with filters (optimized)
     */
    public function getAllBookings(Request $request)
    {
        $query = Booking::with([
            'field:id,venue_id,name',
            'field.venue:id,name',
            'payment:booking_id,payment_status',
            'user:id,name,email'
        ]);

        // Filter by status
        if ($request->filled('status') && $request->status !== 'all') {
            $query->where('status', $request->status);
        }

        // Filter by payment status
        if ($request->filled('payment_status') && $request->payment_status !== 'all') {
            $query->whereHas('payment', function ($q) use ($request) {
                $q->where('payment_status', $request->payment_status);
            });
        }

        // Filter by date range
        if ($request->filled('start_date')) {
            $query->where('booking_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('booking_date', '<=', $request->end_date);
        }

        // Filter by venue
        if ($request->filled('venue_id')) {
            $query->whereHas('field', function ($q) use ($request) {
                $q->where('venue_id', $request->venue_id);
            });
        }

        // Search
        if ($request->filled('search')) {
            $search = $request->search;
            $query->where(function ($q) use ($search) {
                $q->where('booking_number', 'like', "%{$search}%")
                    ->orWhere('customer_name', 'like', "%{$search}%")
                    ->orWhere('customer_email', 'like', "%{$search}%")
                    ->orWhere('customer_phone', 'like', "%{$search}%");
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        // Paginate
        $perPage = $request->get('per_page', 20);
        return $query->paginate($perPage);
    }
}
