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
use Exception;

class BookingController extends Controller
{
    public function __construct()
    {
        // Set Midtrans configuration
        Config::$serverKey = config('midtrans.server_key');
        Config::$isProduction = config('midtrans.is_production');
        Config::$isSanitized = config('midtrans.is_sanitized');
        Config::$is3ds = config('midtrans.is_3ds');

        // PENTING: Hapus ini di production!
        // Hanya untuk development/testing
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

            // Get time slot
            $timeSlot = TimeSlot::find($validated['time_slot_id']);
            if (!$timeSlot) {
                return response()->json([
                    'success' => false,
                    'message' => 'Time slot tidak ditemukan',
                ], 404);
            }

            Log::info('Time slot found:', ['id' => $timeSlot->id, 'price' => $timeSlot->price]);

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

            // Clean phone number - hapus karakter non-numeric
            $cleanPhone = preg_replace('/[^0-9]/', '', $validated['customer_phone']);

            // Format phone: pastikan dimulai dengan +62 atau 62
            if (substr($cleanPhone, 0, 1) === '0') {
                $cleanPhone = '62' . substr($cleanPhone, 1);
            } elseif (substr($cleanPhone, 0, 2) !== '62') {
                $cleanPhone = '62' . $cleanPhone;
            }

            // Sanitize strings untuk Midtrans
            $venueName = $this->sanitizeString($field->venue->name, 50);
            $fieldName = $this->sanitizeString($field->name, 30);
            $customerName = $this->sanitizeString($validated['customer_name'], 50);

            $params = [
                'transaction_details' => [
                    'order_id' => $orderID,
                    'gross_amount' => $grossAmount,
                ],
                'item_details' => [
                    [
                        'id' => 'BOOK' . $booking->id,
                        'price' => (int) $subtotal,
                        'quantity' => 1,
                        'name' => trim($venueName . ' - ' . $fieldName),
                    ],
                    [
                        'id' => 'ADMIN',
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

            // Get Snap Token dengan error handling yang lebih baik
            try {
                Log::info('Calling Midtrans Snap API...');
                Log::info('Server Key: ' . substr(Config::$serverKey, 0, 20) . '...');
                Log::info('Is Production: ' . (Config::$isProduction ? 'true' : 'false'));

                $snapToken = Snap::getSnapToken($params);

                Log::info('Snap token received successfully');
                Log::info('Token preview: ' . substr($snapToken, 0, 30) . '...');
            } catch (\Midtrans\Exceptions\ApiException $e) {
                DB::rollBack();

                Log::error('Midtrans API Exception:', [
                    'message' => $e->getMessage(),
                    'http_code' => $e->getHttpCode(),
                    'response_body' => $e->getResponseBody(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Gagal terhubung ke Midtrans: ' . $e->getMessage(),
                    'details' => config('app.debug') ? [
                        'http_code' => $e->getHttpCode(),
                        'response' => $e->getResponseBody()
                    ] : null
                ], 500);
            } catch (\Exception $e) {
                DB::rollBack();

                Log::error('Midtrans General Error:', [
                    'message' => $e->getMessage(),
                    'file' => $e->getFile(),
                    'line' => $e->getLine(),
                    'trace' => $e->getTraceAsString()
                ]);

                return response()->json([
                    'success' => false,
                    'message' => 'Terjadi kesalahan saat membuat pembayaran: ' . $e->getMessage(),
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
                'trace' => $e->getTraceAsString()
            ]);

            return response()->json([
                'success' => false,
                'message' => 'Gagal membuat booking: ' . $e->getMessage(),
            ], 500);
        }
    }

    /**
     * Sanitize string untuk Midtrans
     * Hapus karakter special, hanya izinkan alphanumeric dan spasi
     */
    private function sanitizeString($string, $maxLength = 50)
    {
        // Hapus karakter special, hanya izinkan huruf, angka, dan spasi
        $clean = preg_replace('/[^a-zA-Z0-9 ]/', '', $string);

        // Trim dan batasi panjang
        $clean = trim($clean);
        $clean = substr($clean, 0, $maxLength);

        // Jika kosong setelah sanitize, berikan default
        return !empty($clean) ? $clean : 'Item';
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
                'trace' => $e->getTraceAsString()
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
