<?php

namespace App\Http\Controllers\Api;

use Illuminate\Support\Facades\Auth;
use App\Http\Controllers\Controller;
use App\Models\Booking;
use App\Models\Payment;
use App\Models\Field;
use App\Models\TimeSlot;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Midtrans\Config;
use Midtrans\Snap;


class BookingController extends Controller
{
    public function __construct()
    {
        Log::info('Midtrans Config Loaded', [
            'server_key' => config('midtrans.server_key'),
            'is_production' => config('midtrans.is_production'),
        ]);

        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$is3ds = config('midtrans.is_3ds');
        Config::$isSanitized = true;

        Log::info('Midtrans Server Key: ' . Config::$serverKey);

        Config::$curlOptions = [
            CURLOPT_SSL_VERIFYHOST => 0,
            CURLOPT_SSL_VERIFYPEER => 0,
        ];
    }

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

            // Get time slot - DENGAN BETTER ERROR HANDLING
            try {
                $timeSlot = TimeSlot::findOrFail($validated['time_slot_id']);

                Log::info('Time slot found:', [
                    'id' => $timeSlot->id,
                    'price' => $timeSlot->price,
                    'start_time' => $timeSlot->start_time,
                    'end_time' => $timeSlot->end_time
                ]);
            } catch (\Illuminate\Database\Eloquent\ModelNotFoundException $e) {
                Log::error('Time slot not found in database:', [
                    'requested_id' => $validated['time_slot_id'],
                    'error' => $e->getMessage(),
                    'available_time_slots' => DB::table('time_slots')->pluck('id')->toArray()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Time slot dengan ID ' . $validated['time_slot_id'] . ' tidak ditemukan di database. Silakan pilih slot yang tersedia.',
                ], 404);
            }

            // Check if slot is available
            $existingBooking = Booking::where('field_id', $validated['field_id'])
                ->where('time_slot_id', $validated['time_slot_id'])
                ->where('booking_date', $validated['booking_date'])
                ->whereIn('status', ['pending', 'confirmed', 'paid'])
                ->exists();

            if ($existingBooking) {
                return response()->json([
                    'success' => false,
                    'message' => 'Slot sudah dibooking',
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

            Log::info('Booking created:', ['id' => $booking->id, 'number' => $booking->booking_number]);

            // Get field with venue
            $field = Field::with('venue')->find($validated['field_id']);

            if (!$field || !$field->venue) {
                DB::rollBack();
                throw new \Exception('Field atau venue tidak ditemukan');
            }

            Log::info('Field and venue found:', [
                'field_id' => $field->id,
                'field_name' => $field->name,
                'venue_name' => $field->venue->name
            ]);

            // Prepare Midtrans params
            $orderID = $booking->booking_number;
            $grossAmount = (int) $totalAmount;

            // Clean and format phone number - IMPROVED
            $cleanPhone = $this->cleanPhoneNumber($validated['customer_phone']);

            // Sanitize strings untuk Midtrans - IMPROVED
            $itemName = $this->sanitizeItemName($field->venue->name, $field->name);
            $customerName = $this->sanitizeCustomerName($validated['customer_name']);

            Log::info('Sanitized data:', [
                'item_name' => $itemName,
                'customer_name' => $customerName,
                'phone' => $cleanPhone
            ]);

            $params = [
                'transaction_details' => [
                    'order_id' => $orderID,
                    'gross_amount' => $grossAmount,
                ],
                'item_details' => [
                    [
                        'id' => 'FIELD_BOOKING_' . $booking->id,
                        'price' => (int) $subtotal,
                        'quantity' => 1,
                        'name' => $itemName,
                    ],
                    [
                        'id' => 'ADMIN_FEE_' . $booking->id,
                        'price' => (int) $adminFee,
                        'quantity' => 1,
                        'name' => 'Biaya Admin',
                    ],
                ],
                'customer_details' => [
                    'first_name' => $customerName,
                    'email' => $validated['customer_email'],
                    'phone' => $cleanPhone,
                ],
            ];

            Log::info('Midtrans params prepared:', $params);

            // Get Snap Token dengan error handling
            try {
                Log::info('Calling Midtrans Snap API...');

                $snapToken = Snap::getSnapToken($params);

                Log::info('Snap token received successfully');
            } catch (\Exception $e) {
                DB::rollBack();

                Log::error('Midtrans Snap Error:', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal membuat pembayaran: ' . $e->getMessage(),
                ], 500);
            }

            // Create payment record
            Payment::create([
                'booking_id' => $booking->id,
                'amount' => $totalAmount,
                'payment_method' => 'midtrans',
                'status' => 'pending',
                'snap_token' => $snapToken,
            ]);

            DB::commit();

            Log::info('=== BOOKING CREATED SUCCESSFULLY ===');

            return response()->json([
                'success' => true,
                'message' => 'Booking berhasil dibuat',
                'data' => [
                    'booking' => $booking->load('field.venue', 'timeSlot'),
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
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Clean dan format phone number untuk Midtrans
     */
    private function cleanPhoneNumber($phone)
    {
        // Hapus semua karakter non-numeric
        $clean = preg_replace('/[^0-9]/', '', $phone);

        // Jika kosong setelah cleaning, return default
        if (empty($clean)) {
            return '628000000000';
        }

        // Hilangkan leading zeros
        $clean = ltrim($clean, '0');

        // Tambahkan country code jika belum ada
        if (substr($clean, 0, 2) !== '62') {
            $clean = '62' . $clean;
        }

        // Validasi panjang (min 10 digit setelah 62, max 15)
        $phoneLength = strlen($clean);
        if ($phoneLength < 10 || $phoneLength > 15) {
            // Return default jika tidak valid
            return '628000000000';
        }

        return $clean;
    }

    /**
     * Sanitize item name untuk Midtrans (venue + field)
     * Max 50 karakter, hanya alphanumeric dan spasi
     */
    private function sanitizeItemName($venueName, $fieldName)
    {
        // Gabungkan venue dan field
        $combined = $venueName . ' - ' . $fieldName;

        // Hapus karakter special, hanya izinkan huruf, angka, spasi, dan dash
        $clean = preg_replace('/[^a-zA-Z0-9 \-]/', '', $combined);

        // Hapus multiple spaces
        $clean = preg_replace('/\s+/', ' ', $clean);

        // Trim
        $clean = trim($clean);

        // Batasi panjang max 50 karakter
        if (strlen($clean) > 50) {
            $clean = substr($clean, 0, 47) . '...';
        }

        // Jika kosong setelah sanitize, berikan default
        if (empty($clean)) {
            return 'Booking Lapangan';
        }

        return $clean;
    }

    /**
     * Sanitize customer name untuk Midtrans
     * Max 50 karakter, hanya alphanumeric dan spasi
     */
    private function sanitizeCustomerName($name)
    {
        // Hapus karakter special, hanya izinkan huruf, angka, dan spasi
        $clean = preg_replace('/[^a-zA-Z0-9 ]/', '', $name);

        // Hapus multiple spaces
        $clean = preg_replace('/\s+/', ' ', $clean);

        // Trim
        $clean = trim($clean);

        // Batasi panjang max 50 karakter
        if (strlen($clean) > 50) {
            $clean = substr($clean, 0, 50);
        }

        // Jika kosong setelah sanitize, berikan default
        if (empty($clean)) {
            return 'Customer';
        }

        return $clean;
    }

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
                Log::warning('Invalid signature from Midtrans', [
                    'order_id' => $orderID,
                    'expected' => $hash,
                    'received' => $signatureKey
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Invalid signature',
                ], 403);
            }

            $booking = Booking::where('booking_number', $orderID)->firstOrFail();
            $payment = $booking->payment;

            $transactionStatus = $request->transaction_status;
            $fraudStatus = $request->fraud_status ?? null;

            Log::info('Processing callback', [
                'order_id' => $orderID,
                'transaction_status' => $transactionStatus,
                'fraud_status' => $fraudStatus
            ]);

            // Update status berdasarkan response Midtrans
            if ($transactionStatus == 'capture') {
                if ($fraudStatus == 'accept') {
                    $booking->status = 'paid';
                    $payment->status = 'paid';
                }
            } elseif ($transactionStatus == 'settlement') {
                $booking->status = 'paid';
                $payment->status = 'paid';
            } elseif ($transactionStatus == 'pending') {
                $booking->status = 'pending';
                $payment->status = 'pending';
            } elseif (in_array($transactionStatus, ['deny', 'expire', 'cancel'])) {
                $booking->status = 'cancelled';
                $payment->status = 'failed';
            }

            $booking->save();
            $payment->save();

            Log::info('Callback processed successfully', [
                'booking_status' => $booking->status,
                'payment_status' => $payment->status
            ]);

            return response()->json([
                'success' => true,
                'message' => 'Callback processed',
            ]);
        } catch (\Exception $e) {
            Log::error('Midtrans callback error', [
                'error' => $e->getMessage(),
                'request' => $request->all(),
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Callback processing failed',
            ], 500);
        }
    }

    public function getBookingStatus($bookingNumber)
    {
        try {
            $booking = Booking::with(['field.venue', 'timeSlot', 'payment'])
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
}
