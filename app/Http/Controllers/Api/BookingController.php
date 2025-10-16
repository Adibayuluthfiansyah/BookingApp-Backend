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
                }
            } elseif ($transactionStatus == 'settlement') {
                $booking->status = 'confirmed';
                $payment->payment_status = 'verified';
                $payment->paid_at = now();
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
}
