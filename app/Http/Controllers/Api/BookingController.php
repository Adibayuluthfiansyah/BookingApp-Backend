<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Field;
use App\Models\TimeSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Auth;
use App\Notifications\BookingConfirmedNotification;
use Illuminate\Support\Facades\Notification;

class BookingController extends Controller
{
    /**
     * Create new booking
     */
    public function createBooking(Request $request)
    {
        try {
            Log::info('=== BOOKING REQUEST START ===');
            Log::info('Request data:', $request->all());

            // Validasi input
            $validated = $request->validate([
                'field_id' => 'required|exists:fields,id',
                'time_slot_id' => 'required|exists:time_slots,id',
                'booking_date' => 'required|date|after_or_equal:today',
                'customer_name' => 'required|string|max:255',
                'customer_phone' => 'required|string|max:20',
                'customer_email' => 'required|email|max:255',
                'notes' => 'nullable|string',
            ]);

            Log::info('Validation passed');

            // Get time slot
            $timeSlot = TimeSlot::findOrFail($validated['time_slot_id']);
            Log::info('Time slot found:', [
                'id' => $timeSlot->id,
                'price' => $timeSlot->price,
                'start_time' => $timeSlot->start_time,
                'end_time' => $timeSlot->end_time
            ]);

            // Check slot availability
            $existingBooking = Booking::where('field_id', $validated['field_id'])
                ->where('time_slot_id', $validated['time_slot_id'])
                ->where('booking_date', $validated['booking_date'])
                ->whereIn('status', ['pending', 'confirmed', 'completed'])
                ->exists();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slot sudah dibooking oleh orang lain',
                ], 422);
            }

            // Calculate price
            $subtotal = (float) $timeSlot->price;
            $adminFee = 5000;
            $totalAmount = $subtotal + $adminFee;

            Log::info('Price calculated:', [
                'subtotal' => $subtotal,
                'admin_fee' => $adminFee,
                'total' => $totalAmount
            ]);

            DB::beginTransaction();

            // Create booking
            $booking = Booking::create([
                'field_id' => $validated['field_id'],
                'time_slot_id' => $validated['time_slot_id'],
                'user_id' => Auth::check() ? Auth::id() : null,
                'booking_date' => $validated['booking_date'],
                'start_time' => $timeSlot->start_time,
                'end_time' => $timeSlot->end_time,
                'customer_name' => $validated['customer_name'],
                'customer_phone' => $validated['customer_phone'],
                'customer_email' => $validated['customer_email'],
                'notes' => $validated['notes'],
                'subtotal' => $subtotal,
                'admin_fee' => $adminFee,
                'total_amount' => $totalAmount,
                'status' => 'pending',
            ]);

            Log::info('Booking created:', [
                'id' => $booking->id,
                'booking_number' => $booking->booking_number
            ]);

            // Get field with venue
            $field = Field::with('venue')->findOrFail($validated['field_id']);

            // Prepare data for Midtrans
            $orderID = $booking->booking_number;
            $grossAmount = (int) $totalAmount;

            // Clean data
            $cleanPhone = $this->cleanPhoneNumber($validated['customer_phone']);
            $itemName = $this->sanitizeText($field->venue->name . ' - ' . $field->name, 50);
            $customerName = $this->sanitizeText($validated['customer_name'], 50);

            Log::info('Prepared data for Midtrans:', [
                'order_id' => $orderID,
                'amount' => $grossAmount,
                'item_name' => $itemName,
                'customer_name' => $customerName,
                'phone' => $cleanPhone,
                'email' => $validated['customer_email']
            ]);

            // Create Midtrans transaction using raw cURL
            $snapToken = $this->createMidtransTransaction([
                'order_id' => $orderID,
                'gross_amount' => $grossAmount,
                'item_name' => $itemName,
                'customer_name' => $customerName,
                'customer_email' => $validated['customer_email'],
                'customer_phone' => $cleanPhone,
                'item_details' => [
                    [
                        'id' => 'FIELD_' . $booking->id,
                        'price' => (int) $subtotal,
                        'quantity' => 1,
                        'name' => $itemName,
                    ],
                    [
                        'id' => 'ADMIN_' . $booking->id,
                        'price' => (int) $adminFee,
                        'quantity' => 1,
                        'name' => 'Biaya Admin',
                    ],
                ]
            ]);

            if (!$snapToken) {
                throw new \Exception('Gagal mendapatkan Snap Token dari Midtrans');
            }

            // Create payment record
            Payment::create([
                'booking_id' => $booking->id,
                'amount' => $totalAmount,
                'payment_method' => 'transfer_bank',
                'payment_status' => 'pending',
                'snap_token' => $snapToken,
            ]);

            DB::commit();

            Log::info('=== BOOKING CREATED SUCCESSFULLY ===');

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
                'file' => $e->getFile(),
                'line' => $e->getLine(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Create Midtrans transaction using raw cURL (bypass SDK bug)
     */
    private function createMidtransTransaction($data)
    {
        try {
            $serverKey = config('midtrans.server_key');
            $isProduction = config('midtrans.is_production', false);

            // Midtrans API endpoint
            $url = $isProduction
                ? 'https://app.midtrans.com/snap/v1/transactions'
                : 'https://app.sandbox.midtrans.com/snap/v1/transactions';

            // Prepare request body
            $body = [
                'transaction_details' => [
                    'order_id' => $data['order_id'],
                    'gross_amount' => $data['gross_amount'],
                ],
                'item_details' => $data['item_details'],
                'customer_details' => [
                    'first_name' => $data['customer_name'],
                    'email' => $data['customer_email'],
                    'phone' => $data['customer_phone'],
                ],
            ];

            $jsonBody = json_encode($body);

            Log::info('=== CALLING MIDTRANS API (cURL) ===');
            Log::info('URL:', ['url' => $url]);
            Log::info('Body:', $body);

            // Initialize cURL
            $ch = curl_init($url);

            // Set cURL options
            curl_setopt($ch, CURLOPT_POST, true);
            curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);
            curl_setopt($ch, CURLOPT_HTTPHEADER, [
                'Content-Type: application/json',
                'Accept: application/json',
                'Authorization: Basic ' . base64_encode($serverKey . ':')
            ]);

            // Execute request
            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlError = curl_error($ch);

            curl_close($ch);

            Log::info('Midtrans API Response:', [
                'http_code' => $httpCode,
                'response' => $response,
                'curl_error' => $curlError
            ]);

            // Check for cURL errors
            if ($curlError) {
                throw new \Exception('cURL Error: ' . $curlError);
            }

            // Check HTTP status
            if ($httpCode !== 201) {
                $errorData = json_decode($response, true);
                $errorMessage = $errorData['error_messages'][0] ?? 'Unknown error from Midtrans';
                throw new \Exception('Midtrans API Error (HTTP ' . $httpCode . '): ' . $errorMessage);
            }

            // Parse response
            $responseData = json_decode($response, true);

            if (!isset($responseData['token'])) {
                throw new \Exception('Snap token not found in Midtrans response');
            }

            Log::info('=== SNAP TOKEN RECEIVED ===', [
                'token_preview' => substr($responseData['token'], 0, 20) . '...'
            ]);

            return $responseData['token'];
        } catch (\Exception $e) {
            Log::error('=== MIDTRANS CURL ERROR ===', [
                'message' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            throw $e;
        }
    }

    /**
     * Clean phone number for Midtrans (format: 62xxx)
     */
    private function cleanPhoneNumber($phone)
    {
        // Remove all non-numeric characters
        $clean = preg_replace('/[^0-9]/', '', $phone);

        if (empty($clean)) {
            return '628000000000';
        }

        // Remove leading zeros
        $clean = ltrim($clean, '0');

        // Add country code if not present
        if (substr($clean, 0, 2) !== '62') {
            $clean = '62' . $clean;
        }

        // Validate length (10-15 digits)
        $length = strlen($clean);
        if ($length < 10 || $length > 15) {
            return '628000000000';
        }

        return $clean;
    }

    /**
     * Sanitize text for Midtrans (only alphanumeric, space, dash)
     */
    private function sanitizeText($text, $maxLength = 50)
    {
        // Remove special characters
        $clean = preg_replace('/[^a-zA-Z0-9 \-]/', '', $text);

        // Remove multiple spaces/dashes
        $clean = preg_replace('/\s+/', ' ', $clean);
        $clean = preg_replace('/\-+/', '-', $clean);

        // Trim
        $clean = trim($clean, ' -');

        // Limit length
        if (strlen($clean) > $maxLength) {
            $clean = substr($clean, 0, $maxLength - 3) . '...';
        }

        // Return default if empty
        return empty($clean) ? 'Booking Lapangan' : $clean;
    }

    /**
     * Handle Midtrans callback/notification
     */
    public function handleCallback(Request $request)
    {
        try {
            Log::info('=== MIDTRANS CALLBACK RECEIVED ===', $request->all());

            $orderID = $request->order_id;
            $statusCode = $request->status_code;
            $grossAmount = $request->gross_amount;
            $signatureKey = $request->signature_key;

            // Verify signature
            $serverKey = config('midtrans.server_key');
            $hash = hash('sha512', $orderID . $statusCode . $grossAmount . $serverKey);

            if ($hash !== $signatureKey) {
                Log::warning('Invalid signature', [
                    'order_id' => $orderID,
                    'expected' => $hash,
                    'received' => $signatureKey
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                ], 403);
            }

            // Get booking
            $booking = Booking::where('booking_number', $orderID)->firstOrFail();
            $payment = $booking->payment;

            $transactionStatus = $request->transaction_status;
            $fraudStatus = $request->fraud_status ?? null;

            Log::info('Processing callback', [
                'order_id' => $orderID,
                'transaction_status' => $transactionStatus,
                'fraud_status' => $fraudStatus
            ]);

            // Update status based on Midtrans response
            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'accept') {
                    $booking->status = 'confirmed';
                    $payment->payment_status = 'verified';
                    $payment->paid_at = now();

                    // Send email notification
                    $this->sendBookingConfirmation($booking);
                }
            } elseif ($transactionStatus == 'settlement') {
                $booking->status = 'confirmed';
                $payment->payment_status = 'verified';
                $payment->paid_at = now();

                // Send email notification
                $this->sendBookingConfirmation($booking);
            } elseif ($transactionStatus == 'pending') {
                $booking->status = 'pending';
                $payment->payment_status = 'pending';
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $booking->status = 'cancelled';
                $payment->payment_status = 'rejected';
            }

            $booking->save();
            $payment->save();

            Log::info('Callback processed successfully', [
                'booking_status' => $booking->status,
                'payment_status' => $payment->payment_status
            ]);

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
            $booking = Booking::with('payment')
                ->where('booking_number', $bookingNumber)
                ->firstOrFail();

            // Only allow cancellation for pending bookings
            if ($booking->status !== 'pending') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking tidak dapat dibatalkan. Status: ' . $booking->status,
                ], 422);
            }

            // Check if payment is still pending
            if ($booking->payment && $booking->payment->payment_status === 'verified') {
                return response()->json([
                    'success' => false,
                    'message' => 'Booking tidak dapat dibatalkan karena sudah dibayar',
                ], 422);
            }

            DB::beginTransaction();

            // Update booking status
            $booking->update(['status' => 'cancelled']);

            // Update payment status if exists
            if ($booking->payment) {
                $booking->payment->update(['payment_status' => 'rejected']);
            }

            DB::commit();

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
            DB::rollBack();

            Log::error('Cancel booking error', [
                'booking_number' => $bookingNumber,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membatalkan booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Send booking confirmation email
     */
    private function sendBookingConfirmation($booking)
    {
        try {
            // Send email to customer
            Notification::route('mail', $booking->customer_email)
                ->notify(new BookingConfirmedNotification($booking));

            Log::info('Booking confirmation email sent', [
                'booking_number' => $booking->booking_number,
                'email' => $booking->customer_email
            ]);
        } catch (\Exception $e) {
            // Log error but don't fail the request
            Log::error('Failed to send booking confirmation email', [
                'booking_number' => $booking->booking_number,
                'error' => $e->getMessage()
            ]);
        }
    }

    /**
     * Get all bookings with filters (Admin only)
     */
    public function getAllBookings(Request $request)
    {
        try {
            Log::info('Admin fetching all bookings', [
                'filters' => $request->all()
            ]);

            $query = Booking::with(['field.venue', 'payment', 'user']);

            // Filter by status
            if ($request->has('status') && $request->status !== 'all') {
                $query->where('status', $request->status);
            }

            // Filter by payment status
            if ($request->has('payment_status') && $request->payment_status !== 'all') {
                $query->whereHas('payment', function ($q) use ($request) {
                    $q->where('payment_status', $request->payment_status);
                });
            }

            // Filter by date range
            if ($request->has('start_date') && $request->start_date) {
                $query->where('booking_date', '>=', $request->start_date);
            }
            if ($request->has('end_date') && $request->end_date) {
                $query->where('booking_date', '<=', $request->end_date);
            }

            // Filter by venue
            if ($request->has('venue_id') && $request->venue_id) {
                $query->whereHas('field', function ($q) use ($request) {
                    $q->where('venue_id', $request->venue_id);
                });
            }

            // Search by booking number or customer name
            if ($request->has('search') && $request->search) {
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
            $bookings = $query->paginate($perPage);

            Log::info('Bookings fetched', [
                'total' => $bookings->total(),
                'current_page' => $bookings->currentPage()
            ]);

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
            Log::error('Error fetching all bookings', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil data booking: ' . $e->getMessage()
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

            Log::info('Admin viewing booking detail', [
                'booking_id' => $id,
                'admin_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'data' => $booking
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching booking detail', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Booking tidak ditemukan'
            ], 404);
        }
    }

    /**
     * Update booking status manually (Admin)
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

            Log::info('Booking status updated by admin', [
                'booking_id' => $id,
                'old_status' => $oldStatus,
                'new_status' => $validated['status'],
                'admin_id' => auth()->id()
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Status booking berhasil diupdate',
                'data' => $booking->fresh(['field.venue', 'payment'])
            ]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            return response()->json([
                'success' => false,
                'message' => 'Data tidak valid',
                'errors' => $e->errors()
            ], 422);
        } catch (\Exception $e) {
            Log::error('Error updating booking status', [
                'booking_id' => $id,
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengupdate status booking: ' . $e->getMessage()
            ], 500);
        }
    }

    /**
     * Get dashboard statistics (Admin)
     */
    public function getDashboardStats()
    {
        try {
            $today = now()->format('Y-m-d');
            $thisMonth = now()->format('Y-m');

            // Today's stats
            $todayBookings = Booking::whereDate('booking_date', $today)->count();
            $todayRevenue = Booking::whereDate('booking_date', $today)
                ->whereIn('status', ['confirmed', 'completed'])
                ->sum('total_amount');

            // This month stats
            $monthlyBookings = Booking::where('booking_date', 'like', "$thisMonth%")->count();
            $monthlyRevenue = Booking::where('booking_date', 'like', "$thisMonth%")
                ->whereIn('status', ['confirmed', 'completed'])
                ->sum('total_amount');

            // Overall stats
            $totalBookings = Booking::count();
            $totalRevenue = Booking::whereIn('status', ['confirmed', 'completed'])
                ->sum('total_amount');

            // Pending bookings
            $pendingBookings = Booking::where('status', 'pending')->count();

            // Recent bookings
            $recentBookings = Booking::with(['field.venue', 'payment'])
                ->orderBy('created_at', 'desc')
                ->limit(10)
                ->get();

            // Booking by status
            $bookingsByStatus = Booking::selectRaw('status, count(*) as count')
                ->groupBy('status')
                ->pluck('count', 'status');

            // Revenue by venue
            $revenueByVenue = Booking::selectRaw('venues.name as venue_name, sum(bookings.total_amount) as revenue')
                ->join('fields', 'bookings.field_id', '=', 'fields.id')
                ->join('venues', 'fields.venue_id', '=', 'venues.id')
                ->whereIn('bookings.status', ['confirmed', 'completed'])
                ->groupBy('venues.id', 'venues.name')
                ->orderByDesc('revenue')
                ->limit(5)
                ->get();

            return response()->json([
                'success' => true,
                'data' => [
                    'today' => [
                        'bookings' => $todayBookings,
                        'revenue' => $todayRevenue,
                    ],
                    'monthly' => [
                        'bookings' => $monthlyBookings,
                        'revenue' => $monthlyRevenue,
                    ],
                    'overall' => [
                        'total_bookings' => $totalBookings,
                        'total_revenue' => $totalRevenue,
                        'pending_bookings' => $pendingBookings,
                    ],
                    'recent_bookings' => $recentBookings,
                    'bookings_by_status' => $bookingsByStatus,
                    'revenue_by_venue' => $revenueByVenue,
                ]
            ]);
        } catch (\Exception $e) {
            Log::error('Error fetching dashboard stats', [
                'error' => $e->getMessage()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal mengambil statistik dashboard'
            ], 500);
        }
    }
}
