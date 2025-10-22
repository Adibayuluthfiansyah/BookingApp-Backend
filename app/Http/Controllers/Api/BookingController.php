<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Services\BookingService;
use App\Services\MidtransService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

class BookingController extends Controller
{
    private BookingService $bookingService;
    private MidtransService $midtransService;

    public function __construct(BookingService $bookingService, MidtransService $midtransService)
    {
        $this->bookingService = $bookingService;
        $this->midtransService = $midtransService;
    }

    /**
     * Create new booking (PUBLIC - support guest & logged-in user)
     */
    public function createBooking(Request $request)
    {
        try {
            Log::info('=== BOOKING REQUEST START ===', $request->all());

            $validated = $request->validate([
                'field_id' => 'required|exists:fields,id',
                'time_slot_id' => 'required|exists:time_slots,id',
                'booking_date' => 'required|date|after_or_equal:today',
                'customer_name' => 'required|string|max:255',
                'customer_phone' => 'required|string|max:20',
                'customer_email' => 'required|email|max:255',
                'notes' => 'nullable|string',
            ]);

            // ✅ AUTO-DETECT: Ambil user_id jika user sedang login
            $user = auth('sanctum')->user();
            $validated['user_id'] = $user ? $user->id : null;

            // ✅ Log booking type untuk debugging
            Log::info('Booking type:', [
                'is_guest' => $user === null,
                'user_id' => $validated['user_id'],
                'customer_email' => $validated['customer_email']
            ]);

            DB::beginTransaction();

            // Create booking via service
            $booking = $this->bookingService->createBooking($validated);

            // Create Midtrans transaction
            $snapToken = $this->midtransService->createTransaction($booking);

            // Create payment record
            $this->midtransService->createPaymentRecord($booking, $snapToken);

            DB::commit();

            Log::info('=== BOOKING CREATED SUCCESSFULLY ===', [
                'booking_number' => $booking->booking_number,
                'user_id' => $booking->user_id,
                'is_guest' => $booking->user_id === null
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking berhasil dibuat',
                'data' => [
                    'booking' => $booking->load('field.venue'),
                    'snap_token' => $snapToken,
                ],
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            Log::error('Validation error:', $e->errors());
            return response()->json([
                'success' => false,
                'message' => 'Validasi gagal',
                'errors' => $e->errors(),
            ], 422);
        } catch (\Exception $e) {
            DB::rollBack();

            Log::error('=== BOOKING ERROR ===', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Handle Midtrans callback
     */
    public function handleCallback(Request $request)
    {
        try {
            Log::info('=== MIDTRANS CALLBACK RECEIVED ===', $request->all());

            $this->midtransService->handleCallback($request->all());

            return response()->json([
                'success' => true,
                'message' => 'Callback processed',
            ]);
        } catch (\Exception $e) {
            Log::error('Callback error', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Callback failed',
            ], 500);
        }
    }

    /**
     * Get booking status
     */
    public function getBookingStatus($bookingNumber)
    {
        try {
            $booking = Booking::with(['field.venue', 'payment'])
                ->where('booking_number', $bookingNumber)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $booking,
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Booking tidak ditemukan',
            ], 404);
        }
    }

    /**
     * Cancel booking
     */
    public function cancelBooking($bookingNumber)
    {
        try {
            $booking = $this->bookingService->cancelBooking($bookingNumber);

            Log::info('Booking cancelled', [
                'booking_number' => $bookingNumber,
                'cancelled_at' => now()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Booking berhasil dibatalkan',
                'data' => $booking,
            ]);
        } catch (\Exception $e) {
            Log::error('Cancel booking error', [
                'booking_number' => $bookingNumber,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Get all bookings with filters (Admin only)
     */
    public function getAllBookings(Request $request)
    {
        try {
            $bookings = $this->bookingService->getAllBookings($request);

            return response()->json([
                'success' => true,
                'data' => $bookings->items(),
                'meta' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                    'from' => $bookings->firstItem(),
                    'to' => $bookings->lastItem(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching bookings', ['error' => $e->getMessage()]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data booking'
            ], 500);
        }
    }

    /**
     * Get booking detail (Admin)
     */
    public function getBookingDetail($id)
    {
        try {
            $booking = Booking::with(['field.venue', 'payment', 'user'])
                ->findOrFail($id);

            return response()->json([
                'success' => true,
                'data' => $booking
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Booking tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Update booking status (Admin)
     */
    public function updateBookingStatus(Request $request, $id)
    {
        try {
            $validated = $request->validate([
                'status' => 'required|in:pending,confirmed,cancelled,completed',
                'notes' => 'nullable|string'
            ]);

            $booking = Booking::with('payment')->findOrFail($id);
            $oldStatus = $booking->status;

            $booking->status = $validated['status'];
            if (isset($validated['notes'])) {
                $booking->notes = ($booking->notes ? $booking->notes . "\n" : '') .
                    "[Admin Update] " . $validated['notes'];
            }
            $booking->save();

            // Update payment status if needed
            if ($validated['status'] === 'confirmed' && $booking->payment) {
                $booking->payment->payment_status = 'verified';
                $booking->payment->paid_at = $booking->payment->paid_at ?? now();
                $booking->payment->save();
            } elseif ($validated['status'] === 'cancelled' && $booking->payment) {
                $booking->payment->payment_status = 'rejected';
                $booking->payment->save();
            }

            Log::info('Booking status updated', [
                'booking_id' => $id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status booking berhasil diupdate',
                'data' => $booking->fresh(['field.venue', 'payment'])
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate status'
            ], 500);
        }
    }

    /**
     * Get dashboard statistics (Admin)
     */
    public function getDashboardStats(Request $request)
    {
        try {
            $stats = $this->bookingService->getDashboardStats($request);

            if ($stats === null || (is_array($stats) && $stats[0] === null)) {
                return response()->json([
                    'success' => false,
                    'message' => 'Gagal mengambil statistik: User tidak terautentikasi'
                ], 401);
            }

            return response()->json([
                'success' => true,
                'data' => $stats
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching dashboard stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik'
            ], 500);
        }
    }

    /**
     * ✅ TAMBAHAN: Get logged-in customer's bookings
     */
    public function getCustomerBookings(Request $request)
    {
        try {
            $user = $request->user();

            $bookings = Booking::with(['field.venue', 'payment'])
                ->where('user_id', $user->id)
                ->orderBy('created_at', 'desc')
                ->paginate(10);

            return response()->json([
                'success' => true,
                'data' => $bookings->items(),
                'meta' => [
                    'current_page' => $bookings->currentPage(),
                    'last_page' => $bookings->lastPage(),
                    'per_page' => $bookings->perPage(),
                    'total' => $bookings->total(),
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching customer bookings:', ['error' => $e->getMessage()]);
            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data booking'
            ], 500);
        }
    }

    /**
     * ✅ TAMBAHAN: Get customer booking detail
     */
    public function getCustomerBookingDetail(Request $request, $id)
    {
        try {
            $user = $request->user();

            $booking = Booking::with(['field.venue', 'payment'])
                ->where('user_id', $user->id)
                ->where('id', $id)
                ->firstOrFail();

            return response()->json([
                'success' => true,
                'data' => $booking
            ]);
        } catch (\Exception $e) {
            return response()->json([
                'success' => false,
                'message' => 'Booking tidak ditemukan'
            ], 404);
        }
    }
}
