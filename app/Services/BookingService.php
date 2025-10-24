<?php

namespace App\Services;

use App\Models\Booking;
use App\Models\TimeSlot;
use App\Models\Venue;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Carbon\Carbon;

class BookingService
{
    /**
     * Create new booking (support guest & logged-in user)
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
                'user_id' => $data['user_id'] ?? null,
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
            ->whereIn('status', ['pending', 'confirmed', 'completed', 'paid'])
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
     * Get dashboard statistics (Filtered by venue ownership)
     * ✅ DIPERBAIKI: Tidak pakai CONVERT_TZ, gunakan Carbon untuk timezone handling
     */
    public function getDashboardStats(Request $request)
    {
        $user = $request->user();

        if (!$user) {
            Log::warning('Attempted to get dashboard stats without authenticated user.');
            return [null];
        }

        // Ambil timezone dari request
        $timezone = $request->query('tz', 'Asia/Jakarta');

        try {
            // Dapatkan tanggal hari ini di timezone admin
            $now = Carbon::now($timezone);

            // Konversi ke UTC untuk query database (karena created_at di DB dalam UTC)
            $todayStart = $now->copy()->startOfDay()->setTimezone('UTC');
            $todayEnd = $now->copy()->endOfDay()->setTimezone('UTC');

            // Awal dan akhir bulan dalam UTC
            $startOfMonthUTC = $now->copy()->startOfMonth()->setTimezone('UTC');
            $endOfMonthUTC = $now->copy()->endOfMonth()->setTimezone('UTC');

            Log::info('Dashboard Stats Timezone Info', [
                'timezone' => $timezone,
                'today_start_utc' => $todayStart->toDateTimeString(),
                'today_end_utc' => $todayEnd->toDateTimeString(),
                'month_start_utc' => $startOfMonthUTC->toDateTimeString(),
                'month_end_utc' => $endOfMonthUTC->toDateTimeString(),
            ]);
        } catch (\Exception $e) {
            Log::error('Timezone conversion error', [
                'timezone' => $timezone,
                'error' => $e->getMessage()
            ]);
            // Fallback ke UTC
            $now = Carbon::now('UTC');
            $todayStart = $now->copy()->startOfDay();
            $todayEnd = $now->copy()->endOfDay();
            $startOfMonthUTC = $now->copy()->startOfMonth();
            $endOfMonthUTC = $now->copy()->endOfMonth();
        }

        $baseQuery = Booking::query();
        $venueIds = [];

        if ($user->role === 'admin') {
            $venueIds = $user->getVenueIds();
            Log::info('Filtering dashboard stats for admin', [
                'user_id' => $user->id,
                'venue_ids' => $venueIds
            ]);
            $baseQuery->whereHas('field', function ($query) use ($venueIds) {
                $query->whereIn('venue_id', $venueIds);
            });
        } elseif ($user->role === 'super_admin') {
            Log::info('Fetching dashboard stats for super admin', ['user_id' => $user->id]);
        }

        // ✅ PERBAIKAN: Gunakan whereBetween dengan Carbon (tanpa CONVERT_TZ)
        $stats = (clone $baseQuery)->selectRaw("
            COUNT(*) as total_bookings,
            COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as today_bookings,
            COUNT(CASE WHEN created_at BETWEEN ? AND ? THEN 1 END) as monthly_bookings,
            COUNT(CASE WHEN status = 'pending' THEN 1 END) as pending_bookings,
            SUM(CASE WHEN status IN ('confirmed', 'completed', 'paid') THEN total_amount ELSE 0 END) as total_revenue,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND status IN ('confirmed', 'completed', 'paid') THEN total_amount ELSE 0 END) as today_revenue,
            SUM(CASE WHEN created_at BETWEEN ? AND ? AND status IN ('confirmed', 'completed', 'paid') THEN total_amount ELSE 0 END) as monthly_revenue
        ", [
            // today_bookings
            $todayStart,
            $todayEnd,
            // monthly_bookings
            $startOfMonthUTC,
            $endOfMonthUTC,
            // today_revenue
            $todayStart,
            $todayEnd,
            // monthly_revenue
            $startOfMonthUTC,
            $endOfMonthUTC
        ])->first();

        // Debug log hasil query
        Log::info('Dashboard Stats Query Result', [
            'today_bookings' => $stats->today_bookings ?? 0,
            'today_revenue' => $stats->today_revenue ?? 0,
            'monthly_bookings' => $stats->monthly_bookings ?? 0,
            'monthly_revenue' => $stats->monthly_revenue ?? 0,
        ]);

        // 2. Recent bookings
        $recentBookings = (clone $baseQuery)
            ->with([
                'field:id,venue_id,name',
                'field.venue:id,name',
                'payment:id,booking_id,payment_status,paid_at',
                'user:id,name,email'
            ])
            ->select([
                'id',
                'booking_number',
                'field_id',
                'user_id',
                'booking_date',
                'start_time',
                'end_time',
                'customer_name',
                'customer_phone',
                'customer_email',
                'status',
                'total_amount',
                'created_at'
            ])
            ->orderBy('created_at', 'desc')
            ->limit(10)
            ->get();

        // 3. Booking by status
        $bookingsByStatus = (clone $baseQuery)
            ->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->pluck('count', 'status');

        // 4. Revenue by venue (filtered)
        $revenueQuery = DB::table('bookings')
            ->join('fields', 'bookings.field_id', '=', 'fields.id')
            ->join('venues', 'fields.venue_id', '=', 'venues.id')
            ->whereIn('bookings.status', ['confirmed', 'completed', 'paid'])
            ->whereBetween('bookings.created_at', [$startOfMonthUTC, $endOfMonthUTC]);

        if ($user->role === 'admin') {
            $revenueQuery->whereIn('venues.id', $venueIds);
        }

        $revenueByVenue = $revenueQuery
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

        // Hitung managed venues
        $managedVenuesCount = 0;
        if ($user->role === 'admin') {
            $managedVenuesCount = count($venueIds);
        } elseif ($user->role === 'super_admin') {
            $managedVenuesCount = Venue::count();
        }

        // Stats untuk guest vs logged-in users
        $guestBookingsCount = (clone $baseQuery)->whereNull('user_id')->count();
        $userBookingsCount = (clone $baseQuery)->whereNotNull('user_id')->count();

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
                'guest_bookings' => $guestBookingsCount,
                'user_bookings' => $userBookingsCount,
            ],
            'recent_bookings' => $recentBookings,
            'bookings_by_status' => $bookingsByStatus,
            'revenue_by_venue' => $revenueByVenue,
            'user_role' => $user->role,
            'managed_venues_count' => $managedVenuesCount,
        ];
    }

    /**
     * Get all bookings with filters (filtered by venue ownership)
     */
    public function getAllBookings(Request $request): LengthAwarePaginator
    {
        $user = $request->user();

        if (!$user) {
            Log::warning('Attempted to get all bookings without authenticated user.');
            $perPage = $request->get('per_page', 20);
            return new LengthAwarePaginator([], 0, $perPage);
        }

        $query = Booking::with([
            'field:id,venue_id,name',
            'field.venue:id,name',
            'payment:booking_id,payment_status',
            'user:id,name,email'
        ]);

        $venueIds = [];

        // Filter HANYA untuk 'admin', BUKAN 'super_admin'
        if ($user->role === 'admin') {
            $venueIds = $user->getVenueIds();
            Log::info('Filtering bookings for admin', ['user_id' => $user->id, 'venue_ids' => $venueIds]);
            $query->whereHas('field', function ($q) use ($venueIds) {
                $q->whereIn('venue_id', $venueIds);
            });
        } elseif ($user->role === 'super_admin') {
            Log::info('Fetching all bookings for super admin', ['user_id' => $user->id]);
        }

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

        // Filter by booking type (guest vs user)
        if ($request->filled('booking_type')) {
            if ($request->booking_type === 'guest') {
                $query->whereNull('user_id');
            } elseif ($request->booking_type === 'user') {
                $query->whereNotNull('user_id');
            }
        }

        // Filter by date range (berdasarkan TANGGAL MAIN)
        if ($request->filled('start_date')) {
            $query->where('booking_date', '>=', $request->start_date);
        }
        if ($request->filled('end_date')) {
            $query->where('booking_date', '<=', $request->end_date);
        }

        // Filter by created_at (TANGGAL BOOKING)
        if ($request->filled('created_start_date')) {
            $query->where('created_at', '>=', Carbon::parse($request->created_start_date)->startOfDay());
        }
        if ($request->filled('created_end_date')) {
            $query->where('created_at', '<=', Carbon::parse($request->created_end_date)->endOfDay());
        }

        // Filter by venue
        if ($request->filled('venue_id')) {
            if ($user->role === 'admin' && !in_array($request->venue_id, $venueIds)) {
                return new LengthAwarePaginator([], 0, $request->get('per_page', 20));
            }
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
                    ->orWhere('customer_phone', 'like', "%{$search}%")
                    ->orWhereHas('field.venue', function ($vq) use ($search) {
                        $vq->where('name', 'like', "%{$search}%");
                    })
                    ->orWhereHas('field', function ($fq) use ($search) {
                        $fq->where('name', 'like', "%{$search}%");
                    });
            });
        }

        // Sort
        $sortBy = $request->get('sort_by', 'created_at');
        $sortOrder = $request->get('sort_order', 'desc');
        $allowedSorts = ['created_at', 'booking_date', 'total_amount', 'status'];
        if (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortOrder);
        } else {
            $query->orderBy('created_at', 'desc');
        }

        // Paginate
        $perPage = $request->get('per_page', 20);
        return $query->paginate($perPage);
    }
}
